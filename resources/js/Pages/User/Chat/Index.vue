<template>
	<AppLayout v-slot:default="slotProps">
		<div class="md:flex md:flex-grow md:overflow-hidden">
			<div class="md:w-[30%] md:flex flex-col h-full bg-white border-r border-l" :class="contact ? 'hidden' : ''">
				<ChatTable :rows="rows" :filters="props.filters" :rowCount="props.rowCount"
					:ticketingIsEnabled="ticketingIsEnabled" :status="props?.status"
					:chatSortDirection="props.chat_sort_direction"
					:contactCategoriesEnabled="props.contactCategoriesEnabled"
					:hasMoreContacts="hasMoreContacts"
					:isLoadingMoreContacts="isLoadingMoreContacts"
					@load-more-contacts="loadMoreContacts"
					@category-filter-change="onCategoryFilterChange" />
			</div>
			<div class="min-w-0 bg-cover flex flex-col chat-bg"
				:class="contact ? 'h-screen md:w-[70%]' : 'md:h-screen md:w-[70%]'">
				<ChatHeader v-if="contact" :ticketingIsEnabled="ticketingIsEnabled" :contact="contact"
					:displayContactInfo="displayContactInfo" :ticket="ticket" :addon="addon"
					@toggleView="toggleContactView" @deleteThread="deleteThread" @closeThread="closeThread" />
				<div v-if="contact && !displayTemplate" class="flex-1 overflow-y-auto" ref="scrollContainer2">
					<ChatThread v-if="!displayContactInfo && !loadingThread" :contactId="contact.id"
						:initialMessages="chatThread" :hasMoreMessages="hasMoreMessages" :initialNextPage="nextPage" />
					<Contact v-if="displayContactInfo" class="bg-white h-full" :fields="props.fields" :contact="contact"
						:locationSettings="props.locationSettings" />
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
				<div v-if="displayTemplate && contact" class="flex-1 overflow-y-hidden">
					<CampaignForm v-if="displayTemplate && contact" class="bg-white h-full" :contact="contact.uuid"
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
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { getOrJoinChatChannel } from '../../../echo'
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
	contactCategoriesEnabled: Boolean,
	hasMoreContacts: { type: Boolean, default: false },
	nextContactsPage: { type: Number, default: null },
})

/** إلغاء اشتراك هذا المكوّن عند الـ unmount (لا نستدعي leave لتبقى القناة للمكوّنات الأخرى) */
const unsubscribeChatChannel = ref(null)

// deduplication helper: يزيل جهات الاتصال المكررة بنفس id (قد تنتج عن JOIN مع tickets)
function deduplicateContacts(data) {
	if (!Array.isArray(data)) return data
	const seen = new Set()
	return data.filter(c => {
		if (seen.has(c.id)) return false
		seen.add(c.id)
		return true
	})
}

const rows = ref(
	props.rows?.data
		? { ...props.rows, data: deduplicateContacts(props.rows.data) }
		: props.rows
)
const rowCount = ref(props.rowCount)
const isCategoryFilterActive = ref(false)
const scrollContainer2 = ref(null)
const scrollResizeCleanup = ref(null)
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
const templates = ref(props.templates ?? [])

const hasMoreContacts = ref(props.hasMoreContacts)
const nextContactsPage = ref(props.nextContactsPage)
const isLoadingMoreContacts = ref(false)

async function loadMoreContacts() {
	if (isLoadingMoreContacts.value || !hasMoreContacts.value || nextContactsPage.value === null) return
	isLoadingMoreContacts.value = true
	try {
		const params = new URLSearchParams(window.location.search)
		params.set('contact_page', nextContactsPage.value)
		const response = await axios.get('/chats-load-more?' + params.toString())
		const newData = response.data
		const currentData = rows.value?.data ?? []
		// Deduplication: avoid showing the same contact twice (pagination boundary issue)
		const existingIds = new Set(currentData.map(c => c.id))
		const uniqueNew = (newData.data ?? []).filter(c => !existingIds.has(c.id))
		rows.value = { data: [...currentData, ...uniqueNew] }
		rowCount.value = newData.rowCount ?? rowCount.value
		hasMoreContacts.value = newData.hasMoreContacts ?? false
		nextContactsPage.value = newData.nextContactsPage ?? null
	} catch (e) {
		console.error('loadMoreContacts error', e)
	} finally {
		isLoadingMoreContacts.value = false
	}
}



