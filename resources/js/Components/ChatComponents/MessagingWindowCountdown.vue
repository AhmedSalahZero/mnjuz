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
		: trans('The customer has not messaged in over 24 hours. WhatsApp only allows template messages until they reply.'),
)

// الحالة المغلقة شارة حمراء بقفل لا حلقة رمادية: الرمادي بدا معطّلاً أو جارِي التحميل
// فلا يلفت الموظف إلى أن الرد الحرّ صار ممنوعاً.
const pillClass = computed(
	() => ({
		ok: 'bg-green-50 text-green-700 ring-green-200',
		warning: 'bg-amber-50 text-amber-700 ring-amber-200',
		critical: 'bg-red-50 text-red-700 ring-red-200',
		closed: 'bg-red-50 text-red-700 ring-red-200',
	})[remaining.value.level],
)

const dash = computed(() => `${remaining.value.fraction * CIRCUMFERENCE} ${CIRCUMFERENCE}`)
</script>

<template>
	<div class="inline-flex h-8 shrink-0 items-center gap-x-1.5 rounded-full px-2 ring-1 ring-inset xl:pe-3"
		:class="pillClass" role="timer" :title="fullLabel" :aria-label="fullLabel"
		data-testid="messaging-window-countdown" :data-level="remaining.level">
		<svg v-if="remaining.open" class="hidden xl:block w-4 h-4 shrink-0 -rotate-90" viewBox="0 0 36 36" aria-hidden="true">
			<circle cx="18" cy="18" :r="RADIUS" fill="none" stroke-width="5" class="stroke-current opacity-20" />
			<circle cx="18" cy="18" :r="RADIUS" fill="none" stroke-width="5" stroke-linecap="round"
				:stroke-dasharray="dash" class="stroke-current transition-all duration-500" />
		</svg>
		<svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" class="w-4 h-4 shrink-0"
			fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
			<rect x="5" y="11" width="14" height="10" rx="2" />
			<path d="M8 11V7a4 4 0 0 1 8 0v4" />
		</svg>
		<!-- أقل من xl الترويسة ضيّقة (70% من المساحة): المفتوحة وقتٌ بلون المستوى بلا حلقة، والمغلقة قفل وحده ونصّها في الـ title واللافتة تحت -->
		<span v-if="remaining.open" class="xl:hidden text-xs font-semibold tabular-nums" dir="ltr">
			{{ pad(remaining.hours) }}:{{ pad(remaining.minutes) }}
		</span>
		<span class="hidden xl:inline text-xs font-medium whitespace-nowrap">
			{{ shortLabel }}
		</span>
	</div>
</template>
