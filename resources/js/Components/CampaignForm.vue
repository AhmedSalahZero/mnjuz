<script setup>
import axios from "axios"
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import WhatsappTemplate from '@/Components/WhatsappTemplate.vue'
import { headerPreviewSource } from '@/Composables/templateMediaPreview'
import { ref, computed, onMounted, watch } from 'vue'
import { Link, useForm } from "@inertiajs/vue3"
import 'vue3-toastify/dist/index.css'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

const props = defineProps({
	templates: Object,
	contactGroups: Object,
	settings: Array,
	contact: {
		type: String,
		default: null
	},
	displayTitle: {
		type: Boolean,
		default: false
	},
	displayCancelBtn: {
		type: Boolean,
		default: true
	},
	isCampaignFlow: {
		type: Boolean,
		default: true
	},
	sendText: {
		type: String,
		default: 'Save'
	},
	// When false, the internal submit button and submit logic are disabled.
	submitEnabled: {
		type: Boolean,
		default: true
	},
	// Optional external selected template UUID (e.g. from settings page).
	initialTemplate: {
		type: String,
		default: null
	},
	// Hide the internal template selector when it's controlled externally.
	showTemplateSelector: {
		type: Boolean,
		default: true
	},
	// Optional pre-filled parameters (header/body/buttons) for a given template.
	initialParameters: {
		type: Object,
		default: null,
	},
})
const isLoading = ref(false)
const mediaHistory = ref([])
const selectedHistoryUuid = ref(null)

/**
 * رابط الوسائط الذي ترسمه المعاينة — و'' حين لا يوجد ما يُعرض.
 *
 * كان WhatsappTemplate يُستدعى بلا placeholder فيبقى على قيمته الافتراضية
 * true، فترسم المعاينة العنصر النائب مهما اختار العميل. فلا شيء يتغيّر
 * أمامه حين ينجح اختياره — وهذا وحده ما جعل ميزة «الملفات المستخدمة
 * سابقاً» تبدو معطّلة.
 */
const headerPreview = computed(() => headerPreviewSource(form.header))
const isHistoryLoading = ref(false)
const contactGroupOptions = ref([
	{ value: 'all', label: trans('all contacts') },
])
const templateOptions = ref([])
const config = ref(props.settings?.metadata)
const settings = ref(config.value ? JSON.parse(config.value) : null)

const variableOptions = ref([
	{ value: 'static', label: trans('static') },
	{ value: 'dynamic', label: trans('dynamic') }
])

const dynamicOptions = ref([
	{ value: 'first name', label: trans('contact first name') },
	{ value: 'last name', label: trans('contact last name') },
	{ value: 'name', label: trans('contact full name') },
	{ value: 'phone', label: trans('contact phone') },
	{ value: 'email', label: trans('contact email') },
])

const form = useForm({
	name: null,
	template: null,
	contacts: null,
	time: null,
	skip_schedule: false,
	'header': {
		'format': null,
		'text': null,
		'parameters': []
	},
	'body': {
		'text': null,
		'parameters': []
	},
	'footer': {
		'text': null,
	},
	'buttons': [],
})

