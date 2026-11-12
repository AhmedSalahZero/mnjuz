import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { router } from '@inertiajs/vue3';

let echoInstance = null;

/** خطّاف التنقّل يُسجَّل مرّة مع أوّل اتصال ويبقى (انظر syncEchoWithPage). */
let navigateHookBound = false;

/**
 * بصمة الإعداد الذي بُني به الاتصال. الأدمن يختار لكل منشأة Pusher أو Reverb،
 * فالانتقال إلى منشأة على مزوّدٍ آخر (أو تبديل الأدمن) يأتي بإعدادٍ مختلف مع
 * الصفحة التالية — والاتصال القديم يظلّ يستمع إلى مزوّدٍ لا يُبثّ إليه.
 */
let echoConfigKey = null;

const configKeyOf = (broadcast) => JSON.stringify([
    broadcast?.provider ?? null,
    broadcast?.key ?? null,
    broadcast?.host ?? null,
    broadcast?.port ?? null,
    broadcast?.cluster ?? null,
    broadcast?.force_tls ?? null,
]);

const AUTH_ENDPOINT = '/broadcasting/auth';

/**
 * ترويسات طلب المصادقة على القناة — تُقرأ مع كل طلب، لا مرّة عند بناء الاتصال.
 *
 * كل تسجيل دخول يُدوّر توكن CSRF (Session::regenerate)، والدخول يمرّ عبر
 * Inertia فلا تُعاد الصفحة ويبقى <meta csrf-token> على التوكن القديم. التوكن
 * المجمّد عند البناء كان يجعل /broadcasting/auth يردّ 419، فيفشل الاشتراك ولا
 * تصل الرسائل لحظياً حتى إعادة التحميل. كوكي XSRF-TOKEN يجدّدها Laravel مع كل
 * ردّ، وهي ما يقرؤه axios في طلبات الصفحة.
 *
 * ترويسة توكن واحدة لا اثنتان: Laravel يقرأ X-CSRF-TOKEN قبل X-XSRF-TOKEN،
 * فإرسال القديمة بجانب الكوكي يُبقي الرفض. و X-Requested-With يجعل الجلسة
 * المنتهية 401 صريحة بدل صفحة الدخول التي يقرؤها pusher-js «JSON غير صالح».
 */
function currentAuthHeaders() {
    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
    const cookie = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    if (cookie && cookie[1]) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie[1]);
    } else {
        const meta = document.querySelector('meta[name="csrf-token"]')?.content;
        if (meta) {
            headers['X-CSRF-TOKEN'] = meta;
        }
    }

    return headers;
}

/** قنوات مشتركة: اسم القناة → { channel, handlers } — اشتراك فيزيائي واحد = طلب auth واحد */
const sharedChannelEntries = new Map();

/** قنوات حالة الموظفين: اسم القناة → { channel, handlers } */
const presenceChannelEntries = new Map();

/** قطع الاتصال ونسيان قنواته كلّها. الاشتراك التالي يبني اتصالاً جديداً. */
export function disconnectEcho() {
    if (!echoInstance) {
        return;
    }

    try {
        echoInstance.disconnect();
    } catch (error) {
        console.warn('[Echo] تعذّر إغلاق الاتصال السابق', error);
    }
    echoInstance = null;
    echoConfigKey = null;
    sharedChannelEntries.clear();
    presenceChannelEntries.clear();
}

/**
 * الاتصال يعيش ما دامت الصفحة لوحة مستخدمٍ في منشأة — الشرط نفسه الذي يشترك
 * به Layout/App.vue.
 *
 * الخروج رابط Inertia لا يُعيد تحميل الصفحة، والاتصال محفوظ في هذا الموديول.
 * فكان التبويب بعد الخروج يبقى مشتركاً في قناة المستخدم ويستقبل محادثاته، ومن
 * يدخل بعده في التبويب نفسه يستقبل محادثات السابق. فصفحةٌ بلا مستخدم أو بلا
 * منشأة تقطعه: الدخول بعد خروج أو انتهاء جلسة أو خروج قسري، واختيار المنشأة،
 * وصفحات الأدمن. هنا القطع وحده؛ الاشتراك يبقى على الـlayout فلا سباق مع تركيبه.
 */
export function syncEchoWithPage(props) {
    if (!props?.auth?.user?.id || !props?.organization?.id) {
        disconnectEcho();
    }
}

