import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echoInstance = null;

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

/** قنوات مشتركة: اسم القناة → { channel, handlers } — اشتراك فيزيائي واحد = طلب auth واحد */
const sharedChannelEntries = new Map();

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
        try {
            echoInstance.disconnect();
        } catch (error) {
            console.warn('[Echo] تعذّر إغلاق الاتصال السابق', error);
        }
        echoInstance = null;
        sharedChannelEntries.clear();
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
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
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
