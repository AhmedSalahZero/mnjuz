import type { Interactive, WidgetMessage } from './types.ts'

/**
 * فقاعة محلّية — أسئلة البداية والأسئلة الشائعة. معرّفها «l:…»: لا يعرفها
 * الخادم، والضغط على أزرارها يبقى في المتصفّح.
 */
export function localBubble(
    id: string,
    direction: 'in' | 'out',
    text: string | null,
    interactive: Interactive | null = null,
    image: string | null = null,
    /** وصف الصورة لقارئ الشاشة (alt). */
    imageAlt: string | null = null,
): WidgetMessage {
    return {
        id,
        seq: null,
        client_id: null,
        direction,
        type: image ? 'image' : 'text',
        text,
        media: image ? { url: image, name: imageAlt, mime: 'image/*', size: null } : null,
        interactive,
        status: 'sent',
        created_at: null,
        sender: { kind: direction === 'out' ? 'bot' : 'visitor' },
    }
}

/** حتى ثلاثة خيارات أزرارٌ (كواتساب)، وما زاد قائمةٌ يفتحها زرّ باسمها. */
export function choices(rows: { id: string; title: string }[], listLabel: string): Interactive {
    return rows.length <= 3 ? { kind: 'buttons', buttons: rows } : { kind: 'list', button: listLabel, sections: [{ title: '', rows }] }
}