/**
 * يُسجَّل مع أوّل اتصال لا عند تحميل التطبيق: استيراد هذا الموديول في app.js
 * كان سيحمّل Echo و Pusher في صفحات الضيوف كلها، ومنها صفحة الدخول.
 */
function bindNavigateHook() {
    if (navigateHookBound) {
        return;
    }
    navigateHookBound = true;
    router.on('navigate', (event) => syncEchoWithPage(event.detail.page.props));
}

/**
 * اتصال البثّ. يقبل إعداد المزوّد كما يبنيه الخادم بدل مفتاح وتجميعة.
 *
 * السائق يبقى pusher مع المزوّدَين: Reverb يتكلّم بروتوكول Pusher. الفارق أن
 * السحابة تشتقّ عنوانها من التجميعة، وReverb يحتاج عنواناً صريحاً — فتمرير
 * التجميعة وحدها كان يجعل التبديل إليه مستحيلاً من الواجهة.
 *
 * @param {{key: string, cluster?: ?string, host?: ?string, port?: number, force_tls?: boolean}} broadcast
 */
export function getEchoInstance(broadcast) {
    // إعدادٌ مختلف: يُغلق الاتصال القديم وتُنسى قنواته، فيُبنى اتصالٌ بالمزوّد
    // الجديد وتشترك الصفحة من جديد عند تركيبها.
    if (echoInstance && broadcast && configKeyOf(broadcast) !== echoConfigKey) {
        disconnectEcho();
    }

    if (!echoInstance) {
        // مفتاح غائب = اتصال يُبنى ويفشل بلا خطأ ظاهر: تُحفظ الرسائل ولا تصل
        // لحظياً فيبدو النظام بطيئاً لا معطّلاً. حدث هذا فعلاً حين مُرِّر
        // المفتاح نصّاً بدل كائن الإعداد، فصار الغياب يُعلَن.
        if (!broadcast?.key) {
            console.error(
                '[Echo] إعداد البثّ ناقص — لن تصل الرسائل لحظياً.',
                'المتوقَّع كائن {key, host?, port?, cluster?}، والوارد:',
                broadcast,
            );
        }

        window.Pusher = Pusher;

        const options = {
            broadcaster: 'pusher',
            authEndpoint: AUTH_ENDPOINT,
            // وجود channelAuthorization يجعل pusher-js يتجاهل auth.headers
            // الثابتة (ومنها توكن meta الذي يضعه Echo بنفسه)، ويستدعي
            // headersProvider مع كل طلب مصادقة.
            channelAuthorization: {
                endpoint: AUTH_ENDPOINT,
                transport: 'ajax',
                headersProvider: currentAuthHeaders,
            },
            key: broadcast?.key,
            cluster: broadcast?.cluster ?? 'mt1',
            encrypted: true,
        };

        // عنوان صريح = خادمنا. غيابه يعني السحابة، فنترك المكتبة تشتقّ عنوانها
        // من التجميعة كما كانت — تمرير عنوان مُخترَع هناك يكسر الاتصال.
        if (broadcast?.host) {
            const port = broadcast.port ?? 443;
            const forceTLS = broadcast.force_tls !== false;

            options.wsHost = broadcast.host;
            options.wsPort = port;
            options.wssPort = port;
            options.forceTLS = forceTLS;
            options.enabledTransports = ['ws', 'wss'];
        }

        echoInstance = new Echo(options);
        echoConfigKey = configKeyOf(broadcast);
        bindNavigateHook();
    }
    return echoInstance;
}

/**
 * اشتراك مشترك في قناة المحادثات: طلب auth واحد، ومعالجات متعددة.
 * @param {number} organizationId
 * @param {number} userId
 * @param {{key: string, cluster?: ?string, host?: ?string, port?: number, force_tls?: boolean}} broadcast
 * @returns {{ subscribe: (handler: (event: any) => void) => () => void }}
 */
