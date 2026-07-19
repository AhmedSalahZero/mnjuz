<script setup>
import { TransitionChild, TransitionRoot, Dialog, DialogPanel } from '@headlessui/vue'
import { ref, watch } from 'vue'

const props = defineProps({
	isOpen: { type: Boolean, default: false },
	src: { type: String, default: '' },
	alt: { type: String, default: 'Image' },
})

const emit = defineEmits(['close'])

const zoom = ref(1)
const MIN_ZOOM = 1
const MAX_ZOOM = 4

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
	if (!props.src) return
	const a = document.createElement('a')
	a.href = props.src
	a.download = ''
	a.target = '_blank'
	a.rel = 'noopener'
	document.body.appendChild(a)
	a.click()
	document.body.removeChild(a)
}

// إعادة ضبط التكبير عند كل فتح
watch(() => props.isOpen, (open) => {
	if (open) zoom.value = 1
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
						<div class="flex items-center justify-end gap-2 p-3 text-white">
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

						<!-- منطقة الصورة -->
						<div class="flex flex-1 items-center justify-center overflow-auto p-4" @click.self="close">
							<img v-if="props.src" :src="props.src" :alt="props.alt" @click="toggleZoom"
								class="max-h-full max-w-full select-none object-contain transition-transform duration-200"
								:style="{ transform: `scale(${zoom})`, cursor: zoom > 1 ? 'zoom-out' : 'zoom-in' }" />
						</div>
					</DialogPanel>
				</TransitionChild>
			</div>
		</Dialog>
	</TransitionRoot>
</template>
