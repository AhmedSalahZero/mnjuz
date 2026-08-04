<template>
	<AppLayout>
		<div
			class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
			<div class="flex justify-between">
				<div>
					<h2 class="text-xl mb-1">{{ $t('Support tickets') }}</h2>
					<p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
							<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
								stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
						</svg>
						<span
							class="ml-1 mt-1">{{ $t('Have an issue? Create a ticket and one of our reps will be in touch') }}</span>
					</p>
				</div>
			</div>

			<div class="grid gap-6 lg:grid-cols-5">
				<!-- إنشاء التذكرة من داخل التطبيق بدل النموذج الخارجي -->
				<div class="lg:col-span-2">
					<form @submit.prevent="submit()" class="bg-white border border-slate-200 rounded-lg p-5">
						<h3 class="text-sm font-bold text-gray-900 mb-4">{{ $t('Create ticket') }}</h3>

						<FormSelect v-model="form.category" :name="$t('Category')" :type="'text'"
							:placeholder="$t('Select option')" :options="categoryOptions" :error="form.errors.category" />

						<div class="mt-4">
							<FormInput v-model="form.subject" :name="$t('Subject')" :error="form.errors.subject"
								:type="'text'" />
						</div>

						<div class="mt-4">
							<label class="block text-sm leading-6 text-gray-900">{{ $t('Message') }}</label>
							<textarea v-model="form.message" rows="6"
								class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset sm:text-sm"
								:class="form.errors.message ? 'ring-[#b91c1c]' : 'ring-gray-300'"></textarea>
							<div v-if="form.errors.message" class="text-[#b91c1c] text-xs mt-1">{{ form.errors.message }}</div>
						</div>

						<button type="submit" :disabled="form.processing"
							class="mt-5 rounded-md bg-primary px-4 py-2.5 text-sm text-white w-full disabled:opacity-60">
							{{ form.processing ? $t('Please wait') : $t('Create ticket') }}
						</button>
					</form>
				</div>

				<!-- التذاكر كما يراها فريق الدعم — مصدرها واز مباشرة -->
				<div class="lg:col-span-3">
					<div v-if="!props.wazAvailable"
						class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
						{{ $t('Your organization is not linked to the support platform yet.') }}
					</div>

					<div v-else class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
						<table class="w-full text-sm">
							<thead>
								<tr class="text-left text-slate-500 border-b bg-slate-50">
									<th class="px-4 py-3 font-medium">{{ $t('Subject') }}</th>
									<th class="px-4 py-3 font-medium text-center">{{ $t('Department') }}</th>
									<th class="px-4 py-3 font-medium text-center">{{ $t('Status') }}</th>
									<th class="px-4 py-3 font-medium"></th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="ticket in props.wazTickets" :key="ticket.id"
									class="border-b last:border-b-0 hover:bg-slate-50">
									<td class="px-4 py-3 font-medium text-gray-900">{{ ticket.subject }}</td>
									<td class="px-4 py-3 text-center text-slate-600">{{ $t(departmentLabel(ticket.department)) }}</td>
									<td class="px-4 py-3 text-center">
										<span class="inline-block rounded-full px-2 py-0.5 text-xs"
											:class="statusClass(ticket.status)">
											{{ $t(statusLabel(ticket.status)) }}
										</span>
									</td>
									<td class="px-4 py-3 text-end">
										<a v-if="ticket.url" :href="ticket.url" target="_blank" rel="noopener noreferrer"
											class="text-primary text-xs font-bold hover:underline">{{ $t('View') }}</a>
									</td>
								</tr>
								<tr v-if="!props.wazTickets || props.wazTickets.length === 0">
									<td colspan="4" class="px-4 py-8 text-center text-slate-400">{{ $t('No data available.') }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</AppLayout>
</template>
<script setup>
import AppLayout from "./../Layout/App.vue"
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
	rows: Object,
	wazTickets: { type: Array, default: () => [] },
	wazAvailable: { type: Boolean, default: false },
	categories: { type: Array, default: () => [] },
})

const form = useForm({
	category: null,
	subject: null,
	message: null,
})

const categoryOptions = computed(() => props.categories.map(c => ({ value: c.id, label: c.name })))

// أقسام وحالات التذاكر كما تعرّفها واز أعمال.
const DEPARTMENTS = { 1: 'Support', 2: 'Accounting', 3: 'Sales' }
const STATUSES = { 1: 'Open', 2: 'In progress', 3: 'Answered', 4: 'On hold', 5: 'Closed' }

const departmentLabel = (d) => DEPARTMENTS[Number(d)] ?? 'Support'
const statusLabel = (s) => STATUSES[Number(s)] ?? 'Open'

const statusClass = (s) => {
	switch (Number(s)) {
		case 5: return 'bg-slate-100 text-slate-500'
		case 3: return 'bg-green-100 text-green-700'
		case 4: return 'bg-amber-100 text-amber-700'
		default: return 'bg-blue-100 text-blue-700'
	}
}

const submit = () => form.post('/support', {
	preserveScroll: true,
	onSuccess: () => form.reset(),
})
</script>