export function getOrJoinChatChannel(organizationId, userId, broadcast) {
    const name = `chats.ch${organizationId}.${userId}`;
    if (sharedChannelEntries.has(name)) {
        return channelApi(sharedChannelEntries.get(name));
    }

    const echo = getEchoInstance(broadcast);

    // قناة هويةٍ سابقة في التبويب نفسه (تبديل المنشأة، أو دخول مستخدم آخر):
    // تُترك قبل الانضمام، وإلا ظلّت محادثاتها تصل ورُفضت بـ403 عند عودة الاتصال.
    // في التبويب قناة محادثات واحدة: الـlayout وصفحة المحادثات بالاسم نفسه.
    for (const staleName of [...sharedChannelEntries.keys()]) {
        echo.leave(staleName);
        sharedChannelEntries.delete(staleName);
    }

    const channel = echo
        .join(name)
        .here(() => {})
        .joining(() => {})
        .leaving(() => {})
        // قناة حضور: الاشتراك يمرّ بـ/broadcasting/auth. ابتلاع خطئه كان يخفي
        // الفشل تماماً — لا رسائل لحظية ولا سبب ظاهر، فيبدو النظام بطيئاً.
        .error((error) => {
            console.error('[Echo] فشل الاشتراك في ' + name, error);
        });

    echo.connector?.pusher?.connection?.bind('error', (error) => {
        console.error('[Echo] خطأ اتصال البثّ', error);
    });

    const handlers = new Set();
    const contactHandlers = new Set();

    // معالج يرمي لا يمنع بقية المعالجات ولا يقطع الاستماع.
    const fanOut = (set, label) => (event) => {
        set.forEach((h) => {
            try {
                h(event);
            } catch (e) {
                console.warn(label, e);
            }
        });
    };

    channel.listen('NewChatEvent', fanOut(handlers, '[Echo NewChatEvent handler]'));
    // حالة جهة الاتصال تتغيّر من الخادم (مساعد الذكاء الاصطناعي) دون رسالة جديدة.
    channel.listen('ContactAiAssistanceUpdated', fanOut(contactHandlers, '[Echo ContactAiAssistanceUpdated handler]'));

    const entry = { channel, handlers, contactHandlers };
    sharedChannelEntries.set(name, entry);

    return channelApi(entry);
}

/**
 * واجهة الاشتراك على قناة مشتركة: كل دالة تُرجع دالة إلغاء الاشتراك.
 * subscribe: رسائل المحادثات. subscribeContactUpdates: تحديثات جهة الاتصال.
 */
function channelApi(entry) {
    return {
        subscribe(handler) {
            entry.handlers.add(handler);
            return () => entry.handlers.delete(handler);
        },
        subscribeContactUpdates(handler) {
            entry.contactHandlers.add(handler);
            return () => entry.contactHandlers.delete(handler);
        },
    };
}

/**
 * حالة اتصال موظفي المنظمة لحظياً — لصفحة الأداء (المالك والمدير).
 *
 * الحدث AgentPresenceChanged يُطلَق عند تغيّر الحالة وحده. ما يفوت الصفحة وهي
 * غير مشتركة (قبل اكتمال الاشتراك، أو أثناء انقطاع الاتصال) تستدركه بـ onSync:
 * يُستدعى عند نجاح الاشتراك وكلّما عاد الاتصال، فتسأل الصفحة نقطة الحالة.
 *
 * @param {number} organizationId
 * @param {{key: string, cluster?: ?string, host?: ?string, port?: number, force_tls?: boolean}} broadcast
 * @param {(event: {user_id: number, online: boolean, last_activity_at: ?string, at: number}) => void} handler
 * @param {() => void} [onSync]
 * @returns {() => void} إلغاء الاشتراك
 */
export function subscribeAgentPresence(organizationId, broadcast, handler, onSync) {
    const name = `agents.presence.ch${organizationId}`;
    const echo = getEchoInstance(broadcast);

    let entry = presenceChannelEntries.get(name);
    if (!entry) {
        const handlers = new Set();
        const syncs = new Set();
        const channel = echo
            .private(name)
            .subscribed(() => syncs.forEach((sync) => sync()))
            .error((error) => {
                console.error('[Echo] فشل الاشتراك في ' + name, error);
            });

        channel.listen('.AgentPresenceChanged', (event) => {
            handlers.forEach((h) => {
                try {
                    h(event);
                } catch (e) {
                    console.warn('[Echo AgentPresenceChanged handler]', e);
                }
            });
        });

        entry = { channel, handlers, syncs };
        presenceChannelEntries.set(name, entry);
    }

    entry.handlers.add(handler);
    if (onSync) entry.syncs.add(onSync);

    const connection = echo.connector?.pusher?.connection;
    const onConnected = () => onSync?.();
    connection?.bind('connected', onConnected);

    return () => {
        entry.handlers.delete(handler);
        if (onSync) entry.syncs.delete(onSync);
        connection?.unbind('connected', onConnected);

        if (entry.handlers.size === 0 && presenceChannelEntries.get(name) === entry) {
            presenceChannelEntries.delete(name);
            try {
                echo.leave(name);
            } catch (e) {
                // اتصال أُغلق أصلاً.
            }
        }
    };
}
