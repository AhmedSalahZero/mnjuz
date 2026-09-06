<script setup>
import { computed, ref } from 'vue'
import ImageLightbox from './ImageLightbox.vue'
import { senderName } from '@/Composables/senderName'
import { albumCaption, albumStatus, albumTiles } from '@/Composables/imageAlbums'

const props = defineProps({
	/** رسائل الصور المضمومة، بترتيب إرسالها. */
	messages: { type: Array, required: true },
	/** اتجاه المجموعة: inbound أو outbound. */
	type: { type: String, required: true },
})

const lightboxOpen = ref(false)
const lightboxSrc = ref('')

const openLightbox = (src) => {
	if (!src) return
	lightboxSrc.value = src
	lightboxOpen.value = true
}

const brokenTiles = ref(new Set())

const markBroken = (id) => {
	const next = new Set(brokenTiles.value)
	next.add(id)
	brokenTiles.value = next
}

const tileSource = (message) => {
	if (brokenTiles.value.has(message.id)) return ''
	return message?.media?.path || ''
}

const grid = computed(() => albumTiles(props.messages))
const caption = computed(() => albumCaption(props.messages))
const status = computed(() => albumStatus(props.messages))

/** آخر رسالة هي صاحبة الوقت المعروض — الألبوم يُقرأ ككتلة واحدة. */
const last = computed(() => props.messages[props.messages.length - 1] ?? {})

/**
 * عمودان دائماً عدا الصورتين: تخطيط واتساب يجعلهما جنباً إلى جنب بارتفاع
 * أكبر، وهو أوضح من مربّعين صغيرين في زاوية.
 */
const columns = computed(() => (props.messages.length === 2 ? 'grid-cols-2' : 'grid-cols-2'))
</script>

<template>
	<div
		class="rounded-lg my-1 p-2 text-sm flex flex-col relative max-w-[340px]"
		:class="type === 'outbound'
			? 'ml-auto rounded-tr-none bg-[#d8fad4] speech-bubble-right'
			: 'mr-auto rounded-tl-none bg-white speech-bubble-left'"
	>
		<div class="grid gap-1" :class="columns">
			<div
				v-for="(message, index) in grid.tiles"
				:key="message.id ?? index"
				class="relative overflow-hidden rounded-md bg-slate-200"
			>
				<img
					v-if="tileSource(message)"
					:src="tileSource(message)"
					alt="Image"
					class="h-[150px] w-full cursor-pointer object-cover"
					@click="openLightbox(tileSource(message))"
					@error="markBroken(message.id)"
				/>
				<div v-else class="flex h-[150px] w-full items-center justify-center px-2 text-center text-xs text-slate-500">
					{{ $t('Content not available') }}
				</div>

				<!-- الصور الزائدة عن أربع: تُفتح آخر بلاطة على الصورة نفسها -->
				<button
					v-if="index === grid.tiles.length - 1 && grid.hidden > 0"
					type="button"
					class="absolute inset-0 flex items-center justify-center bg-black/55 text-xl font-medium text-white"
					@click="openLightbox(tileSource(message))"
				>
					+{{ grid.hidden }}
				</button>
			</div>
		</div>

		<div v-if="caption" class="mt-2 whitespace-pre-wrap break-words">{{ caption }}</div>

		<div v-if="type === 'outbound' && senderName(last.user)" class="mt-2 mb--2">
			<span class="text-gray-500 text-xs leading-none">
				{{ $t('Sent By') }}: {{ senderName(last.user) }}
			</span>
		</div>

		<div class="flex items-center justify-between space-x-4" :class="type === 'outbound' && last.user ? '' : 'mt-2'">
			<p class="text-gray-500 text-xs leading-none">
				{{ $t('{count} photos', { count: messages.length }) }}
			</p>
			<div class="flex items-center space-x-1">
				<p class="text-gray-500 text-xs leading-none">{{ last.created_at }}</p>
				<span v-if="type === 'outbound'">
					<svg v-if="status === 'sent'" xmlns="http://www.w3.org/2000/svg" width="16" height="16"
						viewBox="0 0 24 24" class="text-gray-500">
						<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
							stroke-width="2" d="m5 12l5 5L20 7" />
					</svg>
					<svg v-else-if="status === 'delivered' || status === 'read'" xmlns="http://www.w3.org/2000/svg"
						width="16" height="16" viewBox="0 0 24 24"
						:class="status === 'read' ? 'text-[#00a5f4]' : 'text-gray-500'">
						<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
							stroke-width="2" d="m1.75 12.25l2.5 2.5m3.5-4l2.5-2.5m-4.5 4l2.5 2.5l10-10.5" />
					</svg>
					<svg v-else-if="status === 'failed'" xmlns="http://www.w3.org/2000/svg" width="16" height="16"
						viewBox="0 0 24 24" class="text-red-600">
						<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"
							d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
					</svg>
				</span>
			</div>
		</div>

		<ImageLightbox :isOpen="lightboxOpen" :src="lightboxSrc" @close="lightboxOpen = false" />
	</div>
</template>