watch(
	() => props.rows,
	(newRows) => {
		if (!isCategoryFilterActive.value) {
			rows.value = newRows?.data
				? { ...newRows, data: deduplicateContacts(newRows.data) }
				: newRows
		}
	},
)
watch(
	() => props.chatThread,
	(newChatThread) => {
		chatThread.value = newChatThread
	},
)

watch(
	() => props.contact,
	(newContact) => {
		contact.value = newContact
	},
	{}
)
// التمرير لنهاية المحادثة عند فتح محادثة — ننتظر التخطيط والوسائط ثم نستخدم ResizeObserver عند تحميل الصور
watch(
	() => [props.contact?.id, props.chatThread?.length],
	() => {
		if (!props.contact || !props.chatThread?.length) return
		// إلغاء أي مراقبة سابقة
		if (typeof scrollResizeCleanup.value === 'function') {
			scrollResizeCleanup.value()
			scrollResizeCleanup.value = null
		}

		const runScrollLogic = (container) => {
			if (!container) return
			const scrollToBottomInstant = () => {
				if (container) container.scrollTop = container.scrollHeight
			}
			const runInitialScroll = () => {
				container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' })
				setTimeout(() => { container.scrollTop = container.scrollHeight }, 100)
				setTimeout(() => { container.scrollTop = container.scrollHeight }, 350)
			}
			requestAnimationFrame(() => {
				requestAnimationFrame(runInitialScroll)
			})
			const graceMs = 2500
			let lastScrollHeight = container.scrollHeight
			const maybeScrollToBottom = () => {
				const h = container.scrollHeight
				if (h > lastScrollHeight) {
					scrollToBottomInstant()
					lastScrollHeight = h
				}
			}
			const contentEl = container.firstElementChild
			const observer = contentEl ? new ResizeObserver(() => requestAnimationFrame(maybeScrollToBottom)) : null
			if (observer && contentEl) observer.observe(contentEl)
			const intervalId = setInterval(maybeScrollToBottom, 200)
			const timeoutId = setTimeout(() => {
				clearInterval(intervalId)
				if (observer) observer.disconnect()
				scrollResizeCleanup.value = null
			}, graceMs)
			scrollResizeCleanup.value = () => {
				clearTimeout(timeoutId)
				clearInterval(intervalId)
				if (observer) observer.disconnect()
			}
		}

		// الحاوية تُصيّر فقط عند وجود contact (v-if) — نقرأ الـ ref بعد nextTick وأيضاً بعد تأخير بسيط للموبايل
		nextTick(() => {
			let container = scrollContainer2.value
			if (container) {
				runScrollLogic(container)
				return
			}
			setTimeout(() => {
				container = scrollContainer2.value
				if (container) runScrollLogic(container)
			}, 50)
		})
	},
)
watch(
	() => props.templates,
	(newTemplates) => {
		templates.value = newTemplates ?? []
	},
)

function onCategoryFilterChange(active) {
	isCategoryFilterActive.value = active
	if (!active) {
		rows.value = props.rows
	}
}

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
	displayTemplate.value = false
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
	const isMedia = !isText && !!form.value.file
	if (isText && !(form.value.message ?? '').trim()) {
		return
	}
	let metadata = {
		text: {
			body: form.value.message,
		},
		type: 'text',
	}

	if (isMedia) {
		file = form.value.file
		const fileTypeCategory = getFileTypeCategory(file.type)
		const caption = (form.value.message ?? '').trim()
		metadata = {
			type: fileTypeCategory,
		}

		if (fileTypeCategory === 'image') {
			metadata.image = {
				mime_type: file.type,
				...(caption ? { caption } : {}),
			}
		} else if (fileTypeCategory === 'video') {
			metadata.video = {
				mime_type: file.type,
				...(caption ? { caption } : {}),
			}
		} else if (fileTypeCategory === 'audio') {
			metadata.audio = {
				mime_type: file.type,
			}
		} else {
			metadata.document = {
				mime_type: file.type,
				...(caption ? { caption } : {}),
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
			} else {
				// testing
				chatThread.value.push(chat)
			}
		} else {
			chatThread.value.push(chat)
		}
		setTimeout(scrollToBottom, 100)
	} else {
		const tempChatIndex = chatThread.value.findIndex(
			(item) => item[0].value.wam_id === chat[0].tempMessageId,
		)
		if (tempChatIndex !== -1) {
			chatThread.value[tempChatIndex] = chat
		} else {
		}
	}
}

