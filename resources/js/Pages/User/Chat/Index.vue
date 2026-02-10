<template>
	<AppLayout v-slot:default="slotProps">
		<div class="md:flex md:flex-grow md:overflow-hidden">
			<div class="md:w-[30%] md:flex flex-col h-full bg-white border-r border-l" :class="contact ? 'hidden' : ''">
				<ChatTable :rows="rows" :filters="props.filters" :rowCount="props.rowCount"
					:ticketingIsEnabled="ticketingIsEnabled" :status="props?.status"
					:chatSortDirection="props.chat_sort_direction" />
			</div>
			<div class="min-w-0 bg-cover flex flex-col chat-bg"
				:class="contact ? 'h-screen md:w-[70%]' : 'md:h-screen md:w-[70%]'">
				<ChatHeader v-if="contact" :ticketingIsEnabled="ticketingIsEnabled" :contact="contact"
					:displayContactInfo="displayContactInfo" :ticket="ticket" :addon="addon"
					@toggleView="toggleContactView" @deleteThread="deleteThread" @closeThread="closeThread" />
				<div v-if="contact && !displayTemplate" class="flex-1 overflow-y-auto" ref="scrollContainer2">
					<ChatThread v-if="!displayContactInfo && !loadingThread && !displayTemplate" :contactId="contact.id"
						:initialMessages="chatThread" :hasMoreMessages="hasMoreMessages" :initialNextPage="nextPage" />
					<Contact v-if="displayContactInfo && !displayTemplate" class="bg-white h-full"
						:fields="props.fields" :contact="contact" :locationSettings="props.locationSettings" />
				</div>
				<div v-if="props.contact?.is_blocked"
					class="is-blocked flex justify-center items-center gap-2 px-3 py-1 h-[80px] text-center bg-red-50/80 backdrop-blur-sm border border-red-200 rounded-lg">
					<div class="relative flex h-2 w-2">
						<span
							class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
						<span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
					</div>
					<span class="text-xs font-bold text-red-800 uppercase tracking-tighter">{{ $t('Blocked')
					}}</span>
				</div>
				<div v-if="
					contact &&
					!contact.is_blocked &&
					!displayContactInfo &&
					!formLoading &&
					!displayTemplate
				" class="w-full py-4">
					<ChatForm :contact="contact" :simpleForm="simpleForm" :chatLimitReached="isChatLimitReached"
						@viewTemplate="displayTemplate = true" @newMessage="generateNewMessage" />
				</div>
				<div v-if="displayTemplate" class="flex-1 overflow-y-hidden">
					<CampaignForm v-if="displayTemplate" class="bg-white h-full" :contact="contact.uuid"
						:templates="templates" :contactGroups="[]" :settings="props.settings" :displayCancelBtn="false"
						:displayTitle="true" :isCampaignFlow="false" :scheduleTemplate="false"
						:sendText="'Send Message'" @viewTemplate="displayTemplate = false" />
				</div>
			</div>
			<!--<div v-if="contact" class="md:w-[25%] min-w-0 bg-cover flex flex-col bg-white border-l">
                <ChatContact v-if="contact" class="bg-white h-full" :contact="contact" />
            </div>-->
		</div>
		<button class="hidden" ref="toggleNavbarBtn" @click="slotProps.toggleNavBar"></button>
	</AppLayout>
</template>
<script setup>
import CampaignForm from '@/Components/CampaignForm.vue'
import ChatForm from '@/Components/ChatComponents/ChatForm.vue'
import ChatHeader from '@/Components/ChatComponents/ChatHeader.vue'
import ChatTable from '@/Components/ChatComponents/ChatTable.vue'
import ChatThread from '@/Components/ChatComponents/ChatThread.vue'
import Contact from '@/Components/ContactInfo.vue'
import { default as axios } from 'axios'
import debounce from 'lodash/debounce'
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { getEchoInstance } from '../../../echo'
import AppLayout from './../Layout/App.vue'

const props = defineProps({
	rows: Array,
	rowCount: Number,
	pusherSettings: Object,
	organizationId: Number,
	isChatLimitReached: Boolean,
	toggleNavBar: Function,
	settings: Object,
	status: String,
	chatThread: Array,
	hasMoreMessages: Boolean,
	nextPage: Number,
	addon: Object,
	contact: Object,
	ticket: Object,
	chat_sort_direction: String,
	filters: Object,
	templates: Array,
	fields: Array,
	locationSettings: Object,
	simpleForm: Boolean,
	user: Array,
	timezone: String,
})

// ✅ استخدام ref بدلاً من let
const echoInstance = ref(null)
const echoChannel = ref(null)

const rows = ref(props.rows)
const rowCount = ref(props.rowCount)
const scrollContainer2 = ref(null)
const loadingThread = ref(false)
const displayContactInfo = ref(false)
const displayTemplate = ref(false)
const formLoading = ref(false)
const isChatLimitReached = ref(props.isChatLimitReached)
const toggleNavbarBtn = ref(null)
const config = ref(props.settings.metadata)
const settings = ref(config.value ? JSON.parse(config.value) : null)
const ticketingIsEnabled = ref(settings.value?.tickets?.active ?? false)
const chatThread = ref(props.chatThread)
const contact = ref(props.contact)

watch(
	() => props.rows,
	(newRows) => {
		rows.value = newRows
	},
)

function toggleContactView(value) {
	displayContactInfo.value = value
}

const scrollToBottom = () => {
	const container = scrollContainer2.value
	if (container) {
		container.scrollTo({
			top: container.scrollHeight,
			behavior: 'smooth',
		})
	}
}

