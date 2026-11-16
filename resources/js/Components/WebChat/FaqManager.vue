<script setup>
/**
 * الأسئلة الشائعة في صفحة إعدادات المحادثة المباشرة (WebChatFaqController).
 *
 * كل تغيير يُحفظ وحده فوراً، لا مع زرّ «حفظ» الصفحة. preserveState: true
 * لا تُعاد الصفحة من الصفر، فما لم يُحفظ من بقية الإعدادات يبقى كما هو.
 */
import Modal from '@/Components/Modal.vue'
import FormToggleSwitch from '@/Components/FormToggleSwitch.vue'
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import draggable from 'vuedraggable'

const props = defineProps({
	faqs: { type: Array, default: () => [] },
	max: { type: Number, default: 30 },
})

const keep = { preserveState: true, preserveScroll: true }
const IMAGE_TYPES = 'image/png,image/jpeg,image/webp'

// نسخة محلّية للسحب، تتبع ما يعود من الخادم بعد كل حفظ.
const list = ref([...props.faqs])
watch(() => props.faqs, (faqs) => (list.value = [...faqs]))

const canAdd = computed(() => list.value.length < props.max)

/* ───────── إضافة وتعديل ───────── */

const editing = ref(null) // null: مغلق، 0: جديد، وإلّا معرّف السؤال
const preview = ref(null)
const form = useForm({
	question_ar: '',
	question_en: '',
	answer_ar: '',
	answer_en: '',
	image: null,
	remove_image: false,
	is_active: true,
})

const current = computed(() => list.value.find((faq) => faq.id === editing.value) ?? null)
const shownImage = computed(() => preview.value ?? (form.remove_image ? null : current.value?.image_url ?? null))

const open = (faq = null) => {
	form.reset()
	form.clearErrors()

	if (faq) {
		form.question_ar = faq.question_ar ?? ''
		form.question_en = faq.question_en ?? ''
		form.answer_ar = faq.answer_ar ?? ''
		form.answer_en = faq.answer_en ?? ''
		form.is_active = faq.is_active
	}

	setPreview(null)
	editing.value = faq ? faq.id : 0
}

const close = () => {
	editing.value = null
	setPreview(null)
}

const setPreview = (url) => {
	if (preview.value) URL.revokeObjectURL(preview.value)
	preview.value = url
}

const pickImage = (event) => {
	const file = event.target.files?.[0] ?? null
	form.image = file
	form.remove_image = false
	setPreview(file ? URL.createObjectURL(file) : null)
	event.target.value = ''
}

const removeImage = () => {
	form.image = null
	form.remove_image = true
	setPreview(null)
}

const submit = () => {
	const url = editing.value ? `/settings/webchat/faqs/${editing.value}` : '/settings/webchat/faqs'

	form.post(url, { ...keep, forceFormData: true, onSuccess: close })
}

/* ───────── من القائمة مباشرة ───────── */

const toggle = (faq) => {
	router.post(`/settings/webchat/faqs/${faq.id}`, {
		question_ar: faq.question_ar,
		question_en: faq.question_en ?? '',
		answer_ar: faq.answer_ar ?? '',
		answer_en: faq.answer_en ?? '',
		is_active: !faq.is_active,
	}, keep)
}

const reorder = () => {
	router.post('/settings/webchat/faqs/reorder', { ids: list.value.map((faq) => faq.id) }, keep)
}

const deleting = ref(null)
const destroy = () => {
	const id = deleting.value
	deleting.value = null
	router.delete(`/settings/webchat/faqs/${id}`, keep)
}

const inputClass = (key) => [
	'mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset sm:text-sm',
	form.errors[key] ? 'ring-[#b91c1c]' : 'ring-gray-300',
]
</script>

