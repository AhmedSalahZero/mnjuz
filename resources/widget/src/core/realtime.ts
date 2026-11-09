import Pusher from 'pusher-js'
import type { RealtimeConfig } from './types.ts'

/**
 * قناة الزائر الخاصّة على مزوّد منشأته (Pusher أو Reverb).
 *
 * pusher-js مباشرةً بلا laravel-echo: قناة واحدة وحدث واحد، وEcho يضاعف
 * الحجم بلا فائدة هنا. منطق Reverb هو منطق resources/js/echo.js نفسه:
 * المضيف والمنفذ يُحدَّدان، والنقل ws/wss وحده.
 */
export type RealtimeState = 'connecting' | 'connected' | 'unavailable' | 'disconnected'

export interface RealtimeHandle {
    disconnect(): void
}

export interface WidgetEvent {
    kind: 'message' | 'refresh'
    message?: unknown
    seq?: number | null
}

export function connectRealtime(
    config: RealtimeConfig,
    channelName: string,
    authEndpoint: string,
    token: string,
    onEvent: (event: WidgetEvent) => void,
    onState: (state: RealtimeState) => void,
): RealtimeHandle | null {
    if (!config?.key || !channelName) {
        return null
    }

    const options: Record<string, unknown> = {
        cluster: config.cluster || 'mt1',
        channelAuthorization: {
            endpoint: authEndpoint,
            transport: 'ajax',
            headers: { 'X-Webchat-Token': token, Accept: 'application/json' },
        },
    }

    if (config.host) {
        const port = config.port || 443
        options.wsHost = config.host
        options.wsPort = port
        options.wssPort = port
        options.forceTLS = config.force_tls !== false
        options.enabledTransports = ['ws', 'wss']
    }

    let client: Pusher

    try {
        client = new Pusher(config.key, options as any)
    } catch {
        return null
    }

    client.connection.bind('state_change', (states: { current: string }) => {
        const current = states.current
        onState(current === 'connected' ? 'connected' : current === 'connecting' ? 'connecting' : current === 'unavailable' || current === 'failed' ? 'unavailable' : 'disconnected')
    })

    const channel = client.subscribe(channelName)
    channel.bind('webchat.event', (payload: WidgetEvent) => onEvent(payload))
    channel.bind('pusher:subscription_error', () => onState('unavailable'))

    return {
        disconnect() {
            try {
                client.unsubscribe(channelName)
                client.disconnect()
            } catch {
                // لا شيء.
            }
        },
    }
}
