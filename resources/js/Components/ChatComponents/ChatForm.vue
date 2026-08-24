<script setup>
import { useTrans } from '@/Composables/useTrans'
import axios from 'axios'
import MicRecorder from 'mic-recorder-to-mp3-fixed'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, watchEffect } from 'vue'
import Chat24HourComposeBanner from '@/Components/ChatComponents/Chat24HourComposeBanner.vue'
import ChatAttachMenu from '@/Components/ChatComponents/ChatAttachMenu.vue'
import UploadProgressIndicator from '@/Components/ChatComponents/UploadProgressIndicator.vue'
import { initUploadQueue } from '@/Composables/uploadQueue'
import { splitIntoBatches } from '@/Composables/uploadBatches'
import ShortcutsDropdown from '@/Components/ChatComponents/ShortcutsDropdown.vue'
import LocationPicker from '@/Components/LocationPicker.vue'
import { usePage } from '@inertiajs/vue3'
import EmojiPicker from 'vue3-emoji-picker'
import 'vue3-emoji-picker/css'
import { toast } from 'vue3-toastify'
import 'vue3-toastify/dist/index.css'

const trans = useTrans()

// الطابور مشترك على مستوى الوحدة: يبقى حيّاً بعد أن يترك الموظّف هذه المحادثة،
// فيكمل الرفع بدل أن يموت مع المكوّن.
const uploads = initUploadQueue({ post: (url, data, config) => axios.post(url, data, config) })
const uploadsExpanded = ref(false)

// المؤشّر خاصّ بالمحادثة المعروضة: يختفي عند الانتقال ويعود عند الرجوع إليها.
const currentUploadJobs = computed(() => uploads.jobsFor(props.contact?.uuid))
const currentUploadPercent = computed(() => uploads.percentFor(props.contact?.uuid))
const currentUploadFileCount = computed(() => uploads.fileCountFor(props.contact?.uuid))
const recorder = ref(null)
const props = defineProps(['contact', 'chatLimitReached', 'simpleForm'])
const processingForm = ref(null)
const processingAISuggestions = ref(false)
const formTextInput = ref(null)
const isRecording = ref(false)
const mediaRecorder = ref(null)
const audioChunks = ref([])
const recordingTime = ref(0)
const timerInterval = ref(null)
const playbackTime = ref(0)
const playbackInterval = ref(null)
const audioDuration = ref(0)
const audioPreviewUrl = ref(null)
const isPlaying = ref(false)
const audioPlayer = ref(null)
const isAudioRecording = ref(false)
const form = ref({
	uuid: props.contact.uuid,
	message: null,
	type: null,
	file: null,
})
const form2 = ref({
	uuid: props.contact.uuid,
	message: null,
	type: null,
	file: null,
})
const emojiPicker = ref(false)
const emojiPickerRef = ref(null)

watchEffect(() => {
	form.value.uuid = props.contact.uuid
	form2.value.uuid = props.contact.uuid
})

watch(
	() => props.contact?.uuid,
	(newUuid, oldUuid) => {
		// Prevent cross-contact audio sends when switching chats while a recording/preview exists.
		if (newUuid && oldUuid && newUuid !== oldUuid) {
			deleteRecording()
			form2.value.uuid = newUuid
		}
	},
)

const emit = defineEmits(['response', 'viewTemplate', 'newMessage', 'removeMessage'])
const isLoading = ref(false)
const sendingAuthTemplate = ref(false)

const viewTemplate = () => {
	emit('viewTemplate')
}

const sendAuthTemplate = async () => {
	if (!props.contact?.uuid || sendingAuthTemplate.value) return
	sendingAuthTemplate.value = true
	try {
		const { data } = await axios.post(`/chat/${props.contact.uuid}/send/auth-template`, {})
		if (data?.success) {
			toast.success(trans('Auth template sent successfully'))
		} else {
			toast.error(data?.message || trans('Something went wrong'))
		}
	} catch (err) {
		const msg = err.response?.data?.message
		if (err.response?.status === 400 && msg) {
			toast.error(trans('Please select Auth Template in Settings → General Settings first'))
		} else {
			toast.error(msg || trans('Failed to send auth template'))
		}
	} finally {
		sendingAuthTemplate.value = false
	}
}
/**
 * طلب موقع العميل — رسالة تفاعلية بزرّ «إرسال الموقع» في واتساب.
 *
 * نستخدم نصّ صندوق الكتابة إن كتب الموظّف شيئاً، وإلا فجملة افتراضية: النقرة
 * الواحدة هي الحالة الشائعة، والتخصيص متاح بلا خطوة إضافية.
 *
 * لا نضيف فقاعة متفائلة كما تفعل sendMessage: تلك تعتمد على tempMessageId
 * لتستبدل الفقاعة عند وصول البثّ، وهذا المسار لا يمرّره فتبقى الفقاعة مكرّرة.
 * الرسالة تظهر عند وصول البثّ خلال أجزاء من الثانية.
 */
const requestingLocation = ref(false)
const requestLocation = async () => {
	if (!isInboundChatWithin24Hours.value || requestingLocation.value) return

	const typed = (formTextInput.value ?? '').trim()
	const body = typed || trans('Please share your location so we can reach you accurately.')

	requestingLocation.value = true
	try {
		const { data } = await axios.post(`/chat/${props.contact.uuid}/request-location`, { body })
		if (data?.success === false) {
			toast.error(trans(data.message || 'Something went wrong'))
		} else {
			if (typed) formTextInput.value = null
			toast.success(trans('Location request sent'))
		}
	} catch (error) {
		toast.error(trans(error.response?.data?.message || 'Something went wrong'))
	} finally {
		requestingLocation.value = false
		await nextTick()
		textInputRef.value?.focus()
	}
}

/**
 * إرسال موقع النشاط التجاري إلى العميل — عكس requestLocation أعلاه.
 *
 * الموقع المحفوظ يُرسَل بعلَم use_organization_location لا بإحداثيات من هنا:
 * الخادم هو مصدر الحقيقة، فلو عدّله زميل في الإعدادات بينما الصفحة مفتوحة
 * أُرسل الجديد لا نسخة قديمة عالقة في الواجهة.
 */
const showLocationModal = ref(false)
const sendingLocation = ref(false)
const pickedLocation = ref(null)

const googleMapsApiKey = computed(() => {
	const config = usePage().props.config ?? []
	return config.find((item) => item.key === 'google_maps_api_key')?.value ?? ''
})

const organizationLocation = computed(() => {
	const organization = usePage().props.organization
	if (!organization?.address) return null

	let address
	try {
		address = typeof organization.address === 'string' ? JSON.parse(organization.address) : organization.address
	} catch (error) {
		return null
	}

	const lat = Number(address?.latitude)
	const lng = Number(address?.longitude)
	if (!Number.isFinite(lat) || !Number.isFinite(lng) || (lat === 0 && lng === 0)) return null

	return {
		latitude: lat,
		longitude: lng,
		name: organization.name ?? '',
		address: [address.street, address.city, address.state, address.zip, address.country]
			.filter((part) => (part ?? '').toString().trim() !== '')
			.map((part) => part.toString().trim())
			.join('، '),
	}
})

const openLocationModal = () => {
	if (!isInboundChatWithin24Hours.value) return
	pickedLocation.value = organizationLocation.value
		? { ...organizationLocation.value }
		: { latitude: null, longitude: null, name: '', address: '' }
	showLocationModal.value = true
}

const closeLocationModal = () => {
	showLocationModal.value = false
	pickedLocation.value = null
}

const useBusinessLocation = () => {
	if (!organizationLocation.value) return
	pickedLocation.value = { ...organizationLocation.value }
}

const isPickedLocationValid = computed(() => {
	const value = pickedLocation.value
	if (!value) return false
	const lat = Number(value.latitude)
	const lng = Number(value.longitude)
	return Number.isFinite(lat) && Number.isFinite(lng) && !(lat === 0 && lng === 0)
})

/** هل المختار هو الموقع المحفوظ نفسه؟ عندها نترك الخادم يحلّه بنفسه. */
const isSavedBusinessLocation = computed(() => {
	const saved = organizationLocation.value
	const picked = pickedLocation.value
	if (!saved || !picked) return false
	return Number(saved.latitude) === Number(picked.latitude)
		&& Number(saved.longitude) === Number(picked.longitude)
		&& (saved.name ?? '') === (picked.name ?? '')
		&& (saved.address ?? '') === (picked.address ?? '')
})

const sendLocation = async () => {
	if (!isPickedLocationValid.value || sendingLocation.value || !isInboundChatWithin24Hours.value) return

	const payload = isSavedBusinessLocation.value
		? { use_organization_location: true }
		: {
			latitude: pickedLocation.value.latitude,
			longitude: pickedLocation.value.longitude,
			name: pickedLocation.value.name || '',
			address: pickedLocation.value.address || '',
		}

	sendingLocation.value = true
	try {
		const { data } = await axios.post(`/chat/${props.contact.uuid}/send-location`, payload)
		if (data?.success === false) {
			toast.error(trans(data.message || 'Something went wrong'))
		} else {
			toast.success(trans('Location sent'))
			closeLocationModal()
		}
	} catch (error) {
		toast.error(trans(error.response?.data?.message || 'Something went wrong'))
	} finally {
		sendingLocation.value = false
	}
}

