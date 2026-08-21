import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echoInstance = null;

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
        const entry = sharedChannelEntries.get(name);
        return {
            subscribe(handler) {
                entry.handlers.add(handler);
                return () => entry.handlers.delete(handler);
            },
        };
    }

    const echo = getEchoInstance(broadcast);
    const channel = echo
        .join(name)
        .here(() => {})
        .joining(() => {})
        .leaving(() => {})
        .error(() => {});

    const handlers = new Set();
    channel.listen('NewChatEvent', (event) => {
        handlers.forEach((h) => {
            try {
                h(event);
            } catch (e) {
                console.warn('[Echo NewChatEvent handler]', e);
            }
        });
    });

    sharedChannelEntries.set(name, { channel, handlers });

    return {
        subscribe(handler) {
            handlers.add(handler);
            return () => handlers.delete(handler);
        },
    };
}
