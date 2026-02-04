import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echoInstance = null;

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