const appendMessageIntoBody = (form) => {
	emit('newMessage', form)
}
const sendMessage = async () => {
	if (!isInboundChatWithin24Hours.value) return
	const messageText = (formTextInput.value ?? '').trim()
	form.value.message = messageText || null
	processingForm.value = true

	if (form.value.file) {
		if (form.value.type === 'text' || !form.value.type) {
			const mime = form.value.file.type || ''
			if (mime.startsWith('image/')) form.value.type = 'image'
			else if (mime.startsWith('video/')) form.value.type = 'video'
			else if (mime.startsWith('audio/')) form.value.type = 'audio'
			else form.value.type = 'document'
		}
	} else if (!messageText) {
		processingForm.value = false
		return
	}

	if (messageText || form.value.file != null) {
		const formData = new FormData()
		const tempMessageId = crypto.randomUUID()
		form.value.tempMessageId = tempMessageId
		appendMessageIntoBody(form)
		if (messageText) {
			formData.append('message', messageText)
		}
		formData.append('type', form.value.type)
		formData.append('uuid', form.value.uuid)
		formData.append('tempMessageId', form.value.tempMessageId)

		if (form.value.file) {
			formData.append('file', form.value.file)
		}

		try {
			const response = await axios.post('/chats', formData)

			if (isAudioRecording.value == true) {
				await sendAudioMessage()
			}

			form.value.message = null
			formTextInput.value = null
			form.value.file = null

			processingForm.value = false
		} catch (error) {
			processingForm.value = false
			const failedTempId = form.value.tempMessageId
			form.value.file = null
			const msg = error.response?.data?.message
			if (msg) {
				toast.error(trans(msg))
			}
			if (failedTempId) {
				emit('removeMessage', failedTempId)
			}
		}
	} else {
		if (isAudioRecording.value == true) {
			await sendAudioMessage()
		}

		processingForm.value = false
	}

	await nextTick()
	textInputRef.value?.focus()
}

const sendAudioMessage = async () => {
	if (!isInboundChatWithin24Hours.value) return
	const tempMessageId = crypto.randomUUID()
	form2.value.tempMessageId = tempMessageId
	form2.value.uuid = props.contact.uuid

	const formData = new FormData()
	formData.append('type', form2.value.type)
	formData.append('uuid', form2.value.uuid)

	if (form2.value.file) {
		formData.append('file', form2.value.file)
		formData.append('tempMessageId', form2.value.tempMessageId)
		appendMessageIntoBody(form2)
	}

	try {
		const response = await axios.post('/chats', formData)

		if (isAudioRecording.value == true) {
			deleteRecording()
		}
	} catch (error) {
		// Handle the error
		// console.error('Error:', error);
	}
}

const textInputRef = ref(null)
const adjustTextareaHeight = () => {
	const textInput = textInputRef.value
	textInput.style.height = 'auto'
	textInput.style.height = textInput.scrollHeight + 'px'
}

const handleEnterKey = (event) => {
	// عند فتح قائمة الاختصارات نطبّق العنصر المُحدد بدل إرسال النص المكتوب
	if (shortcutsOpen.value) {
		applyActiveShortcut()
		return
	}
	if (formTextInput.value != null && formTextInput.value.trim() != '') {
		sendMessage()
	}
}

// ==== نظام الاختصارات (Quick replies) ====
const shortcuts = ref([])
const activeShortcutIndex = ref(0)
const shortcutsDismissed = ref(false)

const loadShortcuts = async () => {
	try {
		const res = await axios.get('/shortcuts/available')
		shortcuts.value = res?.data?.shortcuts ?? []
	} catch {
		shortcuts.value = []
	}
}

const filteredShortcuts = computed(() => {
	const value = formTextInput.value
	if (!value || value[0] !== '/') return []
	const query = value.slice(1).trim().toLowerCase()
	if (query === '') return shortcuts.value
	return shortcuts.value.filter(
		(s) => (s.command || '').toLowerCase().includes(query)
			|| (s.message || '').toLowerCase().includes(query),
	)
})

const shortcutsOpen = computed(() =>
	!shortcutsDismissed.value
	&& filteredShortcuts.value.length > 0
	&& (formTextInput.value || '')[0] === '/'
)

watch(filteredShortcuts, () => {
	activeShortcutIndex.value = 0
})

// إعادة إظهار القائمة عند تعديل النص بعد إغلاقها يدوياً
watch(formTextInput, () => {
	if ((formTextInput.value || '')[0] !== '/') {
		shortcutsDismissed.value = false
	}
})

const moveShortcut = (delta) => {
	const len = filteredShortcuts.value.length
	if (len === 0) return
	activeShortcutIndex.value = (activeShortcutIndex.value + delta + len) % len
}

const setActiveShortcut = (index) => {
	activeShortcutIndex.value = index
}

const applyShortcut = (shortcut) => {
	if (!shortcut) return
	formTextInput.value = shortcut.message
	nextTick(() => {
		sendMessage()
	})
}

const applyActiveShortcut = () => {
	applyShortcut(filteredShortcuts.value[activeShortcutIndex.value])
}

// معالجة مفاتيح التنقل عندما تكون قائمة الاختصارات مفتوحة
const onComposeKeydown = (event) => {
	if (!shortcutsOpen.value) return
	if (event.key === 'ArrowDown') {
		event.preventDefault()
		moveShortcut(1)
	} else if (event.key === 'ArrowUp') {
		event.preventDefault()
		moveShortcut(-1)
	} else if (event.key === 'Escape') {
		event.preventDefault()
		shortcutsDismissed.value = true
	}
}

const nowTick = ref(null)
let messagingWindowTimer = null

const parseUtcDate = (value) => {
	if (!value) return null
	if (value instanceof Date) return value
	const normalized = typeof value === 'string' && !value.endsWith('Z') && !value.includes('+')
		? value.replace(' ', 'T') + 'Z'
		: value
	const parsed = new Date(normalized)
	return Number.isNaN(parsed.getTime()) ? null : parsed
}

const isInboundChatWithin24Hours = computed(() => {
	nowTick.value

	const iso = props.contact?.last_inbound_chat_created_at_iso
		?? props.contact?.last_inbound_chat?.created_at_iso
		?? props.contact?.last_inbound_chat?.created_at

	if (iso) {
		const lastInbound = parseUtcDate(iso)
		if (lastInbound) {
			return Date.now() - lastInbound.getTime() < 24 * 60 * 60 * 1000
		}
	}

	if (typeof props.contact?.is_messaging_window_open === 'boolean') {
		return props.contact.is_messaging_window_open
	}

	return false
})

const handleFileUpload = (event) => {
	if (!isInboundChatWithin24Hours.value) {
		event.target.value = ''
		return
	}
	const file = event.target.files?.[0]
	if (!file) {
		event.target.value = ''
		return
	}
	// Reset input so the same (or another) file can be selected again — otherwise 'change' may not fire next time
	event.target.value = ''

	// كان هنا FileReader يقرأ الملف كاملاً إلى base64 ثم يُرمى الناتج — لم
	// يكن يُستعمل في أي موضع، وonload مجرّد مُشغِّل للإرسال. الكلفة نسخة
	// ثانية من الملف في الذاكرة وتأخير قبل أن يبدأ الرفع أصلاً.
	form.value.file = file
	sendMessage()
}

/**
 * ====== اللصق والسحب والإفلات ======
 *
 * نفس ما يفعله واتساب: صورة أو مستند في الحافظة يُلصق في المحادثة، أو يُسحب
 * إليها من سطح المكتب. الفارق عن زرّ الاختيار أن هذين قد يقعان سهواً — لقطة
 * شاشة في الحافظة تُلصق بضغطة — فيمرّان بمعاينة قبل الإرسال، بخلاف الزرّ الذي
 * يعني اختياراً مقصوداً.
 */

/** ما تقبله واتساب فعلاً. أي امتداد خارجها تردّه Meta برسالة غامضة. */
const ACCEPTED_MEDIA = {
	image: { extensions: ['jpg', 'jpeg', 'png'], maxBytes: 5 * 1024 * 1024 },
	video: { extensions: ['mp4'], maxBytes: 16 * 1024 * 1024 },
	audio: { extensions: ['mp3', 'ogg'], maxBytes: 16 * 1024 * 1024 },
	document: {
		extensions: ['txt', 'pdf', 'ppt', 'doc', 'xls', 'docx', 'pptx', 'xlsx'],
		maxBytes: 100 * 1024 * 1024,
	},
}

/**
 * الحدّ الذي يقبله الخادم فعلاً (upload_max_filesize / post_max_size).
 *
 * أكبر منه يرفضه PHP قبل بلوغ Laravel، فتصل حمولة بلا ملف ولا خطأ — يظنّ
 * المستخدم أن الإرسال نجح ولا يصل شيء. نردّه هنا برسالة تذكر الحدّ.
 */
const serverMaxUploadBytes = computed(() => usePage().props.max_upload_bytes ?? Infinity)

/**
 * سقف الطلب الواحد. مجموع الدفعة يُقاس به لا سقفُ الملف — والفرق هو ما جعل
 * ثلاثة ملفات مقبولة فرادى تقف عند ٢٪ حين أُرسلت معاً.
 */
const serverMaxPostBytes = computed(() => usePage().props.max_post_bytes ?? Infinity)

const isDraggingFile = ref(false)
const pendingAttachments = ref([])
/** المرفق المعروض كبيراً. شريط المصغّرات أسفله ينقّل بينها. */
const activeAttachmentIndex = ref(0)
const attachmentCaption = ref('')
let dragDepth = 0

/** نوع الملف من امتداده لا من MIME: المتصفّحات تختلف في MIME الملفات المكتبية. */
const resolveMediaType = (file) => {
	const extension = (file.name.split('.').pop() ?? '').toLowerCase()
	for (const [type, spec] of Object.entries(ACCEPTED_MEDIA)) {
		if (spec.extensions.includes(extension)) return type
	}

	// لقطة الشاشة تصل بلا اسم ملف ذي امتداد، فنرجع إلى MIME.
	const mime = file.type || ''
	if (mime.startsWith('image/')) return 'image'
	if (mime.startsWith('video/')) return 'video'
	if (mime.startsWith('audio/')) return 'audio'
	return null
}