const loadTemplate = async () => {
	try {
		const response = await axios.get('/templates/' + form.template)
		if (response) {
			const metadata = JSON.parse(response.data.metadata)
			form.header.format = extractComponent(metadata, 'HEADER', 'format')

			form.header.text = extractComponent(metadata, 'HEADER', 'text')
			const headerExamples = extractComponent(metadata, 'HEADER', 'example')
			if (headerExamples) {
				if (form.header.format === 'TEXT') {
					form.header.parameters = headerExamples.header_text.map(item => ({
						type: 'text',
						selection: 'static',
						value: item,
					}))
				} else if (form.header.format === 'IMAGE' || form.header.format === 'DOCUMENT' || form.header.format === 'VIDEO') {
					form.header.parameters = headerExamples.header_handle.map(item => ({
						type: form.header.format,
						selection: 'default',
						value: null,
						url: item,
					}))
				}
			} else {
				form.header.parameters = []
			}


			form.body.text = extractComponent(metadata, 'BODY', 'text')
			const bodyExamples = extractComponent(metadata, 'BODY', 'example')
			if (bodyExamples) {
				form.body.parameters = bodyExamples.body_text[0].map(item => ({
					type: 'text',
					selection: 'static',
					value: item,
				}))
			} else {
				form.body.parameters = []
			}

			form.footer.text = extractComponent(metadata, 'FOOTER', 'text')

			const buttons = extractComponent(metadata, 'BUTTONS', 'buttons')
			if (buttons) {
				form.buttons = buttons.map(item => ({
					type: item.type,
					text: item.text,
					value: item[item.type.toLowerCase()] ?? null,
					parameters: (item.type === 'QUICK_REPLY')
						? [{ type: 'static', value: null }]
						: (item.example
							? item.example.map(param => ({ type: 'static', value: param }))
							: []
						),
				}))
			} else {
				form.buttons = []
			}

			// If we have saved parameters for this exact template, override defaults
			if (props.initialParameters && props.initialParameters.template === form.template) {
				if (props.initialParameters.header?.parameters) {
					form.header.parameters = JSON.parse(JSON.stringify(props.initialParameters.header.parameters))
				}
				if (props.initialParameters.body?.parameters) {
					form.body.parameters = JSON.parse(JSON.stringify(props.initialParameters.body.parameters))
				}
				if (Array.isArray(props.initialParameters.buttons)) {
					form.buttons = JSON.parse(JSON.stringify(props.initialParameters.buttons))
				}
			}

			// Emit the current parameters so parents (e.g. settings page) can persist them
			emit('update:parameters', {
				template: form.template,
				header: { parameters: form.header.parameters },
				body: { parameters: form.body.parameters },
				buttons: form.buttons,
			})

			await loadMediaHistory()

		}
	} catch (error) {
		//console.error('Error fetching data:', error);
	}
}

const loadMediaHistory = async () => {
	const mediaType = form.header.parameters[0]?.type
	if (!mediaType || !['IMAGE', 'DOCUMENT', 'VIDEO'].includes(mediaType)) {
		mediaHistory.value = []
		return
	}

	isHistoryLoading.value = true
	try {
		const response = await axios.get('/campaigns/media-history', {
			params: { type: mediaType },
		})
		mediaHistory.value = response.data?.data ?? []
	} catch (error) {
		mediaHistory.value = []
	} finally {
		isHistoryLoading.value = false
	}
}

/**
 * اختيار ملف سبق استخدامه.
 *
 * نرسل uuid لا المسار: الخادم كان يبحث بمطابقة نصّية كاملة على الرابط،
 * فأيّ اختلاف حرف — ترميز، مسافة، تغيّر شكل رابط S3 — يُنتج «الملف لم يعد
 * متاحاً» على ملفٍ موجود. والـuuid معرّف ثابت وصلنا مع القائمة أصلاً.
 * url يبقى المسار لأنه ما تعرضه المعاينة.
 */
const selectHistoryItem = (item) => {
	if (!form.header.parameters[0]) {
		return
	}

	selectedHistoryUuid.value = item.uuid
	form.header.parameters[0].selection = 'history'
	form.header.parameters[0].value = item.uuid
	form.header.parameters[0].url = item.path
}

const deleteHistoryItem = async (item) => {
	if (!window.confirm(trans('Remove this file from history?'))) {
		return
	}

	try {
		await axios.delete(`/campaigns/media-history/${item.uuid}`)
		if (selectedHistoryUuid.value === item.uuid) {
			selectedHistoryUuid.value = null
			if (form.header.parameters[0]) {
				form.header.parameters[0].value = null
				form.header.parameters[0].selection = 'default'
			}
		}
		await loadMediaHistory()
	} catch (error) {
		// رسالة الخادم أوّلاً: «Not found» تعني قائمة قديمة أو ضغطة مكرّرة،
		// والرسالة العامة وحدها كانت تُخفي ذلك عن العميل وعنّا معاً.
		alert(
			error?.response?.data?.message
			|| trans('Something went wrong. Refresh the page and try again')
		)
		await loadMediaHistory()
	}
}

const selectedMediaLabel = (index) => {
	const param = form.header.parameters[index]
	if (!param?.value) {
		return null
	}
	if (param.selection === 'history') {
		const item = mediaHistory.value.find((entry) => entry.uuid === selectedHistoryUuid.value)
		// القيمة صارت uuid، وعرضه على العميل بلا معنى — نعود لاسم الملف.
		return item?.name ?? trans('Previously used file')
	}
	if (param.selection === 'default') {
		return param.value
	}
	return param.value?.name ?? null
}