const closeThread = () => {
	toggleNavbarBtn.value.click()
	contact.value = null
}

const deleteThread = async () => {
	chatThread.value = []
	await axios.delete('/chats/' + contact.value.uuid)
}

function getCurrentDateTime(timezone) {
	const now = new Date()
	const options = {
		timeZone: timezone,
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
		hour12: false,
	}

	const formatter = new Intl.DateTimeFormat('sv-SE', options)
	return formatter.format(now).replace(',', '').replace(/\//g, '-')
}

const getFileTypeCategory = (mimeType) => {
	if (mimeType.startsWith('image/')) return 'image'
	if (mimeType.startsWith('video/')) return 'video'
	if (mimeType.startsWith('audio/')) return 'audio'
	return 'document'
}

const generateNewMessage = (form) => {
	const isText = form.value.type == 'text'
	let file = null
	const isMedia = !isText
	let metadata = {
		text: {
			body: form.value.message,
		},
		type: 'text',
	}

	if (isMedia) {
		file = form.value.file
		const fileTypeCategory = getFileTypeCategory(file.type)
		metadata = {
			type: fileTypeCategory,
		}

		if (fileTypeCategory === 'image') {
			metadata.image = {
				mime_type: file.type,
			}
		} else if (fileTypeCategory === 'video') {
			metadata.video = {
				mime_type: file.type,
			}
		} else if (fileTypeCategory === 'audio') {
			metadata.audio = {
				mime_type: file.type,
			}
		} else {
			metadata.document = {
				mime_type: file.type,
			}
		}
	}

	let chat = [
		{
			type: 'chat',
			value: {
				created_at: getCurrentDateTime(props.timezone),
				deleted_at: null,
				logs: [],
				media: isMedia
					? {
						created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
						id: Math.floor(Math.random() * 100000),
						name: file.name,
						path: URL.createObjectURL(file),
						size: file.size,
						type: file.type,
					}
					: null,
				media_id: null,
				metadata: JSON.stringify(metadata),
				status: 'delivered',
				type: 'outbound',
				user: {
					first_name: props.user.first_name,
					last_name: props.user.last_name,
				},
				wam_id: form.value.tempMessageId,
			},
		},
	]

	updateChatThread(chat)
}

const updateChatThread = (chat) => {
	const wamId = chat[0].value.wam_id
	// console.log('wamId =', wamId)
	const wamIdExists = chatThread.value.some(
		(existingChat) => existingChat[0].value.wam_id === wamId,
	)
	if (!wamIdExists && chat[0].value.deleted_at == null) {
		if (chat[0].tempMessageId) {
			const tempChatIndex = chatThread.value.findIndex(
				(item) => item[0].value.wam_id === chat[0].tempMessageId,
			)
			if (tempChatIndex !== -1) {
				chatThread.value[tempChatIndex] = chat
			}
		} else {
			chatThread.value.push(chat)
		}
		setTimeout(scrollToBottom, 100)
	} else {
		// console.log('chat already exists', chat[0].tempMessageId)
		const tempChatIndex = chatThread.value.findIndex(
			(item) => item[0].value.wam_id === chat[0].tempMessageId,
		)
		if (tempChatIndex !== -1) {
			// console.log('chat already exists', chat[0].tempMessageId,)
			chatThread.value[tempChatIndex] = chat
		} else {
			// console.log('chat not found', chatThread.value, chat)
		}
	}
}

const refetchChatsList = debounce(async () => {
	try {
		const response = await axios.get('/chats')
		if (response?.data?.result) {
			rows.value = response.data.result
		}
	} catch (error) {
		// تجاهل أخطاء تحديث القائمة
	}
}, 1500)

const updateSidePanel = async (chat) => {
	if (contact.value && contact.value.id == chat[0].value.contact_id) {
		updateChatThread(chat)
	}
	refetchChatsList()
}

// ✅ الكود الصحيح مع استخدام ref
onMounted(() => {
	try {


		// ✅ إنشاء Echo instance
		echoInstance.value = getEchoInstance(
			props.pusherSettings.pusher_app_key,
			props.pusherSettings.pusher_app_cluster,
		)


		const channelName = `chats.ch${props.organizationId}`

		echoChannel.value = echoInstance.value
			.join(channelName)
			.here((users) => {
				// console.log('Users currently in channel:', users)
			})
			.joining((user) => {
				// console.log('User joined:', user)
			})
			.leaving((user) => {
				// console.log('User left:', user)
			})
			.error((error) => {
				// console.log('Error:', error)
			})
			.listen('NewChatEvent', (event) => {
				console.log('New chat event received:', event)
				updateSidePanel(event.chat)
			})


		scrollToBottom()

	} catch (error) {
	}
})


onUnmounted(() => {
	try {
		const channelName = props.organizationId ? `chats.ch${props.organizationId}` : null
		// إزالة مستمع الحدث أولاً (Laravel Echo: leave() وحده لا يزيل الـ listeners → تسرب ذاكرة و callbacks مكررة)
		if (echoChannel.value && typeof echoChannel.value.stopListening === 'function') {
			//		console.log('Stop listening to NewChatEvent')
			console.log('Stop listening to NewChatEvent')
			echoChannel.value.stopListening('NewChatEvent')
		}
		if (echoInstance.value && channelName) {
			console.log('Leaving channel:', channelName)
			echoInstance.value.leave(channelName)
		}
		echoChannel.value = null
		echoInstance.value = null
	} catch (error) {
		// تجاهل أخطاء التنظيف
	}
})

</script>