const humanSize = (bytes) => `${Math.round(bytes / (1024 * 1024))} MB`

/** @returns {{file: File, type: string, previewUrl: ?string}|null} */
const buildAttachment = (file) => {
	const type = resolveMediaType(file)
	if (!type) {
		toast.error(trans('This file type is not supported.'))
		return null
	}

	// حدّ الخادم أوّلاً: هو السقف الحقيقي مهما سمحت واتساب.
	if (file.size > serverMaxUploadBytes.value) {
		toast.error(trans('File is larger than the :size limit.').replace(':size', humanSize(serverMaxUploadBytes.value)))
		return null
	}

	const spec = ACCEPTED_MEDIA[type]
	// الصور فوق حدّ واتساب يضغطها الخادم، فلا نردّها هنا.
	if (type !== 'image' && file.size > spec.maxBytes) {
		toast.error(trans('File is larger than the :size limit.').replace(':size', humanSize(spec.maxBytes)))
		return null
	}

	return {
		file,
		type,
		previewUrl: type === 'image' ? URL.createObjectURL(file) : null,
	}
}

const releasePreviews = () => {
	pendingAttachments.value.forEach((item) => {
		if (item.previewUrl) URL.revokeObjectURL(item.previewUrl)
	})
}

const closeAttachmentPreview = () => {
	releasePreviews()
	pendingAttachments.value = []
	attachmentCaption.value = ''
	activeAttachmentIndex.value = 0
}

const activeAttachment = computed(() => pendingAttachments.value[activeAttachmentIndex.value] ?? null)

const queueAttachments = (files) => {
	if (!isInboundChatWithin24Hours.value || !files?.length) return

	const accepted = Array.from(files).map(buildAttachment).filter(Boolean)
	if (!accepted.length) return

	// نُضيف لا نستبدل: لصق ملفٍ ثانٍ والمعاينة مفتوحة يعني «وهذا أيضاً»،
	// والاستبدال كان سيُسقط الأول بلا إنذار.
	pendingAttachments.value = [...pendingAttachments.value, ...accepted]

	// النصّ المكتوب يصير تعليقاً على المرفق، كما يفعل واتساب حين تلصق ومعك نصّ.
	if (attachmentCaption.value === '') {
		attachmentCaption.value = (formTextInput.value ?? '').trim()
	}
}

const removeAttachment = (index) => {
	const [removed] = pendingAttachments.value.splice(index, 1)
	if (removed?.previewUrl) URL.revokeObjectURL(removed.previewUrl)

	if (!pendingAttachments.value.length) {
		closeAttachmentPreview()
		return
	}

	// المعروض قد يكون هو المحذوف أو ما بعده، فنُعيد الفهرس داخل الحدود.
	activeAttachmentIndex.value = Math.min(activeAttachmentIndex.value, pendingAttachments.value.length - 1)
}

/**
 * الإرسال تتابعاً لا تفرّعاً: الخادم يبني رسالة لكل ملف، وإرسالها معاً يقلب
 * ترتيبها في المحادثة حسب أيّها انتهى أولاً.
 */
/**
 * تسليم المرفقات إلى طابور الخلفية.
 *
 * كانت تنتظر بـawait والنافذة مفتوحة فوق الشاشة، فيبقى الموظّف محبوساً حتى
 * يرتفع آخر بايت: لا يفتح محادثة أخرى ولا يردّ على عميل ينتظر. الآن تُنشئ
 * الفقاعات التفاؤلية، تُسلّم الملفات للطابور، وتُغلق النافذة فوراً — والرفع
 * يكمل في الخلفية ويتابعه المؤشّر عند صندوق الرسائل.
 */
const sendAttachments = () => {
	if (!pendingAttachments.value.length) return
	if (!isInboundChatWithin24Hours.value) return

	const queue = [...pendingAttachments.value]
	const caption = attachmentCaption.value.trim()

	// فقاعات تفاؤلية بالترتيب أوّلاً: ترتيب العرض يتحدّد هنا لا بترتيب وصول
	// الردود، فتظهر الملفات كما اختارها المرسِل مهما تفاوتت أحجامها.
	const tempIds = queue.map(() => crypto.randomUUID())
	queue.forEach((item, index) => {
		form.value.file = item.file
		form.value.type = item.type
		// التعليق يُرفق بالأول وحده — تكراره على كل ملف يُغرق المحادثة.
		form.value.message = index === 0 && caption !== '' ? caption : null
		form.value.tempMessageId = tempIds[index]
		appendMessageIntoBody(form)
	})

	// طلب واحد لكل ما يسع في طلب: كل ملف كان يستهلك رحلة HTTP كاملة، فثلاثة
	// ملفات ثلاث رحلات متعاقبة. لكن الطلب محكوم بـpost_max_size، فنُقسّم على
	// قدره بدل أن نُرسل حمولة يرفضها الخادم قبل أن تبلغ PHP — وهو رفضٌ يقف
	// بالمؤشّر بلا رسالة.
	const batches = splitIntoBatches(queue, serverMaxPostBytes.value)
	let offset = 0

	for (const batch of batches) {
		const batchTempIds = tempIds.slice(offset, offset + batch.length)
		// التعليق مع الدفعة الأولى وحدها — تكراره يُغرق المحادثة.
		const batchCaption = offset === 0 ? caption : ''
		offset += batch.length

		uploads.enqueue({
			contactUuid: form.value.uuid,
			contactName: props.contact?.full_name || props.contact?.phone || '',
			files: batch.map((item) => ({ file: item.file, type: item.type })),
			caption: batchCaption,
			tempIds: batchTempIds,
			// الإخفاق يُزيل الفقاعات: إبقاؤها يوهم الموظّف أن الملف وصل العميل.
			onFailure: (ids) => ids.forEach((id) => emit('removeMessage', id)),
		})
	}

	formTextInput.value = null
	closeAttachmentPreview()

	form.value.file = null
	form.value.type = 'text'
	form.value.message = null
	form.value.tempMessageId = null
}

/** إعادة محاولة مهمّة أخفقت: فقاعات جديدة ثم تسليمها للطابور من جديد. */
const retryUpload = (job) => {
	// الطلب الأصلي من المخزن لا من المهمّة: ملفاته غير ملفوفة بوكيل تفاعلي.
	const request = uploads.requestFor(job.id)
	const tempIds = job.fileNames.map(() => crypto.randomUUID())

	job.fileNames.forEach((name, index) => {
		const source = request?.files?.[index]
		if (!source) return

		form.value.file = source.file
		form.value.type = source.type
		form.value.message = index === 0 && job.caption !== '' ? job.caption : null
		form.value.tempMessageId = tempIds[index]
		appendMessageIntoBody(form)
	})

	form.value.file = null
	form.value.type = 'text'
	form.value.message = null
	form.value.tempMessageId = null

	uploads.retry(job.id, tempIds)
}

/**
 * اللصق. نتدخّل فقط حين تحمل الحافظة ملفاً — لصق النصّ يمرّ كما هو، وإلا
 * تعطّل أبسط استخدام للملحن.
 */
const handlePaste = (event) => {
	const files = Array.from(event.clipboardData?.files ?? [])
	if (!files.length) return

	event.preventDefault()
	queueAttachments(files)
}

/**
 * السحب والإفلات على مساحة المحادثة كلّها لا على الملحن وحده — كما في واتساب.
 * dragDepth يعالج تعاقب dragenter/dragleave على العناصر المتداخلة: بدونه
 * تختفي الطبقة كلّما مرّ المؤشّر فوق عنصر داخلي.
 */
const isFileDrag = (event) => Array.from(event.dataTransfer?.types ?? []).includes('Files')

const handleDragEnter = (event) => {
	if (!isFileDrag(event) || !isInboundChatWithin24Hours.value) return
	event.preventDefault()
	dragDepth += 1
	isDraggingFile.value = true
}

const handleDragOver = (event) => {
	if (!isFileDrag(event) || !isInboundChatWithin24Hours.value) return
	event.preventDefault()
	event.dataTransfer.dropEffect = 'copy'
}

const handleDragLeave = (event) => {
	if (!isFileDrag(event)) return
	dragDepth = Math.max(0, dragDepth - 1)
	if (dragDepth === 0) isDraggingFile.value = false
}

const handleDrop = (event) => {
	if (!isFileDrag(event)) return
	event.preventDefault()
	dragDepth = 0
	isDraggingFile.value = false
	queueAttachments(event.dataTransfer?.files)
}

const getAcceptedFileTypes = () => {
	switch (form.value.type) {
		case 'image':
			return '.jpg, .jpeg, .png'
		case 'document':
			return '.txt, .pdf, .ppt, .doc, .xls, .docx, .pptx, .xlsx'
		case 'audio':
			return '.mp3, .ogg'
		case 'video':
			return '.mp4'
		default:
			return '' // Empty string allows all file types
	}
}

const handleAttachAction = (action) => {
	switch (action) {
		case 'image':
		case 'document':
		case 'video':
		case 'audio':
			form.value.type = action
			nextTick(() => document.getElementById('file-upload')?.click())
			break
		case 'request-location':
			requestLocation()
			break
		case 'send-location':
			openLocationModal()
			break
		case 'templates':
			viewTemplate()
			break
		case 'ai-assist':
			getSuggestion()
			break
	}
}

const toggleEmojiPicker = (e) => {
	e.stopPropagation()
	emojiPicker.value = !emojiPicker.value
}

const closeEmojiPicker = () => {
	emojiPicker.value = false
}

