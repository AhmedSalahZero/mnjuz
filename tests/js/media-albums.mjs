/**
 * ضمّ الصور المرسَلة دفعةً واحدة في فقاعة واحدة.
 *
 * السحب والإفلات يرسل كل صورة رسالةً مستقلّة — واجهة واتساب السحابية لا
 * تعرف الألبوم — فكانت عشر صور عشر فقاعات متراصّة. الضمّ عرضٌ فقط، فالقواعد
 * هنا هي كل ما يفصل «دفعة واحدة» عن «صور متفرّقة».
 */
import {
    albumCaption,
    albumStatus,
    albumTiles,
    captionOf,
    groupMediaAlbums,
    tileType,
    ALBUM_GAP_SECONDS,
} from '../../resources/js/Composables/mediaAlbums.js'

let checks = 0
const fail = []
const is = (actual, expected, msg) => {
    checks++
    if (actual !== expected) fail.push(`${msg} — توقّعنا "${expected}" ووجدنا "${actual}"`)
}
const ok = (cond, msg) => {
    checks++
    if (!cond) fail.push(msg)
}

let nextId = 1
const image = (at, direction = 'outbound', extra = {}) => ([{
    type: 'chat',
    value: {
        id: nextId++,
        type: direction,
        created_at: at,
        metadata: JSON.stringify({ type: 'image', image: { caption: extra.caption ?? null } }),
        media: extra.media === undefined ? { path: 'https://cdn.test/a.png', type: 'image/png' } : extra.media,
        deleted_at: extra.deleted_at ?? null,
        status: extra.status ?? 'delivered',
        logs: extra.logs ?? [],
        user: extra.user ?? null,
    },
}])

const video = (at, direction = 'outbound', extra = {}) => ([{
    type: 'chat',
    value: {
        id: nextId++,
        type: direction,
        created_at: at,
        metadata: JSON.stringify({ type: 'video', video: { caption: extra.caption ?? null } }),
        media: { path: 'https://cdn.test/a.mp4', type: 'video/mp4' },
        deleted_at: null,
        status: 'delivered',
        logs: [],
    },
}])

const document_ = (at, direction = 'outbound') => ([{
    type: 'chat',
    value: {
        id: nextId++,
        type: direction,
        created_at: at,
        metadata: JSON.stringify({ type: 'document', document: { filename: 'a.pdf' } }),
        media: { path: 'https://cdn.test/a.pdf', type: 'application/pdf' },
        deleted_at: null,
        status: 'delivered',
        logs: [],
    },
}])

const text = (at, direction = 'outbound') => ([{
    type: 'chat',
    value: {
        id: nextId++,
        type: direction,
        created_at: at,
        metadata: JSON.stringify({ type: 'text', text: { body: 'مرحباً' } }),
        media: null,
        deleted_at: null,
        logs: [],
    },
}])

const ticket = (at) => ([{ type: 'ticket', value: { id: nextId++, description: 'Conversation opened', created_at: at } }])

// ------------------------------------------------ الحالة الأساسية

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        image('2026-09-03 10:00:03'),
        image('2026-09-03 10:00:05'),
    ])

    is(items.length, 1, 'ثلاث صور دفعةً واحدة ⇒ عنصر واحد')
    is(items[0].kind, 'album', 'العنصر ألبوم')
    is(items[0].messages.length, 3, 'الألبوم يحمل الصور الثلاث')
    is(items[0].direction, 'outbound', 'اتجاه الألبوم')
    ok(items[0].key.startsWith('album-'), 'مفتاح ثابت للألبوم')
}

// صورة واحدة تبقى فقاعة عادية
{
    const items = groupMediaAlbums([image('2026-09-03 10:00:00')])
    is(items.length, 1, 'صورة واحدة')
    is(items[0].kind, 'single', 'الصورة الواحدة ليست ألبوماً')
}

// ------------------------------------------------ ما يقطع الدفعة

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        text('2026-09-03 10:00:02'),
        image('2026-09-03 10:00:04'),
    ])
    is(items.length, 3, 'نصّ بين صورتين يقطع الضمّ')
    is(items.every((i) => i.kind === 'single'), true, 'الثلاثة مفردة')
}

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00', 'outbound'),
        image('2026-09-03 10:00:02', 'inbound'),
    ])
    is(items.length, 2, 'اتجاهان مختلفان لا يُضمّان')
}

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        image('2026-09-03 10:05:00'),
    ])
    is(items.length, 2, `فجوة أكبر من ${ALBUM_GAP_SECONDS} ثانية تقطع الضمّ`)
}

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        image('2026-09-03 10:00:59'),
    ])
    is(items[0].kind, 'album', 'فجوة داخل الحدّ تُبقي الضمّ')
}

// دفعة طويلة: العبرة بالفجوة بين كل صورتين لا بمدى الدفعة كلّها
{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        image('2026-09-03 10:00:50'),
        image('2026-09-03 10:01:40'),
    ])
    is(items.length, 1, 'رفعٌ بطيء يبقى دفعة واحدة')
    is(items[0].messages.length, 3, 'كل الصور في الألبوم')
}

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        image('2026-09-03 10:00:02', 'outbound', { deleted_at: '2026-09-03 10:01:00' }),
        image('2026-09-03 10:00:04'),
    ])
    is(items.length, 3, 'الرسالة المحذوفة تقطع ولا تُضمّ')
}

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        ticket('2026-09-03 10:00:02'),
        image('2026-09-03 10:00:04'),
    ])
    is(items.length, 3, 'سطر التذكرة يقطع الضمّ')
    is(items[1].chat[0].type, 'ticket', 'التذكرة تبقى كما هي')
}

