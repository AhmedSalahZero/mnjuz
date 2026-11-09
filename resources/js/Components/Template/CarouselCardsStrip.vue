<script setup>
/**
 * صفّ بطاقات الـ carousel كما يراه العميل في واتساب: تمرير أفقي، لكل بطاقة
 * وسائطها ونصّها وأزرارها.
 *
 * عرضٌ فقط، ويُستعمل في ثلاثة مواضع بنفس الشكل: معاينة صفحة إنشاء القالب،
 * ومعاينة نموذج الإرسال، وفقاعة المحادثة بعد الإرسال.
 *
 * cards: [{ media: string|null, body: string|null, buttons: [{ type, text }] }]
 */
defineProps({
    format: { type: String, default: 'IMAGE' },
    cards: { type: Array, default: () => [] },
    compact: { type: Boolean, default: false },
})
</script>

<template>
    <div
        class="flex gap-2 overflow-x-auto snap-x snap-mandatory pb-2 mt-1"
        dir="ltr">
        <div
            v-for="(card, index) in cards"
            :key="index"
            class="snap-start shrink-0 bg-white rounded-lg overflow-hidden flex flex-col border border-gray-100 shadow-sm"
            :class="compact ? 'w-[11rem]' : 'w-[13.5rem]'">
            <div
                class="bg-[#ccd0d5] flex items-center justify-center overflow-hidden"
                :class="compact ? 'h-24' : 'h-28'">
                <template v-if="card.media">
                    <video
                        v-if="String(format).toUpperCase() === 'VIDEO'"
                        :key="card.media"
                        :src="card.media"
                        class="w-full h-full object-cover"
                        preload="metadata"
                        muted
                        playsinline></video>
                    <img
                        v-else
                        :src="card.media"
                        class="w-full h-full object-cover"
                        loading="lazy"
                        alt="" />
                </template>
                <svg
                    v-else
                    xmlns="http://www.w3.org/2000/svg"
                    width="32"
                    height="32"
                    viewBox="0 0 24 24"
                    class="text-white">
                    <path
                        v-if="String(format).toUpperCase() === 'VIDEO'"
                        fill="currentColor"
                        d="M8 5.14v14l11-7l-11-7Z" />
                    <path
                        v-else
                        fill="currentColor"
                        d="M21 19V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2M8.5 13.5l2.5 3l3.5-4.5l4.5 6H5z" />
                </svg>
            </div>

            <p
                v-if="card.body"
                class="px-2 pt-1.5 text-[13px] text-gray-800 whitespace-pre-wrap break-words normal-case"
                dir="auto">
                {{ card.body }}
            </p>

            <div class="mt-auto pt-1">
                <div
                    v-for="(button, buttonIndex) in card.buttons"
                    :key="buttonIndex"
                    class="flex justify-center items-center gap-x-1.5 border-t border-gray-100 h-9 text-[13px] text-[#00a5f4]"
                    dir="auto">
                    <svg
                        v-if="button.type === 'PHONE_NUMBER'"
                        xmlns="http://www.w3.org/2000/svg"
                        width="15"
                        height="15"
                        viewBox="0 0 24 24">
                        <path
                            fill="currentColor"
                            d="M20 16v4c-2.758 0-5.07-.495-7-1.325c-3.841-1.652-6.176-4.63-7.5-7.675C4.4 8.472 4 5.898 4 4h4l1 4l-3.5 3c1.324 3.045 3.659 6.023 7.5 7.675L16 15l4 1z" />
                    </svg>
                    <img
                        v-else-if="button.type === 'URL'"
                        :src="'/images/icons/link.png'"
                        class="h-3.5"
                        alt="" />
                    <img
                        v-else
                        :src="'/images/icons/reply.png'"
                        class="h-3.5"
                        alt="" />
                    <span class="truncate">{{ button.text }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