const addEmoji = (emoji) => {
	const textarea = textInputRef.value
	const currentValue = formTextInput.value || ''
	const start = textarea.selectionStart || 0
	const end = textarea.selectionEnd || 0

	const newText = currentValue.substring(0, start) + emoji.i + currentValue.substring(end)

	formTextInput.value = newText

	// Focus the textarea and place the cursor at the correct position
	setTimeout(() => {
		textarea.focus()
		textarea.setSelectionRange(start + emoji.i.length, start + emoji.i.length)
	}, 0)
}

const emojiPickerContains = (target) => {
	const ref = emojiPickerRef.value
	if (!ref) return false
	if (Array.isArray(ref)) return ref.some((el) => el?.contains?.(target))
	return ref.contains?.(target) ?? false
}

const handleClickOutside = (event) => {
	if (
		emojiPicker.value &&
		!emojiPickerContains(event.target) &&
		textInputRef.value &&
		!textInputRef.value.contains(event.target)
	) {
		closeEmojiPicker()
	}
}

const startRecording = async () => {
	try {
		isAudioRecording.value = true
		await recorder.value.start()
		isRecording.value = true
		startTimer()
	} catch (error) {
		console.error('Error accessing microphone:', error)
	}
}

const stopRecording = async () => {
	if (isRecording.value) {
		try {
			const [buffer, blob] = await recorder.value.stop().getMp3()
			const audioFile = new File(buffer, 'audio-message.mp3', {
				type: blob.type,
				lastModified: Date.now(),
			})

			audioPreviewUrl.value = URL.createObjectURL(blob)
			form2.value.type = 'audio'
			form2.value.file = audioFile

			isRecording.value = false
			stopTimer()
		} catch (error) {
			console.error('Error stopping recording:', error)
		}
	}
}

const deleteRecording = () => {
	if (audioPreviewUrl.value) {
		URL.revokeObjectURL(audioPreviewUrl.value)
	}
	audioPreviewUrl.value = null
	recordingTime.value = 0
	playbackTime.value = 0
	audioDuration.value = 0
	audioChunks.value = []
	form2.value.type = null
	form2.value.file = null
	stopPlaybackTimer()
}
const keepFocus = () => {
	textInputRef.value.focus()
}
const togglePlayback = () => {
	if (!audioPlayer.value) return

	if (isPlaying.value) {
		audioPlayer.value.pause()
		stopPlaybackTimer()
	} else {
		audioPlayer.value.play()
		startPlaybackTimer()
	}
	isPlaying.value = !isPlaying.value
}

const startTimer = () => {
	recordingTime.value = 0
	timerInterval.value = setInterval(() => {
		recordingTime.value++
	}, 1000)
}

const startPlaybackTimer = () => {
	playbackTime.value = 0
	playbackInterval.value = setInterval(() => {
		if (audioPlayer.value) {
			playbackTime.value = Math.floor(audioPlayer.value.currentTime)
		}
	}, 100)
}

const stopTimer = () => {
	if (timerInterval.value) {
		clearInterval(timerInterval.value)
		timerInterval.value = null
	}
}

const stopPlaybackTimer = () => {
	if (playbackInterval.value) {
		clearInterval(playbackInterval.value)
		playbackInterval.value = null
	}
}

