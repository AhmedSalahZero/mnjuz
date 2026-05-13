<template>
	<SettingLayout :modules="props.modules">
		<div class="md:h-[90vh]">
			<div class="flex justify-center items-center">
				<div class="md:w-[60em] w-full">
					<div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 px-4 pb-6">
						<div class="w-full py-2 mb-2 mt-2">
							<h4 class="text-[16px]">{{ $t('Working hours') }}</h4>
							<p class="text-slate-500 mt-1 mb-4">
								{{ $t('Define one or more intervals per day. Outside these hours, WhatsApp contacts receive an automatic reply with your weekly schedule.') }}
							</p>
							<div class="border border-slate-200 rounded-lg p-4 mb-4">
								<label class="block text-sm leading-6 text-gray-900">{{ $t('Text response') }}</label>
								<div class="mt-2">
									<textarea
										ref="messageTextareaRef"
										:value="form.working_hours_outside_message"
										:rows="4"
										class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
										:class="form.errors.working_hours_outside_message ? 'ring-[#b91c1c]' : 'ring-gray-300'"
										@input="(e) => (form.working_hours_outside_message = e.target.value)"
									></textarea>
									<div
										v-if="form.errors.working_hours_outside_message"
										class="form-error text-[#b91c1c] text-xs mt-1"
									>
										{{ form.errors.working_hours_outside_message }}
									</div>
								</div>
								<div class="flex items-center mt-2">
									<button
										type="button"
										class="bg-slate-100 px-2 py-1 rounded-md text-sm flex items-center gap-x-1 shadow-sm hover:bg-slate-200"
										@click="isVariableModalOpen = true"
									>
										{{ $t('Add variable') }}
										<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
											<path
												fill="none"
												stroke="currentColor"
												stroke-linecap="round"
												stroke-linejoin="round"
												stroke-width="1.5"
												d="M8.25 15L12 18.75L15.75 15m-7.5-6L12 5.25L15.75 9"
											/>
										</svg>
									</button>
								</div>
								<p class="text-slate-500 text-xs mt-2">
									{{ $t('working_hours_outside_message_hint') }}
								</p>
							</div>
							<div
								v-for="(row, index) in form.slots"
								:key="index"
								class="border border-slate-200 rounded-lg p-4 mb-3"
							>
								<div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
									<div class="md:col-span-4">
										<FormSelect
											v-model="row.day"
											:options="dayOptions"
											:name="$t('Day')"
											className="w-full"
											:placeholder="$t('Day')"
										/>
									</div>
									<div class="md:col-span-3">
										<label class="block text-xs text-slate-600 mb-1">{{ $t('Start') }}</label>
										<input
											v-model="row.open"
											type="time"
											step="60"
											class="w-full border border-slate-300 rounded-md px-2 py-2"
										/>
									</div>
									<div class="md:col-span-3">
										<label class="block text-xs text-slate-600 mb-1">{{ $t('End') }}</label>
										<input
											v-model="row.close"
											type="time"
											step="60"
											class="w-full border border-slate-300 rounded-md px-2 py-2"
										/>
									</div>
									<div class="md:col-span-2 flex md:justify-end">
										<button
											type="button"
											class="text-red-600 text-sm hover:underline"
											@click="removeRow(index)"
										>
											{{ $t('Remove') }}
										</button>
									</div>
								</div>
							</div>
							<div class="flex flex-wrap gap-3 mt-2">
								<button
									type="button"
									class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
									@click="addRow"
								>
									{{ $t('Add time slot') }}
								</button>
								<button
									type="button"
									class="rounded-md bg-black px-3 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-50"
									:disabled="form.processing"
									@click="submitForm"
								>
									{{ $t('Save') }}
								</button>
							</div>
							<p v-if="form.errors.slots" class="text-red-600 text-sm mt-2">{{ form.errors.slots }}</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	</SettingLayout>
	<Modal :label="$t('Select variable')" :is-open="isVariableModalOpen">
		<div class="flex bg-slate-50 p-2 rounded-md mt-3">
			<span class="font-light text-sm">{{ $t('working_hours_variable_modal_intro') }}</span>
		</div>
		<div class="mt-2 grid grid-cols-1 gap-x-6">
			<div class="pt-3 grid grid-cols-2 gap-x-2 text-sm gap-y-1 max-h-[50vh] overflow-y-auto">
				<button
					v-for="(item, idx) in props.placeholders"
					:key="idx"
					type="button"
					class="col-span-1 bg-gray-100 p-2 rounded-md text-left hover:bg-gray-50"
					@click="addVariableToMessage(item.value)"
				>
					{{ $t(item.label) }}
				</button>
			</div>
			<div class="mt-4 border-t pt-4">
				<button
					type="button"
					class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
					@click="isVariableModalOpen = false"
				>
					{{ $t('Cancel') }}
				</button>
			</div>
		</div>
	</Modal>
</template>
<script setup>
import SettingLayout from './Layout.vue'
import FormSelect from '@/Components/FormSelect.vue'
import Modal from '@/Components/Modal.vue'
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { locale } = useI18n()

const props = defineProps({
	modules: Array,
	working_hours: { type: Array, default: () => [] },
	working_hours_outside_message: { type: String, default: '' },
	placeholders: { type: Array, default: () => [] },
})

const isVariableModalOpen = ref(false)
const messageTextareaRef = ref(null)

/** 2024-01-07 is Sunday — matches PHP date('w') 0–6 */
const dayOptions = computed(() => {
	const loc = locale.value || 'ar'
	const fmt = new Intl.DateTimeFormat(loc, { weekday: 'long' })
	return Array.from({ length: 7 }, (_, d) => {
		const date = new Date(2024, 0, 7 + d)
		return { value: d, label: fmt.format(date) }
	})
})

const normalizeSlots = (rows) => {
	if (!Array.isArray(rows) || rows.length === 0) {
		return []
	}
	return rows.map((r) => ({
		day: Number(r.day),
		open: (r.open || '09:00').slice(0, 5),
		close: (r.close || '17:00').slice(0, 5),
	}))
}

const form = useForm({
	slots: normalizeSlots(props.working_hours),
	working_hours_outside_message: props.working_hours_outside_message ?? '',
})

const addVariableToMessage = (textToAdd) => {
	const textarea = messageTextareaRef.value
	if (!textarea) {
		return
	}
	const currentValue = textarea.value || ''
	const start = textarea.selectionStart || 0
	const end = textarea.selectionEnd || 0
	const newText = `${currentValue.substring(0, start)}${textToAdd}${currentValue.substring(end)}`
	textarea.value = newText
	form.working_hours_outside_message = newText
	setTimeout(() => {
		textarea.focus()
		textarea.setSelectionRange(start + textToAdd.length, start + textToAdd.length)
	}, 0)
	isVariableModalOpen.value = false
}

const addRow = () => {
	form.slots.push({ day: 6, open: '09:00', close: '17:00' })
}

const removeRow = (index) => {
	form.slots.splice(index, 1)
}

const submitForm = () => {
	form.slots = (form.slots || []).map((s) => ({
		day: Number(s.day),
		open: s.open,
		close: s.close,
	}))
	form.post('/settings/working-hours', {
		preserveScroll: true,
	})
}
</script>
