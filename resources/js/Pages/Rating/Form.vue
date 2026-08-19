<template>
	<div :class="rtlClass" class="min-h-screen bg-slate-50 flex items-center justify-center px-4 py-10">
		<div class="w-full max-w-md bg-white rounded-2xl shadow-sm border border-slate-200 p-6">

			<!-- الرابط صالح: النموذج -->
			<template v-if="state === 'open'">
				<h1 class="text-xl text-center text-gray-900">{{ $t('How was our service?') }}</h1>
				<p v-if="organizationName" class="text-center text-sm text-slate-500 mt-1">{{ organizationName }}</p>

				<div class="flex justify-center gap-2 my-7" dir="ltr">
					<button
						v-for="star in 5"
						:key="star"
						type="button"
						class="transition-transform hover:scale-110 focus:outline-none"
						:aria-label="$t('{count} stars', { count: star })"
						@click="form.rating = star"
						@mouseenter="hovered = star"
						@mouseleave="hovered = 0">
						<svg width="40" height="40" viewBox="0 0 24 24"
							:fill="star <= (hovered || form.rating) ? '#f59e0b' : 'none'"
							:stroke="star <= (hovered || form.rating) ? '#f59e0b' : '#cbd5e1'"
							stroke-width="1.5" stroke-linejoin="round">
							<path d="M12 2.5l2.9 5.88 6.49.95-4.7 4.58 1.11 6.46L12 17.33l-5.8 3.05 1.1-6.46-4.69-4.58 6.49-.95L12 2.5Z" />
						</svg>
					</button>
				</div>

				<div v-if="form.errors.rating" class="text-center text-xs text-[#b91c1c] mb-3">{{ form.errors.rating }}</div>

				<label class="block text-sm text-gray-900 mb-1">{{ $t('Tell us about your experience') }}</label>
				<textarea
					v-model="form.comment"
					rows="4"
					maxlength="2000"
					:placeholder="$t('Optional')"
					class="block w-full rounded-md border-0 py-2 px-3 text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 text-sm resize-none"></textarea>
				<div v-if="form.errors.comment" class="text-xs text-[#b91c1c] mt-1">{{ form.errors.comment }}</div>

				<button
					type="button"
					class="mt-5 w-full rounded-md bg-primary px-3 py-3 text-sm text-white disabled:opacity-50"
					:disabled="!form.rating || form.processing"
					@click="submit()">
					{{ form.processing ? $t('Sending...') : $t('Send rating') }}
				</button>
			</template>

			<!-- أُرسل التقييم: شكر، ولا نعرض النموذج ثانيةً -->
			<template v-else-if="state === 'submitted'">
				<div class="text-center py-6">
					<svg class="mx-auto" width="56" height="56" viewBox="0 0 24 24" fill="none"
						stroke="#16a34a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10" /><path d="m8.5 12.5 2.5 2.5 4.5-5" />
					</svg>
					<h1 class="text-xl text-gray-900 mt-4">{{ $t('Thank you!') }}</h1>
					<p class="text-sm text-slate-500 mt-2">{{ $t('Your rating has been recorded. We appreciate your time.') }}</p>
					<div v-if="stars" class="flex justify-center gap-1 mt-4" dir="ltr">
						<svg v-for="star in 5" :key="star" width="22" height="22" viewBox="0 0 24 24"
							:fill="star <= stars ? '#f59e0b' : 'none'" :stroke="star <= stars ? '#f59e0b' : '#cbd5e1'"
							stroke-width="1.5" stroke-linejoin="round">
							<path d="M12 2.5l2.9 5.88 6.49.95-4.7 4.58 1.11 6.46L12 17.33l-5.8 3.05 1.1-6.46-4.69-4.58 6.49-.95L12 2.5Z" />
						</svg>
					</div>
				</div>
			</template>

			<!-- منتهٍ أو غير صحيح -->
			<template v-else>
				<div class="text-center py-6">
					<svg class="mx-auto" width="56" height="56" viewBox="0 0 24 24" fill="none"
						stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10" /><path d="M12 8v5" /><path d="M12 16h.01" />
					</svg>
					<h1 class="text-xl text-gray-900 mt-4">{{ $t('This link is no longer valid') }}</h1>
					<p class="text-sm text-slate-500 mt-2">
						{{ state === 'expired' ? $t('The rating link has expired.') : $t('The rating link is incorrect or has already been used.') }}
					</p>
				</div>
			</template>

		</div>
	</div>
</template>

<script setup>
import { useRtl } from '@/Composables/useRtl'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps(['state', 'token', 'organizationName', 'stars'])
const { rtlClass } = useRtl()

const hovered = ref(0)
const form = useForm({
	rating: null,
	comment: '',
})

const submit = () => {
	if (!form.rating) return
	form.post(`/rate/${props.token}`, { preserveScroll: true })
}
</script>
