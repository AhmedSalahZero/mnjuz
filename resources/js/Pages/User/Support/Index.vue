<template>
	<AppLayout>
		<div class="p-4 md:p-8 h-full overflow-y-auto">
			<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
				<div>
					<h1 class="text-xl font-semibold text-gray-900">{{ $t('Support tickets') }}</h1>
					<p class="text-sm text-slate-500">
						{{ $t('Have an issue? Create a ticket and one of our reps will be in touch') }}
					</p>
				</div>
				<button v-if="props.wazAvailable" type="button" @click="openModal()"
					class="rounded-md bg-primary px-4 py-2.5 text-sm text-white shrink-0">
					{{ $t('Create ticket') }}
				</button>
			</div>

			<div v-if="!props.wazAvailable"
				class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
				{{ $t('Your organization is not linked to the support platform yet.') }}
			</div>

			<!-- التذاكر كما يراها فريق الدعم — مصدرها واز مباشرة، بعرض الصفحة -->
			<div v-else class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
				<table class="w-full text-sm">
					<thead>
						<tr class="text-left text-slate-500 border-b bg-slate-50">
							<th class="px-4 py-3 font-medium">{{ $t('Subject') }}</th>
							<th class="px-4 py-3 font-medium">{{ $t('Department') }}</th>
							<th class="px-4 py-3 font-medium">{{ $t('Status') }}</th>
							<th class="px-4 py-3 font-medium"></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="ticket in props.wazTickets" :key="ticket.id"
							class="border-b last:border-b-0 hover:bg-slate-50">
							<td class="px-4 py-3 font-medium text-gray-900">{{ ticket.subject }}</td>
							<td class="px-4 py-3 text-slate-600">{{ $t(departmentLabel(ticket.department)) }}</td>
							<td class="px-4 py-3">
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

		<!-- نافذة إنشاء التذكرة — تحلّ محلّ النموذج الخارجي (iframe) -->
		<Modal :label="$t('Create ticket')" :isOpen="isOpen" :closeBtn="true" @close="closeModal()">
			<form @submit.prevent="submit()" class="mt-5">
				<FormSelect v-model="form.category" :name="$t('Category')" :type="'text'"
					:placeholder="$t('Select option')" :options="categoryOptions" :error="form.errors.category" />

				<div class="mt-4">
					<FormInput v-model="form.subject" :name="$t('Subject')" :error="form.errors.subject" :type="'text'" />
				</div>

				<div class="mt-4">
					<label class="block text-sm leading-6 text-gray-900">{{ $t('Message') }}</label>
					<textarea v-model="form.message" rows="6"
						class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset sm:text-sm"
						:class="form.errors.message ? 'ring-[#b91c1c]' : 'ring-gray-300'"></textarea>
					<div v-if="form.errors.message" class="text-[#b91c1c] text-xs mt-1">{{ form.errors.message }}</div>
				</div>

				<div class="mt-6 flex gap-3">
					<button type="button" @click="closeModal()"
						class="rounded-md bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200">
						{{ $t('Cancel') }}
					</button>
					<button type="submit" :disabled="form.processing"
						class="rounded-md bg-primary px-4 py-2 text-sm text-white flex-1 disabled:opacity-60">
						{{ form.processing ? $t('Please wait') : $t('Create ticket') }}
					</button>
				</div>
			</form>
		</Modal>
	</AppLayout>
</template>
<script setup>
import AppLayout from "./../Layout/App.vue"
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

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

const isOpen = ref(false)

const openModal = () => {
	form.clearErrors()
	isOpen.value = true
}

const closeModal = () => {
	isOpen.value = false
}

const submit = () => form.post('/support', {
	preserveScroll: true,
	onSuccess: () => {
		form.reset()
		closeModal()
	},
})
</script>