const formatTime = (seconds) => {
	const minutes = Math.floor(seconds / 60)
	const remainingSeconds = seconds % 60
	return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`
}

const handleAudioLoaded = () => {
	if (audioPlayer.value) {
		audioDuration.value = Math.floor(audioPlayer.value.duration)
	}
}

const getSuggestion = async () => {
	processingAISuggestions.value = true
	try {
		const response = await axios.get('/automation/chat/suggestion', {
			params: {
				contact: props.contact.uuid,
			},
		})

		if (response.data.success) {
			form.value.type = 'text'
			formTextInput.value = response.data.data.text
		} else {
			console.error('Failed to get suggestion:', response.data.message)
		}
	} catch (error) {
		console.error('Error getting suggestion:', error.response?.data?.message || error.message)
	} finally {
		processingAISuggestions.value = false
	}
}

onMounted(() => {
	document.addEventListener('click', handleClickOutside)
	// على المستند لا على الملحن: واتساب يقبل اللصق والإفلات في مساحة المحادثة
	// كلّها. والملحن لا يُركَّب إلا مع محادثة مفتوحة، فالنطاق صحيح.
	document.addEventListener('paste', handlePaste)
	document.addEventListener('dragenter', handleDragEnter)
	document.addEventListener('dragover', handleDragOver)
	document.addEventListener('dragleave', handleDragLeave)
	document.addEventListener('drop', handleDrop)
	loadShortcuts()
	recorder.value = new MicRecorder({
		bitRate: 128,
	})
	nowTick.value = Date.now()
	messagingWindowTimer = setInterval(() => {
		nowTick.value = Date.now()
	}, 60000)
})

onBeforeUnmount(() => {
	document.removeEventListener('click', handleClickOutside)
	document.removeEventListener('paste', handlePaste)
	document.removeEventListener('dragenter', handleDragEnter)
	document.removeEventListener('dragover', handleDragOver)
	document.removeEventListener('dragleave', handleDragLeave)
	document.removeEventListener('drop', handleDrop)
	releasePreviews()
	stopTimer()
	stopPlaybackTimer()
	if (messagingWindowTimer) {
		clearInterval(messagingWindowTimer)
	}
	if (audioPreviewUrl.value) {
		URL.revokeObjectURL(audioPreviewUrl.value)
	}
})
</script>
<template>
	<div v-if="props.chatLimitReached" class="flex justify-center items-center w-full px-6 md:px-4">
		<div class="flex items-start space-x-4 bg-orange-100 rounded-lg p-2 mb-2 px-4">
			<span class="text-red-700">
				<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 36 36">
					<path fill="currentColor"
						d="M18 21.32a1.3 1.3 0 0 0 1.3-1.3V14a1.3 1.3 0 1 0-2.6 0v6a1.3 1.3 0 0 0 1.3 1.32Z"
						class="clr-i-outline clr-i-outline-path-1" />
					<circle cx="17.95" cy="24.27" r="1.5" fill="currentColor"
						class="clr-i-outline clr-i-outline-path-2" />
					<path fill="currentColor"
						d="M30.33 25.54L20.59 7.6a3 3 0 0 0-5.27 0L5.57 25.54A3 3 0 0 0 8.21 30h19.48a3 3 0 0 0 2.64-4.43Zm-1.78 1.94a1 1 0 0 1-.86.49H8.21a1 1 0 0 1-.88-1.48l9.74-17.94a1 1 0 0 1 1.76 0l9.74 17.94a1 1 0 0 1-.02.99Z"
						class="clr-i-outline clr-i-outline-path-3" />
					<path fill="none" d="M0 0h36v36H0z" />
				</svg>
			</span>
			<div>
				<div class="text-sm">{{ $t('Maximum chat limit reached') }}</div>
				<div class="text-sm">
					{{ $t(
						'You have reached the maximum chat limit for your subscription! Please upgrade to send/receive more messages',
					)
					}}
				</div>
			</div>
		</div>
	</div>
	<form v-if="
		simpleForm &&
		!props.chatLimitReached &&
		!props.contact.is_blocked
	" @submit.prevent="sendMessage()" class="relative flex w-full flex-col px-2 md:px-10">
		<ShortcutsDropdown v-if="shortcutsOpen" :items="filteredShortcuts" :activeIndex="activeShortcutIndex"
			@hover="setActiveShortcut" @select="(i) => applyShortcut(filteredShortcuts[i])" />
		<div class="w-full overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
			<Chat24HourComposeBanner
				v-if="!isInboundChatWithin24Hours"
				:sending-auth-template="sendingAuthTemplate"
				@view-template="viewTemplate()"
				@send-auth-template="sendAuthTemplate()"
			/>
			<UploadProgressIndicator
				:jobs="currentUploadJobs"
				:percent="currentUploadPercent"
				:file-count="currentUploadFileCount"
				:expanded="uploadsExpanded"
				@toggle="uploadsExpanded = !uploadsExpanded"
				@retry="retryUpload"
				@cancel="(job) => uploads.cancel(job.id)"
				@dismiss="(job) => uploads.dismiss(job.id)"
			/>
			<div
				class="flex items-center py-4 md:py-2 pl-2 pr-2"
				:class="[
					processingForm ? 'bg-gray-200' : 'bg-white',
					!isInboundChatWithin24Hours ? 'pointer-events-none opacity-40' : '',
				]">
				<div class="absolute">
					<button type="button" @click="toggleEmojiPicker"> 😀 </button>
					<div v-if="emojiPicker" class="absolute left-0 bottom-full" ref="emojiPickerRef">
						<EmojiPicker :native="true" @select="addEmoji" />
					</div>
				</div>
				<textarea ref="textInputRef" @focus="form.type = 'text'" @keydown="onComposeKeydown" @keydown.enter.exact.prevent="handleEnterKey"
					class="w-full ml-3 outline-none resize-none text-sm md:text-base pl-6"
					:class="processingForm ? 'bg-gray-200' : 'bg-white'" v-model="formTextInput"
					@input="adjustTextareaHeight" type="text" rows="1" :autofocus="isInboundChatWithin24Hours"
					:placeholder="$t('Type your message...')" :disabled="processingForm || !isInboundChatWithin24Hours">
      </textarea>
				<input type="file" class="sr-only" :accept="getAcceptedFileTypes()" id="file-upload"
					@change="handleFileUpload($event)" />
				<div class="shrink-0 md:hidden">
					<ChatAttachMenu
						:show-ai-assist="false"
						:requesting-location="requestingLocation"
						:sending-location="sendingLocation"
						:disabled="!isInboundChatWithin24Hours"
						@action="handleAttachAction"
					/>
				</div>
				<div class="hidden md:contents">
				<label @click="form.type = 'image'" for="file-upload" class="text-slate-500 mr-2 cursor-pointer">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
						<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
							stroke-width="1.5">
							<path d="M6.5 8a2 2 0 1 0 4 0a2 2 0 0 0-4 0Zm14.427 1.99c-6.61-.908-12.31 4-11.927 10.51" />
							<path d="M3 13.066c2.78-.385 5.275.958 6.624 3.1" />
							<path
								d="M3 12c0-4.243 0-6.364 1.318-7.682C5.636 3 7.758 3 12 3c4.243 0 6.364 0 7.682 1.318C21 5.636 21 7.758 21 12c0 4.243 0 6.364-1.318 7.682C18.364 21 16.242 21 12 21c-4.243 0-6.364 0-7.682-1.318C3 18.364 3 16.242 3 12Z" />
						</g>
					</svg>
				</label>
				<label @click="form.type = 'document'" for="file-upload" class="text-slate-500 mr-1 cursor-pointer">
					<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
						<g fill="none" stroke="currentColor" stroke-width="1.5">
							<path
								d="M3 10c0-3.771 0-5.657 1.172-6.828C5.343 2 7.229 2 11 2h2c3.771 0 5.657 0 6.828 1.172C21 4.343 21 6.229 21 10v4c0 3.771 0 5.657-1.172 6.828C18.657 22 16.771 22 13 22h-2c-3.771 0-5.657 0-6.828-1.172C3 19.657 3 17.771 3 14v-4Z" />
							<path stroke-linecap="round" d="M8 10h8m-8 4h5" />
						</g>
					</svg>
				</label>
				<label @click="form.type = 'audio'" for="file-upload" class="text-slate-500 mr-1 cursor-pointer">
					<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 1024 1024">
						<path fill="currentColor"
							d="M842 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 140.3-113.7 254-254 254S258 594.3 258 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 168.7 126.6 307.9 290 327.6V884H326.7c-13.7 0-24.7 14.3-24.7 32v36c0 4.4 2.8 8 6.2 8h407.6c3.4 0 6.2-3.6 6.2-8v-36c0-17.7-11-32-24.7-32H548V782.1c165.3-18 294-158 294-328.1M512 624c93.9 0 170-75.2 170-168V232c0-92.8-76.1-168-170-168s-170 75.2-170 168v224c0 92.8 76.1 168 170 168m-94-392c0-50.6 41.9-92 94-92s94 41.4 94 92v224c0 50.6-41.9 92-94 92s-94-41.4-94-92z" />
					</svg>
				</label>
				<label @click="form.type = 'video'" for="file-upload" class="text-slate-500 mr-2 cursor-pointer">
					<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 32 32">
						<path fill="currentColor"
							d="M6.5 5.5A4.5 4.5 0 0 0 2 10v12a4.5 4.5 0 0 0 4.5 4.5h12A4.5 4.5 0 0 0 23 22v-1.5l4.2 3.15c1.153.865 2.8.042 2.8-1.4V9.75c0-1.442-1.647-2.265-2.8-1.4L23 11.5V10a4.5 4.5 0 0 0-4.5-4.5zM23 14l5-3.75v11.5L23 18zm-2-4v12a2.5 2.5 0 0 1-2.5 2.5h-12A2.5 2.5 0 0 1 4 22V10a2.5 2.5 0 0 1 2.5-2.5h12A2.5 2.5 0 0 1 21 10" />
					</svg>
				</label>
				<button type="button" @click="requestLocation()" :disabled="requestingLocation"
					class="hidden md:inline-flex text-slate-500 mr-2 cursor-pointer disabled:opacity-40"
					:title="$t('Request customer location')" :aria-label="$t('Request customer location')">
					<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
						<g fill="none" stroke="currentColor" stroke-width="1.5">
							<path d="M12 21c-4.418-4.03-7-7.4-7-10.5a7 7 0 1 1 14 0c0 3.1-2.582 6.47-7 10.5Z" />
							<circle cx="12" cy="10.5" r="2.5" />
						</g>
					</svg>
				</button>
				<button type="button" @click="openLocationModal()"
					:disabled="sendingLocation || !isInboundChatWithin24Hours"
					class="hidden md:inline-flex text-slate-500 mr-2 cursor-pointer disabled:opacity-40"
					:title="$t('Send our location')" :aria-label="$t('Send our location')">
					<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
						<g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
							stroke-linejoin="round">
							<path d="M12 21c-4.418-4.03-7-7.4-7-10.5a7 7 0 1 1 14 0c0 3.1-2.582 6.47-7 10.5Z"
								fill="currentColor" fill-opacity="0.15" />
							<path d="m14.5 8.5l-5 2.2l2.1 1l1 2.1z" fill="currentColor" />
						</g>
					</svg>
				</button>
				<label @click="viewTemplate()" class="text-slate-500 mr-4 cursor-pointer hidden md:inline-flex">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 256 256">
						<path fill="currentColor"
							d="M216 80h-32V48a16 16 0 0 0-16-16H40a16 16 0 0 0-16 16v128a8 8 0 0 0 13 6.22L72 154v30a16 16 0 0 0 16 16h93.59L219 230.22a8 8 0 0 0 5 1.78a8 8 0 0 0 8-8V96a16 16 0 0 0-16-16M66.55 137.78L40 159.25V48h128v88H71.58a8 8 0 0 0-5.03 1.78M216 207.25l-26.55-21.47a8 8 0 0 0-5-1.78H88v-32h80a16 16 0 0 0 16-16V96h32Z">
						</path>
					</svg>
				</label>
				</div>
				<button class="flex shrink-0 items-center" type="submit"
					:disabled="formTextInput === null || formTextInput.trim() === '' || processingForm || !isInboundChatWithin24Hours">
					<svg v-if="!processingForm" :class="formTextInput === null || formTextInput.trim() === '' || !isInboundChatWithin24Hours ? 'text-slate-300' : 'text-black'
						" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 16 16">
						<path fill="currentColor"
							d="M1.724 1.053a.5.5 0 0 0-.714.545l1.403 4.85a.5.5 0 0 0 .397.354l5.69.953c.268.053.268.437 0 .49l-5.69.953a.5.5 0 0 0-.397.354l-1.403 4.85a.5.5 0 0 0 .714.545l13-6.5a.5.5 0 0 0 0-.894l-13-6.5Z" />
					</svg>
					<svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
						<circle cx="12" cy="2" r="0" fill="currentColor">
							<animate attributeName="r" begin="0" calcMode="spline" dur="1s"
								keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
								values="0;2;0;0" />
						</circle>
						<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(45 12 12)">
							<animate attributeName="r" begin="0.125s" calcMode="spline" dur="1s"
								keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
								values="0;2;0;0" />
						</circle>
						<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(90 12 12)">
							<animate attributeName="r" begin="0.25s" calcMode="spline" dur="1s"
								keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
								values="0;2;0;0" />
						</circle>
						<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(135 12 12)">
							<animate attributeName="r" begin="0.375s" calcMode="spline" dur="1s"
								keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
								values="0;2;0;0" />
						</circle>
						<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(180 12 12)">
							<animate attributeName="r" begin="0.5s" calcMode="spline" dur="1s"
								keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
								values="0;2;0;0" />
						</circle>
						<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(225 12 12)">
							<animate attributeName="r" begin="0.625s" calcMode="spline" dur="1s"
								keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
								values="0;2;0;0" />
						</circle>
						<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(270 12 12)">
							<animate attributeName="r" begin="0.75s" calcMode="spline" dur="1s"
								keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
								values="0;2;0;0" />
						</circle>
						<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(315 12 12)">
							<animate attributeName="r" begin="0.875s" calcMode="spline" dur="1s"
								keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
								values="0;2;0;0" />
						</circle>
					</svg>
				</button>
			</div>
		</div>
	</form>
	<form v-if="
		!simpleForm &&
		!props.chatLimitReached &&
		!props.contact.is_blocked
	" @submit.prevent="sendMessage()" class="relative flex items-center px-2 md:px-10 space-x-2">
		<ShortcutsDropdown v-if="shortcutsOpen" :items="filteredShortcuts" :activeIndex="activeShortcutIndex"
			@hover="setActiveShortcut" @select="(i) => applyShortcut(filteredShortcuts[i])" />
		<div class="w-full overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
			<Chat24HourComposeBanner
				v-if="!isInboundChatWithin24Hours"
				:sending-auth-template="sendingAuthTemplate"
				@view-template="viewTemplate()"
				@send-auth-template="sendAuthTemplate()"
			/>
			<UploadProgressIndicator
				:jobs="currentUploadJobs"
				:percent="currentUploadPercent"
				:file-count="currentUploadFileCount"
				:expanded="uploadsExpanded"
				@toggle="uploadsExpanded = !uploadsExpanded"
				@retry="retryUpload"
				@cancel="(job) => uploads.cancel(job.id)"
				@dismiss="(job) => uploads.dismiss(job.id)"
			/>
			<div class="p-4" :class="!isInboundChatWithin24Hours ? 'pointer-events-none opacity-40' : ''">
			<div>
				<textarea ref="textInputRef" @focus="form.type = 'text'" @keydown="onComposeKeydown" @keydown.enter.exact.prevent="handleEnterKey"
					class="w-full outline-none resize-none text-sm rounded-md p-2"
					:class="processingForm ? 'bg-gray-100' : 'bg-white'" v-model="formTextInput"
					@input="adjustTextareaHeight" type="text" rows="3" :autofocus="isInboundChatWithin24Hours"
					:placeholder="$t('Type your message...')" :disabled="processingForm || !isInboundChatWithin24Hours">
        </textarea>
				<input type="file" class="sr-only" :accept="getAcceptedFileTypes()" id="file-upload"
					@change="handleFileUpload($event)" />
			</div>
			<!-- Recording Interface -->
			<div v-if="isRecording || audioPreviewUrl" class="bg-slate-50 rounded-lg border px-2 mb-2">
				<div class="flex items-center justify-between">
					<div class="flex items-center">
						<span class="text-red-500 mr-2">
							<span v-if="isRecording" class="animate-pulse">
								<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
									<circle cx="12" cy="12" r="7" fill="red" opacity="0.5" />
									<path fill="#fee2e2" fill-rule="evenodd"
										d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2S2 6.477 2 12s4.477 10 10 10m0-3a7 7 0 1 0 0-14a7 7 0 0 0 0 14"
										clip-rule="evenodd" />
								</svg>
							</span>
							<span v-if="audioPreviewUrl" @click="togglePlayback">
								<svg v-if="!isPlaying" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
									viewBox="0 0 24 24">
									<path fill="#22c55e" stroke="#22c55e" stroke-linecap="round" stroke-width="1.5"
										d="M3 12v6.967c0 2.31 2.534 3.769 4.597 2.648l3.203-1.742M3 8V5.033c0-2.31 2.534-3.769 4.597-2.648l12.812 6.968a2.998 2.998 0 0 1 0 5.294l-6.406 3.484" />
								</svg>
								<svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24"
									viewBox="0 0 24 24">
									<path fill="none" stroke="red" stroke-linecap="round" stroke-width="1.5"
										d="M2 18c0 1.886 0 2.828.586 3.414S4.114 22 6 22s2.828 0 3.414-.586S10 19.886 10 18V6c0-1.886 0-2.828-.586-3.414S7.886 2 6 2s-2.828 0-3.414.586S2 4.114 2 6v8m20-8c0-1.886 0-2.828-.586-3.414S19.886 2 18 2s-2.828 0-3.414.586S14 4.114 14 6v12c0 1.886 0 2.828.586 3.414S16.114 22 18 22s2.828 0 3.414-.586S22 19.886 22 18v-8" />
								</svg>
							</span>
						</span>
						<span class="text-sm" v-if="isRecording">{{ formatTime(recordingTime) }}</span>
						<span class="text-sm" v-else>{{ formatTime(playbackTime) }} /
							{{ formatTime(recordingTime) }}</span>
					</div>
					<div class="flex gap-2">
						<!-- Stop Recording Button -->
						<button v-if="isRecording" @click="stopRecording" class="p-2 rounded-full hover:bg-red-100"
							title="Stop Recording">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
								<path fill="red" stroke="red" stroke-width="1.5"
									d="M2 12c0-4.714 0-7.071 1.464-8.536C4.93 2 7.286 2 12 2s7.071 0 8.535 1.464C22 4.93 22 7.286 22 12s0 7.071-1.465 8.535C19.072 22 16.714 22 12 22s-7.071 0-8.536-1.465C2 19.072 2 16.714 2 12Z" />
							</svg>
						</button>
						<!-- Delete Button -->
						<button v-if="audioPreviewUrl" @click="deleteRecording"
							class="p-2 rounded-full hover:bg-red-100 text-red-500" title="Delete Recording">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
								<path fill="none" stroke="red" stroke-linecap="round" stroke-width="1.5"
									d="M9.17 4a3.001 3.001 0 0 1 5.66 0m5.67 2h-17m14.874 9.4c-.177 2.654-.266 3.981-1.131 4.79s-2.195.81-4.856.81h-.774c-2.66 0-3.99 0-4.856-.81c-.865-.809-.953-2.136-1.13-4.79l-.46-6.9m13.666 0l-.2 3M9.5 11l.5 5m4.5-5l-.5 5" />
							</svg>
						</button>
					</div>
				</div>
				<audio ref="audioPlayer" :src="audioPreviewUrl" @ended="isPlaying = false" class="hidden" />
			</div>
			<div class="hidden md:flex gap-x-4 items-center text-gray-600">
				<div @click="viewTemplate()" class="flex gap-x-1 text-sm items-center">
					<span>
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 256 256">
							<path fill="currentColor"
								d="M216 80h-32V48a16 16 0 0 0-16-16H40a16 16 0 0 0-16 16v128a8 8 0 0 0 13 6.22L72 154v30a16 16 0 0 0 16 16h93.59L219 230.22a8 8 0 0 0 5 1.78a8 8 0 0 0 8-8V96a16 16 0 0 0-16-16M66.55 137.78L40 159.25V48h128v88H71.58a8 8 0 0 0-5.03 1.78M216 207.25l-26.55-21.47a8 8 0 0 0-5-1.78H88v-32h80a16 16 0 0 0 16-16V96h32Z">
							</path>
						</svg>
					</span>
					<button>{{ $t('Templates') }}</button>
				</div>
				<div>|</div>
				<div class="flex gap-x-4 text-sm items-center">
					<div>
						<button type="button" class="py-1" @click="toggleEmojiPicker">
							<span>
								<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 16 16">
									<path fill="currentColor" fill-rule="evenodd"
										d="M4.111 2.18a7 7 0 1 1 7.778 11.64A7 7 0 0 1 4.11 2.18zm.556 10.809a6 6 0 1 0 6.666-9.978a6 6 0 0 0-6.666 9.978M6.5 7a1 1 0 1 1-2 0a1 1 0 0 1 2 0m5 0a1 1 0 1 1-2 0a1 1 0 0 1 2 0M8 11a3 3 0 0 1-2.65-1.58l-.87.48a4 4 0 0 0 7.12-.16l-.9-.43A3 3 0 0 1 8 11"
										clip-rule="evenodd" />
								</svg>
							</span>
						</button>
						<div class="absolute">
							<div v-if="emojiPicker" class="absolute left-0 bottom-full" ref="emojiPickerRef">
								<EmojiPicker :native="true" @select="addEmoji" />
							</div>
						</div>
					</div>
					<div>
						<label class="py-1 cursor-pointer" @click="form.type = 'document'" for="file-upload">
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 56 56">
								<path fill="currentColor"
									d="M44.09 28.867L26.863 46.094c-4.476 4.5-10.5 4.054-14.343.164c-3.867-3.844-4.313-9.82.187-14.32L36.191 8.453c2.696-2.695 6.657-3.07 9.235-.515c2.555 2.601 2.18 6.539-.492 9.234L21.988 40.14c-1.171 1.195-2.554.843-3.375.047c-.796-.82-1.125-2.18.047-3.376l16.031-16.007c.704-.727.75-1.758.07-2.438c-.679-.656-1.71-.61-2.413.094L16.246 34.563c-2.39 2.39-2.297 6.046-.187 8.156c2.297 2.297 5.789 2.25 8.18-.164l23.085-23.063c4.383-4.383 4.172-10.148.375-13.969c-3.75-3.726-9.586-4.007-13.992.375L10.082 29.547c-5.789 5.789-5.344 14.062-.117 19.289c5.227 5.203 13.5 5.648 19.289-.117l17.344-17.344c.703-.68.68-1.922-.024-2.555c-.68-.726-1.78-.633-2.484.047" />
							</svg>
						</label>
					</div>
					<div>
						<label @click="form.type = 'audio'" class="py-1 cursor-pointer" for="file-upload">
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
								<g fill="none" stroke="currentColor" stroke-width="1.5">
									<path
										d="M9 19a3 3 0 1 1-6 0a3 3 0 0 1 6 0Zm12-2a3 3 0 1 1-6 0a3 3 0 0 1 6 0ZM9 19V8m12 9V6" />
									<path stroke-linecap="round"
										d="m15.735 3.755l-4 1.333c-1.32.44-1.98.66-2.357 1.184S9 7.492 9 8.882V12l12-4v-.45c0-2.533 0-3.8-.83-4.398c-.831-.599-2.032-.198-4.435.603Z" />
								</g>
							</svg>
						</label>
					</div>
					<div>
						<label @click="form.type = 'video'" class="py-1 cursor-pointer" for="file-upload">
							<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 32 32">
								<path fill="currentColor"
									d="M6.5 5.5A4.5 4.5 0 0 0 2 10v12a4.5 4.5 0 0 0 4.5 4.5h12A4.5 4.5 0 0 0 23 22v-1.5l4.2 3.15c1.153.865 2.8.042 2.8-1.4V9.75c0-1.442-1.647-2.265-2.8-1.4L23 11.5V10a4.5 4.5 0 0 0-4.5-4.5zM23 14l5-3.75v11.5L23 18zm-2-4v12a2.5 2.5 0 0 1-2.5 2.5h-12A2.5 2.5 0 0 1 4 22V10a2.5 2.5 0 0 1 2.5-2.5h12A2.5 2.5 0 0 1 21 10" />
							</svg>
						</label>
					</div>
					<div>
						<label class="py-1 cursor-pointer" @click="form.type = 'image'" for="file-upload">
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
								<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
									stroke-width="1.5">
									<path
										d="M6.5 8a2 2 0 1 0 4 0a2 2 0 0 0-4 0Zm14.427 1.99c-6.61-.908-12.31 4-11.927 10.51" />
									<path d="M3 13.066c2.78-.385 5.275.958 6.624 3.1" />
									<path
										d="M3 12c0-4.243 0-6.364 1.318-7.682C5.636 3 7.758 3 12 3c4.243 0 6.364 0 7.682 1.318C21 5.636 21 7.758 21 12c0 4.243 0 6.364-1.318 7.682C18.364 21 16.242 21 12 21c-4.243 0-6.364 0-7.682-1.318C3 18.364 3 16.242 3 12Z" />
								</g>
							</svg>
						</label>
					</div>
					<div class="flex items-center gap-1">
						<button type="button" class="py-1 cursor-pointer disabled:opacity-40"
							@click="requestLocation()" :disabled="requestingLocation"
							:title="$t('Request customer location')" :aria-label="$t('Request customer location')">
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
								<g fill="none" stroke="currentColor" stroke-width="1.5">
									<path d="M12 21c-4.418-4.03-7-7.4-7-10.5a7 7 0 1 1 14 0c0 3.1-2.582 6.47-7 10.5Z" />
									<circle cx="12" cy="10.5" r="2.5" />
								</g>
							</svg>
						</button>
						<button type="button" class="py-1 cursor-pointer disabled:opacity-40"
							@click="openLocationModal()" :disabled="sendingLocation || !isInboundChatWithin24Hours"
							:title="$t('Send our location')" :aria-label="$t('Send our location')">
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
								<g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
									stroke-linejoin="round">
									<path d="M12 21c-4.418-4.03-7-7.4-7-10.5a7 7 0 1 1 14 0c0 3.1-2.582 6.47-7 10.5Z"
										fill="currentColor" fill-opacity="0.15" />
									<path d="m14.5 8.5l-5 2.2l2.1 1l1 2.1z" fill="currentColor" />
								</g>
					</svg>
						</button>
					</div>
				</div>
				<div>|</div>
				<button type="button" class="py-1 cursor-pointer relative" @click="startRecording"
					v-if="!isRecording && !audioPreviewUrl">
					<span class="relative">
						<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 1024 1024">
							<path fill="currentColor"
								d="M842 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 140.3-113.7 254-254 254S258 594.3 258 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 168.7 126.6 307.9 290 327.6V884H326.7c-13.7 0-24.7 14.3-24.7 32v36c0 4.4 2.8 8 6.2 8h407.6c3.4 0 6.2-3.6 6.2-8v-36c0-17.7-11-32-24.7-32H548V782.1c165.3-18 294-158 294-328.1M512 624c93.9 0 170-75.2 170-168V232c0-92.8-76.1-168-170-168s-170 75.2-170 168v224c0 92.8 76.1 168 170 168m-94-392c0-50.6 41.9-92 94-92s94 41.4 94 92v224c0 50.6-41.9 92-94 92s-94-41.4-94-92z" />
						</svg>
					</span>
				</button>
				<div class="ms-[-5px]">
					<button v-if="!processingAISuggestions"
						class="text-[14px] flex items-center gap-2 hover:bg-gray-100 p-1 rounded-md" type="button"
						@click="getSuggestion()">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
							<g fill="none" stroke="#000" stroke-linecap="round" stroke-linejoin="round"
								stroke-width="1.5" color="#000">
								<path d="m7 8l2.942 1.74c1.715 1.014 2.4 1.014 4.116 0L17 8" />
								<path
									d="M21.984 12.976c.021-.986.021-1.966 0-2.952c-.065-3.065-.098-4.598-1.229-5.733c-1.131-1.136-2.705-1.175-5.854-1.254a115 115 0 0 0-5.802 0c-3.149.079-4.723.118-5.854 1.254c-1.131 1.135-1.164 2.668-1.23 5.733a69 69 0 0 0 0 2.952c.066 3.065.099 4.598 1.23 5.733c1.131 1.136 2.705 1.175 5.854 1.254c1.305.033 2.601.044 3.901.033" />
								<path
									d="m18.5 14l.258.697c.338.914.507 1.371.84 1.704c.334.334.791.503 1.705.841L22 17.5l-.697.258c-.914.338-1.371.507-1.704.84c-.334.334-.503.791-.841 1.705L18.5 21l-.258-.697c-.338-.914-.507-1.371-.84-1.704c-.334-.334-.791-.503-1.705-.841L15 17.5l.697-.258c.914-.338 1.371-.507 1.704-.84c.334-.334.503-.791.841-1.705z" />
							</g>
						</svg>
						<span>{{ $t('AI Assist') }}</span>
					</button>
					<div v-else class="text-[14px] flex items-center gap-2 bg-gray-100 p-1 px-2 rounded-md">
						<span>{{ $t('Searching for suggestions') }}</span>
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
							<circle cx="18" cy="12" r="0" fill="currentColor">
								<animate attributeName="r" begin=".67" calcMode="spline" dur="1.5s"
									keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
									repeatCount="indefinite" values="0;2;0;0" />
							</circle>
							<circle cx="12" cy="12" r="0" fill="currentColor">
								<animate attributeName="r" begin=".33" calcMode="spline" dur="1.5s"
									keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
									repeatCount="indefinite" values="0;2;0;0" />
							</circle>
							<circle cx="6" cy="12" r="0" fill="currentColor">
								<animate attributeName="r" begin="0" calcMode="spline" dur="1.5s"
									keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
									repeatCount="indefinite" values="0;2;0;0" />
							</circle>
						</svg>
					</div>
				</div>
				<div class="ml-auto">
					<button class="flex items-center gap-x-2 p-1 px-2 rounded-md border border-gray-400 text-sm"
						:disabled="(((formTextInput === null || formTextInput.trim() === '') && !form2.file) || !isInboundChatWithin24Hours)">
						<span :class="((formTextInput === null || formTextInput.trim() === '') && !form2.file) || !isInboundChatWithin24Hours
							? 'text-slate-300'
							: 'text-black'
							">{{ $t('Send') }}</span>
						<div>
							<svg v-if="!processingForm" :class="((formTextInput === null || formTextInput.trim() === '') && !form2.file) || !isInboundChatWithin24Hours
								? 'text-slate-300'
								: 'text-black'
								" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
								<path fill="currentColor"
									d="M1.724 1.053a.5.5 0 0 0-.714.545l1.403 4.85a.5.5 0 0 0 .397.354l5.69.953c.268.053.268.437 0 .49l-5.69.953a.5.5 0 0 0-.397.354l-1.403 4.85a.5.5 0 0 0 .714.545l13-6.5a.5.5 0 0 0 0-.894l-13-6.5Z" />
							</svg>
							<svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
								<circle cx="12" cy="2" r="0" fill="currentColor">
									<animate attributeName="r" begin="0" calcMode="spline" dur="1s"
										keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
										repeatCount="indefinite" values="0;2;0;0" />
								</circle>
								<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(45 12 12)">
									<animate attributeName="r" begin="0.125s" calcMode="spline" dur="1s"
										keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
										repeatCount="indefinite" values="0;2;0;0" />
								</circle>
								<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(90 12 12)">
									<animate attributeName="r" begin="0.25s" calcMode="spline" dur="1s"
										keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
										repeatCount="indefinite" values="0;2;0;0" />
								</circle>
								<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(135 12 12)">
									<animate attributeName="r" begin="0.375s" calcMode="spline" dur="1s"
										keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
										repeatCount="indefinite" values="0;2;0;0" />
								</circle>
								<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(180 12 12)">
									<animate attributeName="r" begin="0.5s" calcMode="spline" dur="1s"
										keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
										repeatCount="indefinite" values="0;2;0;0" />
								</circle>
								<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(225 12 12)">
									<animate attributeName="r" begin="0.625s" calcMode="spline" dur="1s"
										keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
										repeatCount="indefinite" values="0;2;0;0" />
								</circle>
								<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(270 12 12)">
									<animate attributeName="r" begin="0.75s" calcMode="spline" dur="1s"
										keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
										repeatCount="indefinite" values="0;2;0;0" />
								</circle>
								<circle cx="12" cy="2" r="0" fill="currentColor" transform="rotate(315 12 12)">
									<animate attributeName="r" begin="0.875s" calcMode="spline" dur="1s"
										keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
										repeatCount="indefinite" values="0;2;0;0" />
								</circle>
							</svg>
						</div>
					</button>
				</div>
			</div>

			<!-- شريط الموبايل: emoji + مرفق + mic + إرسال -->
			<div class="flex md:hidden items-center gap-1 border-t border-slate-100 px-2 py-2 text-gray-600">
				<div class="relative shrink-0">
					<button type="button" class="flex h-9 w-9 items-center justify-center rounded-full hover:bg-slate-100"
						@click="toggleEmojiPicker" :aria-label="$t('Emoji')">
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 16 16" class="text-slate-600">
							<path fill="currentColor" fill-rule="evenodd"
								d="M4.111 2.18a7 7 0 1 1 7.778 11.64A7 7 0 0 1 4.11 2.18zm.556 10.809a6 6 0 1 0 6.666-9.978a6 6 0 0 0-6.666 9.978M6.5 7a1 1 0 1 1-2 0a1 1 0 0 1 2 0m5 0a1 1 0 1 1-2 0a1 1 0 0 1 2 0M8 11a3 3 0 0 1-2.65-1.58l-.87.48a4 4 0 0 0 7.12-.16l-.9-.43A3 3 0 0 1 8 11"
								clip-rule="evenodd" />
						</svg>
					</button>
					<div v-if="emojiPicker" class="absolute left-0 bottom-full z-10" ref="emojiPickerRef">
						<EmojiPicker :native="true" @select="addEmoji" />
					</div>
				</div>

				<ChatAttachMenu
					class="shrink-0"
					:show-ai-assist="true"
					:requesting-location="requestingLocation"
					:sending-location="sendingLocation"
					:disabled="!isInboundChatWithin24Hours"
					@action="handleAttachAction"
				/>

				<button
					v-if="!isRecording && !audioPreviewUrl"
					type="button"
					class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full hover:bg-slate-100"
					@click="startRecording"
					:aria-label="$t('Record audio')"
				>
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 1024 1024" class="text-slate-600">
						<path fill="currentColor"
							d="M842 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 140.3-113.7 254-254 254S258 594.3 258 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 168.7 126.6 307.9 290 327.6V884H326.7c-13.7 0-24.7 14.3-24.7 32v36c0 4.4 2.8 8 6.2 8h407.6c3.4 0 6.2-3.6 6.2-8v-36c0-17.7-11-32-24.7-32H548V782.1c165.3-18 294-158 294-328.1M512 624c93.9 0 170-75.2 170-168V232c0-92.8-76.1-168-170-168s-170 75.2-170 168v224c0 92.8 76.1 168 170 168m-94-392c0-50.6 41.9-92 94-92s94 41.4 94 92v224c0 50.6-41.9 92-94 92s-94-41.4-94-92z" />
					</svg>
				</button>

				<div class="ml-auto shrink-0">
					<button
						type="submit"
						class="flex h-9 w-9 items-center justify-center rounded-full border border-gray-300 disabled:opacity-40"
						:disabled="(((formTextInput === null || formTextInput.trim() === '') && !form2.file) || !isInboundChatWithin24Hours)"
						:aria-label="$t('Send')"
					>
						<svg v-if="!processingForm" :class="((formTextInput === null || formTextInput.trim() === '') && !form2.file) || !isInboundChatWithin24Hours
							? 'text-slate-300'
							: 'text-black'
							" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 16 16">
							<path fill="currentColor"
								d="M1.724 1.053a.5.5 0 0 0-.714.545l1.403 4.85a.5.5 0 0 0 .397.354l5.69.953c.268.053.268.437 0 .49l-5.69.953a.5.5 0 0 0-.397.354l-1.403 4.85a.5.5 0 0 0 .714.545l13-6.5a.5.5 0 0 0 0-.894l-13-6.5Z" />
						</svg>
						<svg v-else xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
							<circle cx="12" cy="2" r="0" fill="currentColor">
								<animate attributeName="r" begin="0" calcMode="spline" dur="1s"
									keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8" repeatCount="indefinite"
									values="0;2;0;0" />
							</circle>
						</svg>
					</button>
				</div>
			</div>
			</div>
		</div>
		<Teleport to="body">
			<!-- طبقة السحب: تُغطّي الشاشة فيصحّ الإفلات في أي موضع من المحادثة -->
			<div v-if="isDraggingFile"
				class="pointer-events-none fixed inset-0 z-[9998] flex items-center justify-center bg-slate-900/40">
				<div class="rounded-xl border-2 border-dashed border-white bg-white/95 px-10 py-8 text-center shadow-xl">
					<svg class="mx-auto mb-3 text-slate-500" xmlns="http://www.w3.org/2000/svg" width="44" height="44"
						viewBox="0 0 24 24">
						<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
							stroke-width="1.5" d="M12 16V4m0 0L8 8m4-4l4 4M4 15v2a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-2" />
					</svg>
					<p class="text-base font-medium text-slate-800">{{ $t('Drop files to send them') }}</p>
					<p class="mt-1 text-xs text-slate-500">{{ $t('Images, videos, audio and documents') }}</p>
				</div>
			</div>

			<!--
				معاينة المرفقات قبل الإرسال: اللصق والإفلات قد يقعان سهواً — لقطة شاشة
				في الحافظة تُلصق بضغطة — فلا يُرسَل شيء قبل أن يراه المرسِل.
				نافذة محدودة لا ملء شاشة: المحادثة تبقى ظاهرة خلفها فيعرف المرسِل
				إلى أين يُرسل.
			-->
			<div v-if="pendingAttachments.length" class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
				role="dialog" aria-modal="true">
				<div class="absolute inset-0 bg-black/50" @click="closeAttachmentPreview()"></div>

				<div class="relative flex max-h-[80vh] w-full max-w-md flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
					<div class="flex shrink-0 items-center gap-3 border-b px-4 py-3">
						<div class="min-w-0 flex-1">
							<p class="truncate text-sm font-medium text-slate-800" dir="auto">
								{{ activeAttachment?.file.name }}
							</p>
							<p class="text-xs text-slate-500">
								{{ ((activeAttachment?.file.size ?? 0) / 1024 / 1024).toFixed(2) }} MB
								<template v-if="pendingAttachments.length > 1">
									· {{ activeAttachmentIndex + 1 }}/{{ pendingAttachments.length }}
								</template>
							</p>
						</div>
						<button type="button" class="shrink-0 rounded-full p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
							@click="closeAttachmentPreview()" :aria-label="$t('Close')">
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
								<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"
									d="M6 6l12 12M18 6L6 18" />
							</svg>
						</button>
					</div>

					<div class="flex min-h-0 flex-1 items-center justify-center bg-slate-100 p-4">
						<img v-if="activeAttachment?.previewUrl" :src="activeAttachment.previewUrl" alt=""
							class="max-h-[40vh] max-w-full rounded-lg object-contain" />
						<div v-else class="flex flex-col items-center gap-2 py-8 text-slate-500">
							<div
								class="flex h-20 w-16 items-center justify-center rounded-lg border-2 border-slate-300 bg-white text-sm font-medium uppercase">
								{{ (activeAttachment?.file.name.split('.').pop() || '?').slice(0, 4) }}
							</div>
						</div>
					</div>

					<div v-if="pendingAttachments.length > 1"
						class="flex shrink-0 gap-2 overflow-x-auto border-t bg-white px-4 py-2">
						<button v-for="(item, index) in pendingAttachments" :key="index" type="button"
							class="group relative h-12 w-12 shrink-0 overflow-hidden rounded-lg border-2 transition"
							:class="index === activeAttachmentIndex ? 'border-primary' : 'border-slate-200 opacity-70 hover:opacity-100'"
							@click="activeAttachmentIndex = index">
							<img v-if="item.previewUrl" :src="item.previewUrl" alt="" class="h-full w-full object-cover" />
							<span v-else
								class="flex h-full w-full items-center justify-center bg-slate-100 text-[10px] font-medium uppercase text-slate-500">
								{{ (item.file.name.split('.').pop() || '?').slice(0, 4) }}
							</span>
							<span
								class="absolute right-0 top-0 hidden h-4 w-4 items-center justify-center bg-black/70 text-white group-hover:flex"
								@click.stop="removeAttachment(index)" :aria-label="$t('Remove')">
								<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24">
									<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="3"
										d="M6 6l12 12M18 6L6 18" />
								</svg>
							</span>
						</button>
					</div>

					<div class="flex shrink-0 items-center gap-2 border-t px-4 py-3">
						<input v-model="attachmentCaption" type="text" :placeholder="$t('Add a caption...')" dir="auto"
							class="min-w-0 flex-1 rounded-full border-slate-300 px-4 py-2 text-sm focus:border-primary focus:ring-primary"
							@keydown.enter.prevent="sendAttachments()" />
						<button type="button"
							class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-white transition hover:opacity-90 disabled:opacity-40"
							@click="sendAttachments()"
							:aria-label="$t('Send')" :title="$t('Send')">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
								viewBox="0 0 16 16">
								<path fill="currentColor"
									d="M1.724 1.053a.5.5 0 0 0-.714.545l1.403 4.85a.5.5 0 0 0 .397.354l5.69.953c.268.053.268.437 0 .49l-5.69.953a.5.5 0 0 0-.397.354l-1.403 4.85a.5.5 0 0 0 .714.545l13-6.5a.5.5 0 0 0 0-.894l-13-6.5Z" />
							</svg>
						</button>
					</div>
				</div>
			</div>

		<!-- نافذة إرسال الموقع: عنوان النشاط بضغطة، أو نقطة يختارها الموظّف -->
		<div v-if="showLocationModal" class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
			role="dialog" aria-modal="true">
			<div class="absolute inset-0 bg-black/40" @click="closeLocationModal()"></div>
			<div class="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-lg bg-white shadow-xl">
				<div class="flex items-center justify-between border-b px-5 py-4">
					<h3 class="text-base font-medium text-slate-800">{{ $t('Send a location') }}</h3>
					<button type="button" class="text-slate-400 hover:text-slate-600" @click="closeLocationModal()"
						:aria-label="$t('Close')">
						<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
							<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"
								d="M6 6l12 12M18 6L6 18" />
						</svg>
					</button>
				</div>

				<div class="px-5 py-4">
					<button v-if="organizationLocation" type="button"
						class="mb-4 flex w-full items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-left hover:border-slate-400 hover:bg-slate-50"
						@click="useBusinessLocation()">
						<svg class="shrink-0 text-slate-500" xmlns="http://www.w3.org/2000/svg" width="22" height="22"
							viewBox="0 0 24 24">
							<g fill="none" stroke="currentColor" stroke-width="1.5">
								<path d="M12 21c-4.418-4.03-7-7.4-7-10.5a7 7 0 1 1 14 0c0 3.1-2.582 6.47-7 10.5Z" />
								<circle cx="12" cy="10.5" r="2.5" />
							</g>
						</svg>
						<span class="min-w-0">
							<span class="block text-sm text-slate-800">{{ $t('Use our business location') }}</span>
							<span class="block truncate text-xs text-slate-500">{{ organizationLocation.address || organizationLocation.name }}</span>
						</span>
					</button>

					<div v-else class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-xs text-amber-800">
						{{ $t('Your business location is not set yet. Add it in Settings first.') }}
					</div>

					<LocationPicker v-model="pickedLocation" :api-key="googleMapsApiKey" :height="'300px'" />
				</div>

				<div class="flex items-center justify-end gap-3 border-t px-5 py-4">
					<button type="button" class="rounded-md px-4 py-2 text-sm text-slate-600 hover:bg-slate-100"
						@click="closeLocationModal()">
						{{ $t('Cancel') }}
					</button>
					<button type="button"
						class="rounded-md bg-slate-900 px-5 py-2 text-sm text-white hover:bg-slate-800 disabled:opacity-40"
						:disabled="!isPickedLocationValid || sendingLocation" @click="sendLocation()">
						{{ sendingLocation ? $t('Sending...') : $t('Send location') }}
					</button>
				</div>
			</div>
		</div>
		</Teleport>
	</form>
</template>
