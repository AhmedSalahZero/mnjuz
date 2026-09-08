<script setup>
import { TransitionChild, TransitionRoot, Dialog, DialogPanel } from '@headlessui/vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { stepIndex } from '@/Composables/albumGallery'

const props = defineProps({
	isOpen: { type: Boolean, default: false },
	src: { type: String, default: '' },
	alt: { type: String, default: 'Image' },
	/**
	 * مجموعة يُتنقّل بينها: [{src, type}]. عند غيابها يُعرض `src` وحده،
	 * فتبقى المواضع القديمة تعمل كما هي.
	 */
	items: { type: Array, default: () => [] },
	/** موضع البداية داخل المجموعة. */
	index: { type: Number, default: 0 },
})

const emit = defineEmits(['close'])

const zoom = ref(1)
const MIN_ZOOM = 1
const MAX_ZOOM = 4

/** المجموعة الفعلية: ما مُرّر، أو `src` وحده مجموعةً من عنصر. */
const list = computed(() => {
	if (props.items.length > 0) {
		return props.items
	}

	return props.src ? [{ src: props.src, type: 'image' }] : []
})

const cursor = ref(0)
const current = computed(() => list.value[cursor.value] ?? null)
const hasMany = computed(() => list.value.length > 1)

function go(delta) {
	cursor.value = stepIndex(cursor.value, list.value.length, delta)
	zoom.value = 1
}

function onKey(event) {
	if (!props.isOpen || !hasMany.value) return

	if (event.key === 'ArrowRight') go(1)
	if (event.key === 'ArrowLeft') go(-1)
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

function close() {
	emit('close')
}

function zoomIn() {
	zoom.value = Math.min(MAX_ZOOM, +(zoom.value + 0.25).toFixed(2))
}

function zoomOut() {
	zoom.value = Math.max(MIN_ZOOM, +(zoom.value - 0.25).toFixed(2))
}

function toggleZoom() {
	zoom.value = zoom.value > 1 ? 1 : 2
}

function download() {
	const src = current.value?.src
	if (!src) return
	const a = document.createElement('a')
	a.href = src
	a.download = ''
	a.target = '_blank'
	a.rel = 'noopener'
	document.body.appendChild(a)
	a.click()
	document.body.removeChild(a)
}

// إعادة ضبط التكبير والموضع عند كل فتح
watch(() => props.isOpen, (open) => {
	if (!open) return
	zoom.value = 1
	cursor.value = stepIndex(props.index, list.value.length, 0)
})
</script>

<template>
	<TransitionRoot appear :show="props.isOpen" as="template">
		<Dialog as="div" class="relative z-50" @close="close">
			<TransitionChild as="template" enter="duration-200 ease-out" enter-from="opacity-0" enter-to="opacity-100"
				leave="duration-150 ease-in" leave-from="opacity-100" leave-to="opacity-0">
				<div class="fixed inset-0 bg-black/80" />
			</TransitionChild>

			<div class="fixed inset-0 overflow-hidden">
				<TransitionChild as="template" enter="duration-200 ease-out" enter-from="opacity-0 scale-95"
					enter-to="opacity-100 scale-100" leave="duration-150 ease-in" leave-from="opacity-100 scale-100"
					leave-to="opacity-0 scale-95">
					<DialogPanel class="flex h-full w-full flex-col">
						<!-- شريط الأدوات -->
						<div class="flex items-center justify-between gap-2 p-3 text-white">
							<span v-if="hasMany" class="text-sm tabular-nums">{{ cursor + 1 }} / {{ list.length }}</span>
							<span v-else></span>
							<div class="flex items-center gap-2">
							<button type="button" @click="zoomOut" :title="$t('Zoom out')"
								class="rounded-full bg-white/10 p-2 hover:bg-white/20 transition-colors">
								<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M19 13H5v-2h14z"/></svg>
							</button>
							<button type="button" @click="zoomIn" :title="$t('Zoom in')"
								class="rounded-full bg-white/10 p-2 hover:bg-white/20 transition-colors">
								<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z"/></svg>
							</button>
							<button type="button" @click="download" :title="$t('Download')"
								class="rounded-full bg-white/10 p-2 hover:bg-white/20 transition-colors">
								<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M5 20h14v-2H5zM19 9h-4V3H9v6H5l7 7z"/></svg>
							</button>
							<button type="button" @click="close" :title="$t('Close')"
								class="rounded-full bg-white/10 p-2 hover:bg-white/20 transition-colors">
								<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M18.3 5.71L12 12l6.3 6.29l-1.41 1.42L10.59 13.4L4.3 19.7l-1.42-1.41L9.17 12L2.88 5.71L4.3 4.29l6.29 6.3l6.3-6.3z"/></svg>
							</button>
							</div>
						</div>

						<!-- منطقة الصورة -->
						<div class="relative flex flex-1 items-center justify-center overflow-auto p-4" @click.self="close">
							<!-- سهم السابق: يظهر فقط حين تكون المجموعة أكثر من واحد -->
							<button v-if="hasMany" type="button" @click.stop="go(-1)" :title="$t('Previous')"
								class="absolute left-3 z-10 rounded-full bg-white/10 p-3 text-white hover:bg-white/20 transition-colors">
								<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M15.41 7.41L14 6l-6 6l6 6l1.41-1.41L10.83 12z"/></svg>
							</button>

							<video v-if="current && current.type === 'video'" :src="current.src" controls autoplay
								class="max-h-full max-w-full select-none object-contain" />
							<img v-else-if="current" :src="current.src" :alt="props.alt" @click="toggleZoom"
								class="max-h-full max-w-full select-none object-contain transition-transform duration-200"
								:style="{ transform: `scale(${zoom})`, cursor: zoom > 1 ? 'zoom-out' : 'zoom-in' }" />

							<button v-if="hasMany" type="button" @click.stop="go(1)" :title="$t('Next')"
								class="absolute right-3 z-10 rounded-full bg-white/10 p-3 text-white hover:bg-white/20 transition-colors">
								<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M8.59 16.59L10 18l6-6l-6-6l-1.41 1.41L13.17 12z"/></svg>
							</button>
						</div>
					</DialogPanel>
				</TransitionChild>
			</div>
		</Dialog>
	</TransitionRoot>
</template>
