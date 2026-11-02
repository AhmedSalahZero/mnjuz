<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useTrans } from '@/Composables/useTrans'
import { lastInboundOf, windowRemaining } from '@/Composables/messagingWindow'

const props = defineProps({
	contact: { type: Object, default: null },
})

const trans = useTrans()

// نصف قطر الحلقة ومحيطها: الجزء المتبقّي من الوقت هو الجزء الظاهر من القوس.
const RADIUS = 15
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

const now = ref(Date.now())
let timer = null

onMounted(() => {
	now.value = Date.now()
	timer = setInterval(() => {
		now.value = Date.now()
	}, 30000)
})

onBeforeUnmount(() => {
	if (timer) clearInterval(timer)
})

// computed على الـ prop نفسه: تبديل المحادثة أو وصول رسالة واردة جديدة
// (Index.vue يحدّث الحقل) يعيدان الحساب بلا أي ربط إضافي.
const remaining = computed(() => windowRemaining(lastInboundOf(props.contact), now.value))

const pad = (n) => String(n).padStart(2, '0')

const shortLabel = computed(() =>
	remaining.value.open
		? trans('{hours}h {minutes}m left', { hours: remaining.value.hours, minutes: remaining.value.minutes })
		: trans('24-hour window closed'),
)

const fullLabel = computed(() =>
	remaining.value.open
		? trans('{hours} hours and {minutes} minutes left before the 24-hour reply window closes', {
			hours: remaining.value.hours,
			minutes: remaining.value.minutes,
		})
		: trans('Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message'),
)

const ringColor = computed(
	() => ({
		ok: 'stroke-green-500',
		warning: 'stroke-amber-500',
		critical: 'stroke-red-500',
		closed: 'stroke-slate-300',
	})[remaining.value.level],
)

const textColor = computed(
	() => ({
		ok: 'text-green-700',
		warning: 'text-amber-600',
		critical: 'text-red-600',
		closed: 'text-slate-500',
	})[remaining.value.level],
)

const dash = computed(() => `${remaining.value.fraction * CIRCUMFERENCE} ${CIRCUMFERENCE}`)
</script>

<template>
	<div class="flex items-center gap-x-2 shrink-0" role="timer" :title="fullLabel" :aria-label="fullLabel"
		data-testid="messaging-window-countdown" :data-level="remaining.level">
		<div class="relative w-9 h-9">
			<svg class="w-9 h-9 -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
				<circle cx="18" cy="18" :r="RADIUS" fill="none" stroke-width="3" class="stroke-slate-200" />
				<circle cx="18" cy="18" :r="RADIUS" fill="none" stroke-width="3" stroke-linecap="round"
					:stroke-dasharray="dash" :class="ringColor" class="transition-all duration-500" />
			</svg>
			<!-- أقل من xl: الوقت داخل الحلقة لأن النص الجانبي مخفيّ (الهيدر ضيّق: 70% من المساحة) -->
			<span v-if="remaining.open" class="xl:hidden absolute inset-0 flex items-center justify-center text-[9px] font-medium leading-none"
				:class="textColor">
				{{ pad(remaining.hours) }}:{{ pad(remaining.minutes) }}
			</span>
			<svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"
				class="absolute inset-0 m-auto w-4 h-4 text-slate-400" fill="none" stroke="currentColor"
				stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
				<circle cx="12" cy="12" r="9" />
				<path d="M12 7v5l3 2" />
			</svg>
		</div>
		<span class="hidden xl:inline text-xs font-medium whitespace-nowrap" :class="textColor">
			{{ shortLabel }}
		</span>
	</div>
</template>