const handleFileUpload = (event) => {
	const fileSizeLimit = getFileSizeLimit(form.header.parameters[0].type)
	const file = event.target.files[0]

	if (file && file.size > fileSizeLimit) {
		// Handle file size exceeding the limit
		alert(trans('file size exceeds the limit. Max allowed size:') + ' ' + fileSizeLimit + 'b')
		// Clear the file input
		event.target.value = null
	} else {
		const reader = new FileReader()

		reader.onload = (e) => {
			form.header.parameters[0].url = e.target.result
		}

		form.header.parameters[0].selection = 'upload'
		form.header.parameters[0].value = file
		selectedHistoryUuid.value = null

		// Start reading the file
		reader.readAsDataURL(file)
	}
}

const getFileAcceptAttribute = (fileType) => {
	switch (fileType) {
		case 'IMAGE':
			return '.png, .jpg, .jpeg'
		case 'DOCUMENT':
			return '.pdf, .txt, .ppt, .doc, .xls, .docx, .pptx, .xlsx'
		case 'VIDEO':
			return '.mp4'
		default:
			return ''
	}
}

const getFileSizeLimit = (fileType) => {
	switch (fileType) {
		case 'IMAGE':
			return 5 * 1024 * 1024 // 5MB
		case 'DOCUMENT':
			return 100 * 1024 * 1024 // 100MB
		case 'VIDEO':
			return 16 * 1024 * 1024 // 16MB
		default:
			return Infinity
	}
}

const extractComponent = (data, type, customProperty) => {
	const component = data.components.find(
		(c) => c.type === type
	)

	return component ? component[customProperty] : null
}

const transformOptions = (options) => {
	return options.map((option) => {
		// Full template object as used in chat (uuid, name, language)
		if (option.uuid) {
			return {
				value: option.uuid,
				label: option.language ? option.name + ' [' + option.language + ']' : option.name,
			}
		}

		// Generic value/label pair as used in settings page
		if (option.value !== undefined && option.label !== undefined) {
			return {
				value: option.value,
				label: option.label,
			}
		}

		// Fallback to something sensible
		return {
			value: option.value ?? option.uuid ?? option.name,
			label: option.label ?? option.name ?? '',
		}
	})
}

const submitForm = () => {
	isLoading.value = true
	form.post(props.isCampaignFlow ? '/campaigns' : '/chat/' + props.contact + '/send/template', {
		onFinish: () => {
			isLoading.value = false
			if (!props.isCampaignFlow) {
				emit('viewTemplate', false)
			}
		},
	})
}

const emit = defineEmits(['viewTemplate', 'update:parameters'])

const viewTemplate = () => {
	emit('viewTemplate', false)
}

const handleSubmit = () => {
	if (!props.submitEnabled) {
		return
	}
	submitForm()
}

onMounted(() => {
	templateOptions.value = transformOptions(props.templates)
	contactGroupOptions.value = [...contactGroupOptions.value, ...transformOptions(props.contactGroups)]

	// If an external initial template is provided (e.g. auth template in settings),
	// keep the internal form.template in sync and load its structure.
	if (props.initialTemplate) {
		form.template = props.initialTemplate
		loadTemplate()
	}
})

watch(
	() => props.initialTemplate,
	(newValue, oldValue) => {
		if (newValue === oldValue) return

		// Update the selected template when controlled from outside
		form.template = newValue

		if (newValue) {
			loadTemplate()
		} else {
			// Clear template-related fields when no template is selected
			form.header.format = null
			form.header.text = null
			form.header.parameters = []
			form.body.text = null
			form.body.parameters = []
			form.footer.text = null
			form.buttons = []
		}
	},
)

