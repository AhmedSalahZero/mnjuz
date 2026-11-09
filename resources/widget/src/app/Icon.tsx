import type { IconDefinition } from '@fortawesome/free-solid-svg-icons'

/**
 * أيقونة Font Awesome مرسومة SVG داخل الصفحة.
 *
 * لا خطّ ولا CSS من CDN: الـ widget داخل shadow root، و@font-face فيه لا
 * يُحمَّل، والخطّ وحده نحو 100KB وطلبٌ لطرف ثالث في موقع كل تاجر. نستورد
 * الأيقونات بأسمائها فلا يدخل البناءَ غيرُها.
 */
export function Icon({ icon, class: className }: { icon: IconDefinition; class?: string }) {
    const [width, height, , , path] = icon.icon

    return (
        <svg
            class={`mnjz-svg${className ? ' ' + className : ''}`}
            viewBox={`0 0 ${width} ${height}`}
            fill="currentColor"
            aria-hidden="true"
            focusable="false"
        >
            {(Array.isArray(path) ? path : [path]).map((d) => (
                <path d={d} key={d} />
            ))}
        </svg>
    )
}
