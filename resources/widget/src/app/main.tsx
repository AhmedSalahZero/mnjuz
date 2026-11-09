import { render } from 'preact'
import css from '../widget.css?inline'
import { Widget, type MountContext } from './Widget.tsx'

/**
 * يُحمَّل عند أول ضغطة على الزرّ (أو حين ينتظر الزائرَ ردٌّ لم يقرأه)،
 * ويحلّ محلّ زرّ الـ loader داخل نفس الـ shadow root. التنسيق يُحقن هناك
 * لا في الصفحة: لا يتسرّب إلى موقع التاجر، ولا يتسرّب تنسيقه إلينا.
 */
export function mount(ctx: MountContext & { root: ShadowRoot }): void {
    const { root, ...context } = ctx

    while (root.firstChild) {
        root.removeChild(root.firstChild)
    }

    const style = document.createElement('style')
    style.textContent = css
    root.appendChild(style)

    const container = document.createElement('div')
    root.appendChild(container)

    render(<Widget ctx={context} />, container)
}
