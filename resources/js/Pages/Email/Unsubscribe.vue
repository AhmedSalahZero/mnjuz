<template>
	<div :class="rtlClass" class="min-h-screen bg-slate-50 flex items-center justify-center px-4 py-10">
		<div class="w-full max-w-md bg-white rounded-2xl shadow-sm border border-slate-200 p-6 text-center">

			<!-- تأكيد: GET لا يغيّر شيئاً (فاحصات الروابط تفتحه) -->
			<template v-if="state === 'confirm'">
				<h1 class="text-xl text-gray-900">{{ $t('Unsubscribe from :name emails?').replace(':name', organizationName || '') }}</h1>
				<p class="text-sm text-slate-500 mt-2" dir="ltr">{{ email }}</p>
				<p class="text-xs text-slate-400 mt-2">{{ $t('You will stop receiving their email campaigns. Other messages are not affected.') }}</p>
				<button type="button" :disabled="form.processing" @click="submit"
					class="mt-6 w-full rounded-md bg-primary px-3 py-3 text-sm text-white disabled:opacity-50">
					{{ $t('Unsubscribe') }}
				</button>
			</template>

			<template v-else-if="state === 'done'">
				<svg class="mx-auto" width="56" height="56" viewBox="0 0 24 24" fill="none"
					stroke="#16a34a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="10" /><path d="m8.5 12.5 2.5 2.5 4.5-5" />
				</svg>
				<h1 class="text-xl text-gray-900 mt-4">{{ $t('You have been unsubscribed.') }}</h1>
				<p v-if="organizationName" class="text-sm text-slate-500 mt-2">{{ organizationName }}</p>
			</template>

			<template v-else>
				<svg class="mx-auto" width="56" height="56" viewBox="0 0 24 24" fill="none"
					stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="10" /><path d="M12 8v5" /><path d="M12 16h.01" />
				</svg>
				<h1 class="text-xl text-gray-900 mt-4">{{ $t('This link is not valid.') }}</h1>
			</template>
		</div>
	</div>
</template>

<script setup>
/**
 * إلغاء الاشتراك في بريد حملات منشأة. الرابط موقَّع وهو التصريح وحده.
 */
import { useRtl } from '@/Composables/useRtl'
import { useForm } from '@inertiajs/vue3'

const props = defineProps(['state', 'organizationName', 'email', 'action'])
const { rtlClass } = useRtl()

const form = useForm({})

const submit = () => {
	if (!props.action) return
	form.post(props.action, { preserveScroll: true })
}
</script>
