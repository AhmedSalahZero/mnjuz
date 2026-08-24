<script setup>
/**
 * مؤشّر الرفع الجاري بجوار صندوق الرسائل.
 *
 * بديل النافذة التي كانت تحجب الشاشة حتى ينتهي الرفع. مضغوط في سطر واحد،
 * وبالضغط عليه تظهر تفاصيل كل مهمّة: محادثتها، ملفاتها، نسبتها.
 *
 * يعرض مهامّ كل المحادثات لا المحادثة الحالية وحدها — الموظّف الذي انتقل
 * ليردّ على عميل آخر يحتاج أن يرى أن رفعه السابق ما زال يعمل، وإخفاؤه يجعله
 * يظنّ أنه ضاع فيعيده.
 */
import { computed } from 'vue'
import { useTrans } from '@/Composables/useTrans'
import { jobPercent } from '@/Composables/uploadQueue'

const props = defineProps({
	jobs: { type: Array, required: true },
	percent: { type: Number, default: 0 },
	fileCount: { type: Number, default: 0 },
	expanded: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle', 'retry', 'cancel', 'dismiss'])

const trans = useTrans()

const uploading = computed(() =>
	props.jobs.filter((job) => job.state === 'uploading' || job.state === 'pending'))
const failed = computed(() => props.jobs.filter((job) => job.state === 'failed'))

const summary = computed(() => {
	if (uploading.value.length > 0) {
		return trans('Uploading :count files').replace(':count', props.fileCount)
	}

	return trans('Upload failed')
})
</script>

<template>
	<div v-if="jobs.length" class="border-t border-slate-200 bg-slate-50 text-xs">
		<button type="button" class="flex w-full items-center gap-2 px-4 py-2 text-start hover:bg-slate-100"
			:aria-expanded="expanded" @click="emit('toggle')">
			<svg v-if="uploading.length" class="animate-spin shrink-0 text-primary" width="14" height="14"
				viewBox="0 0 24 24" fill="none" aria-hidden="true">
				<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" />
				<path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
			</svg>
			<svg v-else class="shrink-0 text-red-600" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true">
				<path fill="currentColor"
					d="M12 2a10 10 0 1 0 0 20a10 10 0 0 0 0-20Zm1 15h-2v-2h2Zm0-4h-2V7h2Z" />
			</svg>

			<span class="min-w-0 flex-1 truncate" :class="uploading.length ? 'text-slate-600' : 'text-red-600'">
				{{ summary }}
			</span>

			<span v-if="uploading.length" class="shrink-0 tabular-nums text-slate-500">{{ percent }}%</span>
		</button>

		<div v-if="uploading.length" class="h-0.5 w-full bg-slate-200" role="progressbar" :aria-valuenow="percent"
			aria-valuemin="0" aria-valuemax="100">
			<div class="h-full bg-primary transition-all duration-200" :style="{ width: percent + '%' }"></div>
		</div>

		<ul v-if="expanded" class="max-h-40 overflow-y-auto border-t border-slate-200 bg-white">
			<li v-for="job in jobs" :key="job.id" class="flex items-center gap-2 px-4 py-2">
				<div class="min-w-0 flex-1">
					<div class="truncate text-slate-700">{{ job.fileNames.join('، ') }}</div>
					<div class="truncate text-[11px] text-slate-400">
						<span v-if="job.contactName">{{ job.contactName }}</span>
						<span v-if="job.state === 'failed'" class="text-red-600"> — {{ job.error }}</span>
					</div>
				</div>

				<span v-if="job.state === 'uploading'" class="shrink-0 tabular-nums text-slate-500">
					{{ jobPercent(job) }}%
				</span>
				<span v-else-if="job.state === 'pending'" class="shrink-0 text-slate-400">
					{{ $t('Waiting') }}
				</span>

				<button v-if="job.state !== 'failed'" type="button" class="shrink-0 text-slate-400 hover:text-red-600"
					@click="emit('cancel', job)">{{ $t('Cancel') }}</button>

				<template v-else>
					<button type="button" class="shrink-0 text-primary hover:underline"
						@click="emit('retry', job)">{{ $t('Retry') }}</button>
					<button type="button" class="shrink-0 text-slate-400 hover:text-slate-600"
						@click="emit('dismiss', job)">{{ $t('Dismiss') }}</button>
				</template>
			</li>
		</ul>
	</div>
</template>
