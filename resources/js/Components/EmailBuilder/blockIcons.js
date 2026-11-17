/** أيقونة كل عنصر في «العناصر» و«ترتيب البريد». */
import { Heading, Image, Link, Minus, MoveVertical, Newspaper, PanelBottom, PanelTop, Pilcrow, Share2 } from 'lucide-vue-next'

export const BLOCK_ICONS = {
    header: PanelTop,
    heading: Heading,
    text: Pilcrow,
    image: Image,
    button: Link,
    image_text: Newspaper,
    divider: Minus,
    spacer: MoveVertical,
    social: Share2,
    footer: PanelBottom,
}