<template>
	<section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
		<div class="flex flex-wrap items-center justify-between gap-2">
			<h4 class="text-[15px]">{{ $t('FAQs') }}</h4>
			<span class="text-xs text-slate-500" dir="ltr">{{ list.length }} / {{ props.max }}</span>
		</div>
		<p class="text-slate-500 mt-1 mb-3">{{ $t('A visitor who chooses “I have a question” reads these answers before writing to you. Each answer can be text, an image, or both.') }}</p>

		<p v-if="list.length === 0" class="rounded-md border border-dashed border-slate-300 p-4 text-center text-slate-500">
			{{ $t('No questions yet. Without questions the visitor goes straight to the chat.') }}
		</p>

		<draggable v-else tag="div" :list="list" handle=".handle" item-key="id" class="space-y-2" @end="reorder">
			<template #item="{ element, index }">
				<div class="flex items-start gap-2 rounded-md border border-slate-200 p-3" :class="{ 'bg-slate-50': !element.is_active }">
					<span class="handle cursor-move text-slate-400 pt-0.5" :title="$t('Drag to reorder')" aria-hidden="true">⠿</span>
					<span class="text-slate-500 pt-0.5">{{ index + 1 }}.</span>
					<div class="min-w-0 flex-1">
						<div class="font-medium text-slate-800 break-words">{{ element.question_ar }}</div>
						<div v-if="element.question_en" class="text-xs text-slate-500 break-words" dir="ltr">{{ element.question_en }}</div>
						<div class="mt-1 flex flex-wrap gap-2 text-xs text-slate-500">
							<span v-if="element.answer_ar || element.answer_en" class="truncate max-w-[16rem]">{{ element.answer_ar || element.answer_en }}</span>
							<span v-if="element.image_url" class="rounded bg-slate-100 px-1.5">{{ $t('Image') }}</span>
							<span v-if="!element.is_active" class="rounded bg-amber-50 px-1.5 text-amber-700">{{ $t('Hidden from visitors') }}</span>
						</div>
					</div>
					<div class="flex shrink-0 items-center gap-3">
						<FormToggleSwitch :modelValue="element.is_active" @update:modelValue="toggle(element)" :title="$t('Shown to visitors')" />
						<button type="button" class="text-xs text-slate-700 underline" @click="open(element)">{{ $t('Edit') }}</button>
						<button type="button" class="text-xs text-red-700" @click="deleting = element.id">{{ $t('Delete') }}</button>
					</div>
				</div>
			</template>
		</draggable>

		<button type="button" class="mt-3 rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50 disabled:opacity-50"
			:disabled="!canAdd" @click="open()">+ {{ $t('Add a question') }}</button>
		<span v-if="!canAdd" class="ms-2 text-xs text-slate-500">{{ $t('You have reached the maximum number of questions.') }}</span>

		<Modal :label="editing ? $t('Edit question') : $t('Add a question')" :isOpen="editing !== null" :closeBtn="true" @close="close">
			<form class="space-y-3 pt-3 text-sm text-start" @submit.prevent="submit">
				<label class="block">
					<span class="text-slate-700">{{ $t('Question') }}</span>
					<input type="text" maxlength="150" v-model="form.question_ar" :class="inputClass('question_ar')" />
					<span v-if="form.errors.question_ar" class="block text-[#b91c1c] text-xs mt-1">{{ form.errors.question_ar }}</span>
				</label>
				<label class="block">
					<span class="text-slate-700">{{ $t('Question in English (optional)') }}</span>
					<input type="text" maxlength="150" dir="ltr" v-model="form.question_en" :class="inputClass('question_en')" />
				</label>
				<label class="block">
					<span class="text-slate-700">{{ $t('Answer') }}</span>
					<textarea rows="3" maxlength="1000" v-model="form.answer_ar" :class="inputClass('answer_ar')"></textarea>
					<span v-if="form.errors.answer_ar" class="block text-[#b91c1c] text-xs mt-1">{{ form.errors.answer_ar }}</span>
				</label>
				<label class="block">
					<span class="text-slate-700">{{ $t('Answer in English (optional)') }}</span>
					<textarea rows="3" maxlength="1000" dir="ltr" v-model="form.answer_en" :class="inputClass('answer_en')"></textarea>
				</label>

				<div>
					<span class="text-slate-700">{{ $t('Image (optional)') }}</span>
					<div class="mt-1 flex flex-wrap items-center gap-3">
						<img v-if="shownImage" :src="shownImage" alt="" class="h-16 w-auto rounded border border-slate-200 object-contain" />
						<label class="cursor-pointer rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50">
							{{ shownImage ? $t('Change image') : $t('Add image') }}
							<input type="file" :accept="IMAGE_TYPES" class="sr-only" @change="pickImage" />
						</label>
						<button v-if="shownImage" type="button" class="text-xs text-red-700" @click="removeImage">{{ $t('Remove image') }}</button>
					</div>
					<span class="block text-xs text-slate-500 mt-1">{{ $t('PNG, JPG or WEBP, up to 2 MB.') }}</span>
					<span v-if="form.errors.image" class="block text-[#b91c1c] text-xs mt-1">{{ form.errors.image }}</span>
				</div>

				<div class="flex items-center justify-between gap-4">
					<span class="text-slate-700">{{ $t('Shown to visitors') }}</span>
					<FormToggleSwitch v-model="form.is_active" />
				</div>

				<div class="flex justify-end gap-2 pt-2">
					<button type="button" class="rounded-md border border-slate-300 px-3 py-1.5" @click="close">{{ $t('Cancel') }}</button>
					<button type="submit" class="rounded-md bg-primary px-4 py-1.5 text-white disabled:opacity-50" :disabled="form.processing">
						{{ form.processing ? $t('Saving...') : $t('Save') }}
					</button>
				</div>
			</form>
		</Modal>

		<Modal :label="$t('Delete this question?')" :isOpen="deleting !== null" :closeBtn="true" @close="deleting = null">
			<div class="pt-3 text-sm">
				<p class="text-slate-600">{{ $t('Visitors will no longer see it. Conversations that already mention it keep its text.') }}</p>
				<div class="mt-5 flex justify-end gap-2">
					<button type="button" class="rounded-md border border-slate-300 px-3 py-1.5" @click="deleting = null">{{ $t('Cancel') }}</button>
					<button type="button" class="rounded-md bg-red-600 px-3 py-1.5 text-white" @click="destroy">{{ $t('Delete') }}</button>
				</div>
			</div>
		</Modal>
	</section>
</template>