// تاريخ غير مفهوم لا يُضمّ — الضمّ الخاطئ يُخفي رسالة
{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        image('غير صالح'),
    ])
    is(items.length, 2, 'تاريخ تالف لا يُضمّ')
}

// ------------------------------------------------ مدخلات فاسدة

is(groupMediaAlbums(null).length, 0, 'null')
is(groupMediaAlbums([]).length, 0, 'قائمة فارغة')
{
    const items = groupMediaAlbums([[{ type: 'chat', value: { id: 1, type: 'outbound', metadata: 'ليس JSON' } }]])
    is(items.length, 1, 'metadata تالفة تبقى مفردة')
    is(items[0].kind, 'single', 'ولا تُعدّ صورة')
}

// ------------------------------------------------ صورة بلا ملف (رفعٌ لم يكتمل)

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00', 'outbound', { media: null }),
        image('2026-09-03 10:00:02'),
    ])
    is(items[0].kind, 'album', 'الصورة قيد الرفع تبقى ضمن دفعتها')
}

// ------------------------------------------------ التعليق

is(albumCaption([
    { metadata: JSON.stringify({ type: 'image', image: { caption: null } }) },
    { metadata: JSON.stringify({ type: 'image', image: { caption: 'الفاتورة' } }) },
]), 'الفاتورة', 'أوّل تعليق موجود هو تعليق الألبوم')

is(albumCaption([{ metadata: JSON.stringify({ type: 'image', image: { caption: 'null' } }) }]), '',
   'السلسلة "null" ليست تعليقاً')
is(albumCaption([]), '', 'ألبوم بلا تعليق')
is(captionOf('  '), '', 'تعليق فارغ')
is(captionOf('نصّ'), 'نصّ', 'تعليق صالح')

// ------------------------------------------------ الحالة المجمّعة

is(albumStatus([{ status: 'read' }, { status: 'delivered' }]), 'delivered',
   'الأدنى يحكم: ألبوم فيه غير مقروءة ليس مقروءاً')
is(albumStatus([{ status: 'read' }, { status: 'read' }]), 'read', 'الكلّ مقروء')
is(albumStatus([{ status: 'read' }, { status: 'sent' }]), 'sent', 'الأدنى مُرسَل')
is(albumStatus([
    { status: 'read' },
    { status: 'delivered', logs: [{ metadata: JSON.stringify({ status: 'failed' }) }] },
]), 'failed', 'الفشل يعلو الكلّ')
is(albumStatus([]), 'sent', 'ألبوم فارغ')

// السجلّات ترفع الحالة كما في الفقاعة المفردة
is(albumStatus([{ status: 'sent', logs: [{ metadata: JSON.stringify({ status: 'delivered' }) }] }]), 'delivered',
   'السجلّ يرفع الحالة')

// ------------------------------------------------ البلاطات

{
    const six = Array.from({ length: 6 }, (_, i) => ({ id: i }))
    const { tiles, hidden } = albumTiles(six)
    is(tiles.length, 4, 'أربع بلاطات ظاهرة')
    is(hidden, 2, 'والباقي +2')
}
{
    const { tiles, hidden } = albumTiles([{ id: 1 }, { id: 2 }])
    is(tiles.length, 2, 'صورتان بلاطتان')
    is(hidden, 0, 'بلا زائد')
}
is(albumTiles(null).tiles.length, 0, 'بلاطات من قائمة غائبة')

// ------------------------------------------------ الفيديو داخل الألبوم

// واتساب يضمّ الصور والفيديو معاً؛ المستند لا يدخل ألبوماً أبداً.
{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        video('2026-09-03 10:00:03'),
        image('2026-09-03 10:00:05'),
    ])
    is(items.length, 1, 'الفيديو يُضمّ مع الصور')
    is(items[0].messages.length, 3, 'الثلاثة في ألبوم واحد')
}

{
    const items = groupMediaAlbums([
        video('2026-09-03 10:00:00'),
        video('2026-09-03 10:00:04'),
    ])
    is(items[0].kind, 'album', 'فيديوهان يصنعان ألبوماً')
}

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        document_('2026-09-03 10:00:03'),
        image('2026-09-03 10:00:06'),
    ])
    is(items.length, 3, 'المستند يقطع الضمّ ولا يدخله')
    is(items.every((i) => i.kind === 'single'), true, 'الثلاثة مفردة')
}

{
    const items = groupMediaAlbums([
        image('2026-09-03 10:00:00'),
        image('2026-09-03 10:00:02'),
        document_('2026-09-03 10:00:04'),
        image('2026-09-03 10:00:06'),
        image('2026-09-03 10:00:08'),
    ])
    is(items.length, 3, 'ألبوم + مستند + ألبوم')
    is(items[0].kind, 'album', 'الأول ألبوم')
    is(items[1].kind, 'single', 'المستند مفرد')
    is(items[2].kind, 'album', 'الأخير ألبوم')
}

is(tileType({ metadata: JSON.stringify({ type: 'video' }) }), 'video', 'نوع بلاطة الفيديو')
is(tileType({ metadata: JSON.stringify({ type: 'image' }) }), 'image', 'نوع بلاطة الصورة')
is(tileType({ metadata: 'تالف' }), 'image', 'الافتراضي صورة عند التلف')

is(albumCaption([{ metadata: JSON.stringify({ type: 'video', video: { caption: 'شاهد' } }) }]), 'شاهد',
   'تعليق الفيديو يُقرأ كتعليق الصورة')

if (fail.length) {
    console.error(fail.join('\n'))
    process.exit(1)
}

console.log(`OK — ${checks} فحصاً`)
