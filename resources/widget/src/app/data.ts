/**
 * الرموز التعبيرية: أصلية من خطّ الجهاز، بلا صور ولا مكتبة. أيقونة كل
 * مجموعة (Font Awesome) في Composer، لا رمزاً تعبيرياً.
 */
export type EmojiGroupId = 'smileys' | 'gestures' | 'hearts' | 'objects' | 'food'

export const EMOJI_GROUPS: { id: EmojiGroupId; items: string[] }[] = [
    {
        id: 'smileys',
        items: '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😗 😙 😚 😋 😛 😝 😜 🤪 🤨 🧐 🤓 😎 🤩 🥳 😏 😒 😞 😔 😟 😕 🙁 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤯 😳 🥵 🥶 😱 😨 😰 😥 😓 🤗 🤔 🤭 🤫 🤥 😶 😐 😑 😬 🙄 😯 😦 😧 😮 😲 🥱 😴 🤤 😪 😵 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕'.split(' '),
    },
    {
        id: 'gestures',
        items: '👍 👎 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💪 🫶 👀 🧠 🫡'.split(' '),
    },
    {
        id: 'hearts',
        items: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 ✨ ⭐ 🌟 💫 🔥 💯 ✅ ❌ ❗ ❓ ⚠️ 🎉 🎊 🎁'.split(' '),
    },
    {
        id: 'objects',
        items: '📦 🛒 🛍️ 💳 💰 💵 🧾 📱 💻 ⌚ 📷 🎧 📞 ✉️ 📧 📝 📌 📍 🗓️ ⏰ ⏳ 🔑 🔒 🚚 🚗 ✈️ 🏠 🏪 🏢 🎫 🏷️ 🔖'.split(' '),
    },
    {
        id: 'food',
        items: '☕ 🍵 🥤 🧃 🍰 🎂 🍫 🍪 🍩 🍦 🍕 🍔 🍟 🌮 🥗 🍗 🍖 🍚 🍛 🥙 🍞 🥐 🍎 🍊 🍋 🍉 🍇 🍓 🥭 🍌 🌴 🌹 🌸 🌙 ☀️ 🌧️'.split(' '),
    },
]

/** دول المنطقة أولاً — رمز الدولة ومفتاح الاتصال. */
export const COUNTRIES: { code: string; dial: string; name: { ar: string; en: string } }[] = [
    { code: 'SA', dial: '966', name: { ar: 'السعودية', en: 'Saudi Arabia' } },
    { code: 'AE', dial: '971', name: { ar: 'الإمارات', en: 'UAE' } },
    { code: 'KW', dial: '965', name: { ar: 'الكويت', en: 'Kuwait' } },
    { code: 'QA', dial: '974', name: { ar: 'قطر', en: 'Qatar' } },
    { code: 'BH', dial: '973', name: { ar: 'البحرين', en: 'Bahrain' } },
    { code: 'OM', dial: '968', name: { ar: 'عُمان', en: 'Oman' } },
    { code: 'EG', dial: '20', name: { ar: 'مصر', en: 'Egypt' } },
    { code: 'JO', dial: '962', name: { ar: 'الأردن', en: 'Jordan' } },
    { code: 'IQ', dial: '964', name: { ar: 'العراق', en: 'Iraq' } },
    { code: 'LB', dial: '961', name: { ar: 'لبنان', en: 'Lebanon' } },
    { code: 'SY', dial: '963', name: { ar: 'سوريا', en: 'Syria' } },
    { code: 'PS', dial: '970', name: { ar: 'فلسطين', en: 'Palestine' } },
    { code: 'YE', dial: '967', name: { ar: 'اليمن', en: 'Yemen' } },
    { code: 'SD', dial: '249', name: { ar: 'السودان', en: 'Sudan' } },
    { code: 'LY', dial: '218', name: { ar: 'ليبيا', en: 'Libya' } },
    { code: 'TN', dial: '216', name: { ar: 'تونس', en: 'Tunisia' } },
    { code: 'DZ', dial: '213', name: { ar: 'الجزائر', en: 'Algeria' } },
    { code: 'MA', dial: '212', name: { ar: 'المغرب', en: 'Morocco' } },
    { code: 'TR', dial: '90', name: { ar: 'تركيا', en: 'Turkey' } },
    { code: 'PK', dial: '92', name: { ar: 'باكستان', en: 'Pakistan' } },
    { code: 'IN', dial: '91', name: { ar: 'الهند', en: 'India' } },
    { code: 'GB', dial: '44', name: { ar: 'المملكة المتحدة', en: 'United Kingdom' } },
    { code: 'US', dial: '1', name: { ar: 'الولايات المتحدة', en: 'United States' } },
]

export const RECENT_EMOJI_LIMIT = 24
