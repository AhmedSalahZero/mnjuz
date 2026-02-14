import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echoInstance = null;

/** قنوات مشتركة: اسم القناة → { channel, handlers } — اشتراك فيزيائي واحد = طلب auth واحد */
const sharedChannelEntries = new Map();

export function getEchoInstance(pusherKey, pusherCluster) {
    if (!echoInstance) {
        window.Pusher = Pusher;
        echoInstance = new Echo({
            broadcaster: 'pusher',
			authEndpoint: '/broadcasting/auth',
			auth: {
				headers: {
					'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
				}
			},
            key: pusherKey,
            cluster: pusherCluster,
            encrypted: true,
        });
    }
    return echoInstance;
}

/**
 * اشتراك مشترك في قناة المحادثات: طلب auth واحد، ومعالجات متعددة.
 * @param {number} organizationId
 * @param {string} pusherKey
 * @param {string} pusherCluster
 * @returns {{ subscribe: (handler: (event: any) => void) => () => void }}
 */
export function getOrJoinChatChannel(organizationId, pusherKey, pusherCluster) {
    const name = `chats.ch${organizationId}`;

    if (sharedChannelEntries.has(name)) {
        const entry = sharedChannelEntries.get(name);
        return {
            subscribe(handler) {
                entry.handlers.add(handler);
                return () => entry.handlers.delete(handler);
            },
        };
    }

    const echo = getEchoInstance(pusherKey, pusherCluster);
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