// 1s: يوازن بين استجابة سريعة وتجميع أحداث متتالية (تجنب طلبات متكررة)
// const refetchChatsList = debounce(async () => {
// 	try {
// 		const response = await axios.get('/chats')
// 		if (response?.data?.result) {
// 			rows.value = response.data.result
// 		}
// 	} catch (error) {
// 		// تجاهل أخطاء تحديث القائمة
// 	}
// }, 1500)

const updateSidePanel = async (chat, statusChanged) => {
	console.log('event chat', chat)
	const isChatFormOpen = contact.value && contact.value.id == chat[0].value.contact_id
	let currentContact = rows.value.data.find(row => row.id === chat[0].value.contact_id)
	let currentChat = chat[0].value
	const isInboundChat = currentChat.type === 'inbound'

	//	const isCurrentContact = contact.value && contact.value.id === chat[0].value.contact_id
	if (isChatFormOpen) {
		updateChatThread(chat)
		currentContact.last_chat = currentChat
		if (isInboundChat) {

		}

	}

	if (statusChanged) {
		console.log('status changed do nothing')
		return false
	}
	/**
	 * ! Temp 
	 */
	// if (isChatFormOpen) {
	// 	contact.value.last_inbound_chat = currentChat
	// 	contact.value.last_inbound_chat_created_at = currentChat.created_at
	// }

	if (isInboundChat) {
		const inboundIso = new Date().toISOString()
		//	console.log('isInboundChat', currentContact)
		if (currentContact) {
			currentContact.last_chat = currentChat
			currentContact.last_inbound_chat = {
				created_at: inboundIso,
				created_at_iso: inboundIso,
			}
			currentContact.unread_messages = currentContact.unread_messages + 1
			currentContact.last_inbound_chat_created_at = inboundIso
			currentContact.last_inbound_chat_created_at_iso = inboundIso
			currentContact.is_messaging_window_open = true
			currentContact.latest_chat_created_at = currentChat.created_at
			if (isChatFormOpen) {
				contact.value.last_inbound_chat = {
					created_at: inboundIso,
					created_at_iso: inboundIso,
				}
				contact.value.last_inbound_chat_created_at = inboundIso
				contact.value.last_inbound_chat_created_at_iso = inboundIso
				contact.value.is_messaging_window_open = true
			}
		} else if (chat[0].value.contact_uuid) {
			rows.value.data.push({
				id: currentChat.contact_id,
				uuid: chat[0].value.contact_uuid,
				last_inbound_chat: {
					created_at: inboundIso,
					created_at_iso: inboundIso,
				},
				last_chat: currentChat,
				unread_messages: 1,
				last_inbound_chat_created_at: inboundIso,
				last_inbound_chat_created_at_iso: inboundIso,
				is_messaging_window_open: true,
				latest_chat_created_at: currentChat.created_at,
				full_name: currentChat.contact_full_name,
				ticket_status: null,
				ticket_assigned_to: null,
			})
		}



	}

	//console.log(rows.value.data, chat[0], '*---*', currentChat.contact_id)



	// console.log('refetching chats list')
	// console.log('chat', chat, '*---*', rows.value)
	//	refetchChatsList()

	//	if (isCurrentContact && !statusChanged) {
	//	console.log('updateChatThread', isCurrentContact)
	//	updateChatThread(chat)
	// تحديث حالة رسالة في المحادثة الحالية — لا حاجة لإعادة جلب القائمة
	//	return
	//	}
	// updateChatThread(chat)
	// refetchChatsList()

}


onMounted(() => {
	try {
		if (!props.organizationId) return

		const { subscribe } = getOrJoinChatChannel(
			props.organizationId,
			props.user.id,
			props.pusherSettings.pusher_app_key,
			props.pusherSettings.pusher_app_cluster,
		)
		unsubscribeChatChannel.value = subscribe((event) => {
			updateSidePanel(event.chat, event.statusChanged)
		})

		scrollToBottom()
	} catch (error) {
		// تجاهل
	}
})

onUnmounted(() => {
	try {
		if (typeof unsubscribeChatChannel.value === 'function') {
			unsubscribeChatChannel.value()
		}
		unsubscribeChatChannel.value = null
	} catch (error) {
		// تجاهل
	}
	try {
		if (typeof scrollResizeCleanup.value === 'function') {
			scrollResizeCleanup.value()
		}
		scrollResizeCleanup.value = null
	} catch (error) {
		// تجاهل
	}
})

</script>