// Whenever parameters or template change, notify parent with the latest snapshot.
watch(
	() => ({
		template: form.template,
		header: { parameters: form.header.parameters },
		body: { parameters: form.body.parameters },
		buttons: form.buttons,
	}),
	(value) => {
		emit('update:parameters', value)
	},
	{ deep: true },
)
</script>
<template>
	<div :class="'md:flex md:flex-grow-1'">
		<div v-if="!settings?.whatsapp" class="md:w-[50%] p-4 md:p-8 overflow-y-auto h-[90vh]">
			<div class="bg-slate-50 border border-primary shadow rounded-md p-4 py-8">
				<div class="flex justify-center mb-4">
					<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 48 48">
						<path fill="black"
							d="M43.634 4.366a1.25 1.25 0 0 1 0 1.768l-4.913 4.913a9.253 9.253 0 0 1-.744 12.244l-3.343 3.343a1.25 1.25 0 0 1-1.768 0l-11.5-11.5a1.25 1.25 0 0 1 0-1.768l3.343-3.343a9.25 9.25 0 0 1 12.244-.743l4.913-4.914a1.25 1.25 0 0 1 1.768 0m-7.611 7.425a6.75 6.75 0 0 0-9.546 0l-2.46 2.459l9.733 9.732l2.46-2.459a6.75 6.75 0 0 0 0-9.546zM9.28 36.953l-4.914 4.913a1.25 1.25 0 0 0 1.768 1.768l4.913-4.913a9.253 9.253 0 0 0 12.244-.744l3.343-3.343a1.25 1.25 0 0 0 0-1.768L25.268 31.5l3.366-3.366a1.25 1.25 0 0 0-1.768-1.768L23.5 29.732L18.268 24.5l3.366-3.366a1.25 1.25 0 0 0-1.768-1.768L16.5 22.732l-1.366-1.366a1.25 1.25 0 0 0-1.768 0l-3.343 3.343a9.25 9.25 0 0 0-.743 12.244m2.51-10.476l2.46-2.46l9.732 9.733l-2.459 2.46a6.75 6.75 0 0 1-9.546 0l-.186-.187a6.75 6.75 0 0 1 0-9.546" />
					</svg>
				</div>
				<h3 class="text-center text-lg font-medium mb-4">{{ $t('Connect your whatsapp account') }}</h3>
				<h4 class="text-center mb-4">
					{{ $t('You need to connect your WhatsApp account first before you can send out campaigns.') }}
				</h4>
				<div class="flex justify-center">
					<Link href="/settings/whatsapp"
						class="rounded-md px-3 py-2 text-sm hover:shadow-md text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary"
						:disabled="isLoading">
						<span v-if="!isLoading">{{ $t('Connect Whatsapp account') }}</span>
					</Link>
				</div>
			</div>
		</div>
		<form v-else @submit.prevent="handleSubmit" class="overflow-y-auto md:w-[50%]"
			:class="isCampaignFlow ? 'p-4 md:p-8 h-[90vh]' : ' h-full'">
			<div v-if="displayTitle"
				class="m-1 rounded px-3 pt-3 pb-3 bg-slate-100 flex items-center justify-between mb-4">
				<h3 class="text-[15px]">{{ $t('Send Template Message') }}</h3>
				<button @click="viewTemplate()"
					class="text-sm md:inline-flex hidden justify-center rounded-md border border-transparent bg-red-800 px-4 py-1 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">Cancel</button>
			</div>
			<div class="grid gap-x-6 gap-y-4 mb-8" :class="isCampaignFlow ? 'sm:grid-cols-6' : 'p-3 md:p-3'">
				<FormInput v-if="isCampaignFlow" v-model="form.name" :name="$t('Campaign name')" :type="'text'"
					:error="form.errors.name" :required="true" :class="'sm:col-span-6'" />
				<FormSelect v-if="showTemplateSelector" v-model="form.template" @update:modelValue="loadTemplate"
					:options="templateOptions" :required="true" :error="form.errors.template" :name="$t('Template')"
					:class="'sm:col-span-3'" :placeholder="$t('Select template')" />
				<FormSelect v-if="isCampaignFlow" v-model="form.contacts" :options="contactGroupOptions"
					:name="$t('Send to')" :required="true" :class="'sm:col-span-3'" :placeholder="$t('Select contacts')"
					:error="form.errors.contacts" />
				<FormInput v-if="isCampaignFlow && !form.skip_schedule" v-model="form.time" :name="$t('Scheduled time')"
					:type="'datetime-local'" :error="form.errors.time" :required="true" :class="'sm:col-span-6'" />
				<div v-if="isCampaignFlow" class="relative flex gap-x-3 sm:col-span-6">
					<div class="flex h-6 items-center">
						<input v-model="form.skip_schedule" id="skip-schedule" name="skip-schedule" type="checkbox"
							class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600">
					</div>
					<div class="text-sm leading-6">
						<label for="skip-schedule"
							class="font-medium text-gray-900">{{ $t('Skip scheduling & send immediately') }}</label>
					</div>
				</div>
			</div>
			<div :class="isCampaignFlow ? '' : 'px-3 md:px-3'">
				<div v-if="form.header.parameters.length > 0" class="bg-slate-100 p-3 mt-4 text-sm">
					<h2 class="flex items-center justify-between space-x-2 pb-2 border-b">
						<div class="flex items-center space-x-2">
							<span>{{ $t('Header variables') }}</span>
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 1024 1024">
								<path fill="currentColor"
									d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64zm67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344zM590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296c-44.096 0-108.992 44.736-148.48 101.504c0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04c67.84 0 107.904-43.648 147.456-100.416z" />
							</svg>
						</div>
						<span>
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
								<path fill="currentColor"
									d="M6.102 16.98c-1.074 0-1.648-1.264-.94-2.073l5.521-6.31a1.75 1.75 0 0 1 2.634 0l5.522 6.31c.707.809.133 2.073-.94 2.073H6.101Z" />
							</svg>
						</span>
					</h2>
					<div v-for="(item, index) in form.header.parameters" :key="index"
						class="mt-2 flex items-center space-x-4">
						<div v-if="form.header.parameters[index].type === 'text'" class="w-full">
							<FormSelect v-model="form.header.parameters[index].selection" :name="$t('Content type')"
								:options="variableOptions" :class="'sm:col-span-6'" />
						</div>
						<div v-if="form.header.parameters[index].type === 'text'" class="w-full">
							<FormInput v-if="form.header.parameters[index].selection === 'static'" :name="$t('Value')"
								:required="true" :error="form.errors['header.parameters.0.value']"
								v-model="form.header.parameters[index].value" :type="'text'" :class="'sm:col-span-6'" />
							<FormSelect v-if="form.header.parameters[index].selection === 'dynamic'" :name="$t('Value')"
								:required="true" :error="form.errors['header.parameters.0.value']"
								v-model="form.header.parameters[index].value" :options="dynamicOptions"
								:class="'sm:col-span-6'" />
						</div>
						<div v-if="['IMAGE', 'DOCUMENT', 'VIDEO'].includes(form.header.parameters[index].type)"
							class="w-full mt-3">
							<div>
								<div class="flex items-center space-x-4">
									<label
										class="cursor-pointer flex justify-center px-2 py-2 w-[30%] bg-slate-200 shadow-sm rounded-lg border"
										:class="form.errors['header.parameters.0.value'] ? 'border border-red-700' : ''"
										for="file-upload">
										{{ $t('Upload') }}
									</label>
									<input type="file" class="sr-only"
										:accept="getFileAcceptAttribute(form.header.parameters[index].type)"
										ref="fileInput" id="file-upload" @change="handleFileUpload" />
									<div v-if="form.header.parameters[index].value" class="w-[20em] truncate">
										{{ selectedMediaLabel(index) }}
									</div>
									<span v-else>{{ $t('No file chosen') }}</span>
								</div>
								<div
									v-if="isCampaignFlow && ['IMAGE', 'DOCUMENT', 'VIDEO'].includes(form.header.parameters[index].type)"
									class="mt-3 border-t border-slate-200 pt-3"
								>
									<p class="text-xs font-medium text-gray-700 mb-2">
										{{ $t('Previously used files') }}
									</p>
									<p v-if="isHistoryLoading" class="text-xs text-gray-500">{{ $t('Loading...') }}</p>
									<p v-else-if="mediaHistory.length === 0" class="text-xs text-gray-500">
										{{ $t('No previous files for this media type') }}
									</p>
									<ul v-else class="space-y-2 max-h-52 overflow-y-auto">
										<li
											v-for="item in mediaHistory"
											:key="item.uuid"
											class="flex items-center gap-2 rounded-md border bg-white p-1.5"
											:class="selectedHistoryUuid === item.uuid ? 'border-primary ring-1 ring-primary bg-primary/5' : 'border-slate-200'"
										>
											<!--
												الصفّ كلّه زرّ الاختيار: الاسم وحده كان هدفاً ضيّقاً لا يبدو
												قابلاً للضغط، وبجواره «حذف» أحمر يبدو زرّاً أكثر منه — فمن
												أراد الاختيار ضغط الحذف.
											-->
											<button
												type="button"
												class="flex flex-1 items-center gap-2 text-left min-w-0"
												:title="item.name"
												@click="selectHistoryItem(item)"
											>
												<img
													v-if="item.media_type === 'IMAGE'"
													:src="item.path"
													alt=""
													class="h-10 w-10 shrink-0 rounded object-cover border border-slate-200 bg-slate-50"
												/>
												<span
													v-else
													class="flex h-10 w-10 shrink-0 items-center justify-center rounded border border-slate-200 bg-slate-50 text-[10px] text-slate-500"
												>
													{{ item.media_type === 'VIDEO' ? 'MP4' : 'DOC' }}
												</span>
												<span class="min-w-0 flex-1">
													<span class="block text-xs truncate">{{ item.name }}</span>
													<span
														v-if="selectedHistoryUuid === item.uuid"
														class="block text-[11px] font-medium text-primary"
													>
														✓ {{ $t('Selected') }}
													</span>
													<span v-else class="block text-[11px] text-slate-400">
														{{ $t('Click to use this file') }}
													</span>
												</span>
											</button>
											<button
												type="button"
												class="shrink-0 rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
												:title="$t('Delete')"
												:aria-label="$t('Delete')"
												@click.stop="deleteHistoryItem(item)"
											>
												<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
													<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"
														d="M4 7h16M10 11v6m4-6v6M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12M9 7V4h6v3" />
												</svg>
											</button>
										</li>
									</ul>
								</div>
								<p v-if="form.header.parameters[index].type === 'IMAGE'" class="text-left text-xs mt-2">
									{{ $t('Max file upload size is') }} <b>5MB</b> <br>
									{{ $t('Supported file extensions') }}: .png, .jpg, .jpeg
								</p>
								<p v-if="form.header.parameters[index].type === 'DOCUMENT'"
									class="text-left text-xs mt-2">{{ $t('Max file upload size is') }} <b>100MB</b> <br>
									{{ $t('Supported file extensions') }}: .pdf, .txt, .ppt, .doc, .xls, .docx, .pptx,
									.xlsx
								</p>
								<p v-if="form.header.parameters[index].type === 'VIDEO'" class="text-left text-xs mt-2">
									{{ $t('Max file upload size is') }} <b>16MB</b> <br>
									{{ $t('Supported file extensions') }}: .mp4
								</p>
							</div>
							<div v-if="form.errors['header.parameters.0.value']"
								class="form-error text-[#b91c1c] text-xs">{{ form.errors['header.parameters.0.value'] }}
							</div>
						</div>
					</div>
				</div>
				<div v-if="form.body.parameters.length > 0" class="bg-slate-100 p-3 mt-1 text-sm">
					<h2 class="flex items-center justify-between space-x-2 pb-2 border-b">
						<div class="flex items-center space-x-2">
							<span>{{ $t('Body variables') }}</span>
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 1024 1024">
								<path fill="currentColor"
									d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64zm67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344zM590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296c-44.096 0-108.992 44.736-148.48 101.504c0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04c67.84 0 107.904-43.648 147.456-100.416z" />
							</svg>
						</div>
						<span>
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
								<path fill="currentColor"
									d="M6.102 16.98c-1.074 0-1.648-1.264-.94-2.073l5.521-6.31a1.75 1.75 0 0 1 2.634 0l5.522 6.31c.707.809.133 2.073-.94 2.073H6.101Z" />
							</svg>
						</span>
					</h2>
					<div v-for="(item, index) in form.body.parameters" :key="index"
						class="mt-2 flex items-center space-x-4">
						<div class="w-[30%]">
							<span v-text="'{{' + (index + 1) + '}}'"></span>
						</div>
						<div class="w-full">
							<FormSelect v-model="form.body.parameters[index].selection" :options="variableOptions"
								:class="'sm:col-span-6'" />
						</div>
						<div class="w-full">
							<FormInput v-if="form.body.parameters[index].selection === 'static'"
								v-model="form.body.parameters[index].value" :required="true"
								:error="form.errors['body.parameters.0.value']" :type="'text'"
								:class="'sm:col-span-6'" />
							<FormSelect v-if="form.body.parameters[index].selection === 'dynamic'"
								v-model="form.body.parameters[index].value" :required="true"
								:error="form.errors['body.parameters.0.value']" :options="dynamicOptions"
								:class="'sm:col-span-6'" />
						</div>
					</div>
				</div>
				<div v-if="form.buttons.length > 0" class="bg-slate-100 p-3 mt-1 text-sm">
					<h2 class="flex items-center justify-between space-x-2 pb-2 border-b">
						<div class="flex items-center space-x-2">
							<span>{{ $t('Button variables') }}</span>
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 1024 1024">
								<path fill="currentColor"
									d="M512 64a448 448 0 1 1 0 896.064A448 448 0 0 1 512 64zm67.2 275.072c33.28 0 60.288-23.104 60.288-57.344s-27.072-57.344-60.288-57.344c-33.28 0-60.16 23.104-60.16 57.344s26.88 57.344 60.16 57.344zM590.912 699.2c0-6.848 2.368-24.64 1.024-34.752l-52.608 60.544c-10.88 11.456-24.512 19.392-30.912 17.28a12.992 12.992 0 0 1-8.256-14.72l87.68-276.992c7.168-35.136-12.544-67.2-54.336-71.296c-44.096 0-108.992 44.736-148.48 101.504c0 6.784-1.28 23.68.064 33.792l52.544-60.608c10.88-11.328 23.552-19.328 29.952-17.152a12.8 12.8 0 0 1 7.808 16.128L388.48 728.576c-10.048 32.256 8.96 63.872 55.04 71.04c67.84 0 107.904-43.648 147.456-100.416z" />
							</svg>
						</div>
						<span>
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
								<path fill="currentColor"
									d="M6.102 16.98c-1.074 0-1.648-1.264-.94-2.073l5.521-6.31a1.75 1.75 0 0 1 2.634 0l5.522 6.31c.707.809.133 2.073-.94 2.073H6.101Z" />
							</svg>
						</span>
					</h2>
					<div class="min-h-[300px]" v-for="(item, index) in form.buttons" :key="index">
						<div v-if="item.parameters.length > 0" class="mt-4 bg-slate-50 p-3">
							<div class="w-[100%] mb-1">
								<span>{{ $t('Label') }}: {{ item.text }}</span>
							</div>
							<div v-for="(value, key) in item.parameters" :key="key" class="flex items-center space-x-4">
								<div class="w-full">
									<FormSelect v-model="value.type" :name="$t('Button type')"
										:options="variableOptions" :class="'sm:col-span-6'" />
								</div>
								<div class="w-full">
									<FormInput v-if="value.type === 'static'" v-model="value.value" :name="$t('Value')"
										:required="true"
										:error="form.errors['buttons.' + index + '.parameters.0.value']" :type="'text'"
										:class="'sm:col-span-6'" />
									<FormSelect v-if="value.type === 'dynamic'" v-model="value.value"
										:name="$t('Value')" :required="true"
										:error="form.errors['buttons.' + index + '.parameters.0.value']"
										:options="dynamicOptions" :class="'sm:col-span-6'" />
								</div>
							</div>
						</div>
					</div>
				</div>
				<div v-if="submitEnabled" class="flex items-center justify-end space-x-3 mt-3">
					<div v-if="displayCancelBtn">
						<Link href="/campaigns"
							class="block rounded-md px-3 py-2 text-sm text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-slate-200">
							{{ $t('Cancel') }}
						</Link>
					</div>
					<div>
						<button type="submit"
							class="rounded-md px-3 py-2 text-sm text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary"
							:disabled="isLoading">
							<span v-if="!isLoading">{{ sendText ? sendText : $t('Save') }}</span>
							<svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
								<path fill="currentColor"
									d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z"
									opacity=".5" />
								<path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
									<animateTransform attributeName="transform" dur="1s" from="0 12 12"
										repeatCount="indefinite" to="360 12 12" type="rotate" />
								</path>
							</svg>
						</button>
					</div>
				</div>
			</div>
		</form>
		<div class="md:w-[50%] py-20 flex justify-center chat-bg" :class="isCampaignFlow ? 'px-20' : 'px-10'">
			<div>
				<WhatsappTemplate :parameters="form" :visible="form.template ? true : false"
					:placeholder="!headerPreview" />
			</div>
		</div>
	</div>
</template>
