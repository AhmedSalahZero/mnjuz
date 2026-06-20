<script setup>
import Pagination from '@/Components/Pagination.vue'
import SortDirectionToggle from '@/Components/SortDirectionToggle.vue'
import TicketStatusToggle from '@/Components/TicketStatusToggle.vue'
import { Link, router } from '@inertiajs/vue3'
import debounce from 'lodash/debounce'
import { ref, watch, inject, computed, onMounted, onUnmounted, nextTick } from 'vue'

const ROW_HEIGHT_PX = 72
const OVERSCAN = 8

function getInitialStatusFilter(statusProp) {
	const params = new URLSearchParams(window.location.search)
	if (params.has('is_read') && params.get('is_read') === '0') return 'unread'
	return statusProp ?? 'all'
}

const props = defineProps({
	rows: {
		type: Object,
		required: true,
	},
	filters: {
		type: Object,
	},
	rowCount: {
		type: Number,
		required: true,
	},
	ticketingIsEnabled: {
		type: Boolean,
	},
	status: {
		type: String,
	},
	chatSortDirection: {
		type: String,
	},
	contactCategoriesEnabled: {
		type: Boolean,
		default: false,
	},
	hasMoreContacts: {
		type: Boolean,
		default: false,
	},
	isLoadingMoreContacts: {
		type: Boolean,
		default: false,
	},
})

const isSearching = ref(false)
const selectedContact = ref(null)
const scrollContainer = ref(null)
const loadMoreSentinel = ref(null)
const categoryFilterUuid = ref(null)
const emit = defineEmits(['view', 'category-filter-change', 'load-more-contacts'])

let loadMoreObserver = null
let loadMoreRequested = false

function viewChat(contact) {
	selectedContact.value = contact
	emit('view', contact)
}

function setCategoryFilter(event, uuid) {
	if (event) {
		event.preventDefault()
		event.stopPropagation()
	}
	categoryFilterUuid.value = uuid
	emit('category-filter-change', true)
}

function clearCategoryFilter() {
	categoryFilterUuid.value = null
	emit('category-filter-change', false)
}

const contentType = (metadata) => {
	try {
		if (metadata == null || typeof metadata !== 'string') return null
		const chatData = JSON.parse(metadata)
		return chatData?.type ?? null
	} catch {
		return null
	}
}

const content = (metadata) => {
	try {
		if (metadata == null || typeof metadata !== 'string') return {}
		return JSON.parse(metadata) || {}
	} catch {
		return {}
	}
}

const getExtension = (fileFormat) => {
	const formatMap = {
		'text/plain': 'TXT',
		'application/pdf': 'PDF',
		'application/vnd.ms-powerpoint': 'PPT',
		'application/msword': 'DOC',
		'application/vnd.ms-excel': 'XLS',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOCX',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PPTX',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
	}

	return formatMap[fileFormat] || 'Unknown'
}

const getContactDisplayName = (metadata) => {
	try {
		const data = content(metadata)
		const contacts = data?.contacts
		if (!Array.isArray(contacts) || contacts.length === 0) return '—'
		if (contacts.length === 1) {
			const contact = contacts[0]
			const name = contact?.name
			return name?.formatted_name || (name ? `${name.first_name || ''} ${name.last_name || ''}`.trim() : '—') || '—'
		}
		const firstName = contacts[0]?.name?.first_name || ''
		return `${firstName} and ${contacts.length - 1} other contacts`
	} catch {
		return '—'
	}
}
const updateTotalUnreadMessages = inject('updateTotalUnreadMessages')

function openChat(contact) {
	let currentUnreadMessages = contact.unread_messages
	updateTotalUnreadMessages(currentUnreadMessages)
	contact.unread_messages = 0
	router.visit('/chats/' + contact.uuid, {
		only: ['contact', 'chatThread', 'hasMoreMessages', 'nextPage', 'ticket', 'unreadMessages', 'flash'],
		preserveState: true,
		preserveScroll: true,
	})

	// router.reload({ only: ['contact', 'chatThread', 'hasMoreMessages', 'nextPage', 'ticket', 'unreadMessages', 'flash'] })
}
const formatTime = (time) => {
	if (!time) {
		return 'Invalid time'
	} else {
		const currentTime = new Date()
		const targetTime = new Date(time)
		const timeDiff = currentTime - targetTime

		// Check if the time is today
		if (
			targetTime.getDate() === currentTime.getDate() &&
			targetTime.getMonth() === currentTime.getMonth() &&
			targetTime.getFullYear() === currentTime.getFullYear()
		) {
			return formatDate(targetTime, 'h:mm a')
		}

		// Check if the time is yesterday
		const yesterday = new Date()
		yesterday.setDate(currentTime.getDate() - 1)
		if (
			targetTime.getDate() === yesterday.getDate() &&
			targetTime.getMonth() === yesterday.getMonth() &&
			targetTime.getFullYear() === yesterday.getFullYear()
		) {
			return 'Yesterday'
		}

		// If beyond yesterday, return the time in the format d/m/y
		return formatDate(targetTime, 'd/m/y')
	}
}

const formatDate = (date, format) => {
	let options = null
	if (format === 'h:mm a') {
		options = { hour12: true, hour: 'numeric', minute: 'numeric' }
	} else {
		options = { day: 'numeric', month: 'numeric', year: 'numeric' }
	}

	return new Intl.DateTimeFormat('en-US', options).format(date)
}

const queryParamsString = window.location.search

// Watch for changes in the query parameters
watch(
	() => queryParamsString,
	(newQuery, oldQuery) => {
		// Perform actions based on query parameter changes
	},
)

const params = ref({
	search: props.filters.search,
})

const search = debounce(() => {
	isSearching.value = true
	runSearch()
}, 1000)

const runSearch = () => {
	const url = window.location.pathname
	router.visit(url, {
		method: 'get',
		data: params.value,
	})
}

const clearSearch = () => {
	params.value.search = null
	runSearch()
}
const currentSortDirection = ref(props.chatSortDirection)
const sortChanged = (value) => {
	currentSortDirection.value = value
}

// فلترة من جهة العميل عند تفعيل التذاكر
const statusFilter = ref(getInitialStatusFilter(props.status))
watch(() => props.status, (newVal) => {
	statusFilter.value = getInitialStatusFilter(newVal)
}, { immediate: false })

const filteredRows = computed(() => {
	let data = props.rows?.data ?? []
	if (props.contactCategoriesEnabled && categoryFilterUuid.value) {
		data = data.filter((c) => {
			const cats = c.contact_categories ?? []
			return cats.some((cat) => (cat.uuid || cat.id) === categoryFilterUuid.value)
		})
	}
	if (!props.ticketingIsEnabled) return data
	const filter = statusFilter.value
	if (filter === 'all') return data
	if (filter === 'unread') return data.filter((c) => (c.unread_messages ?? 0) > 0)
	if (filter === 'unassigned') return data.filter((c) => c.ticket_assigned_to == null)
	if (filter === 'open') return data.filter((c) => c.ticket_status === 'open')
	if (filter === 'closed') return data.filter((c) => c.ticket_status === 'closed')
	return data
})

const sortedContacts = computed(() => {
	const list = (props.ticketingIsEnabled || categoryFilterUuid.value) ? filteredRows.value : (props.rows?.data ?? [])
	return [...list].sort((a, b) => {
		if (currentSortDirection.value == 'asc') {
			return new Date(a.latest_chat_created_at) - new Date(b.latest_chat_created_at)
		}
		return new Date(b.latest_chat_created_at) - new Date(a.latest_chat_created_at)
	})
})

const displayedRowCount = computed(() => {
	if (props.contactCategoriesEnabled && categoryFilterUuid.value) return filteredRows.value.length
	return props.ticketingIsEnabled ? filteredRows.value.length : props.rowCount
})

function onFilterChange(value) {
	statusFilter.value = value
	const url = value === 'all' ? '/chats' : value === 'unread' ? '/chats?is_read=0' : '/chats?status=' + value
	window.history.replaceState(null, '', url)
}

// Virtual list: نعرض فقط الصفوف المرئية لتسريع الـ render مع آلاف الـ contacts
const scrollTop = ref(0)
const containerHeight = ref(400)

const virtualList = computed(() => {
	const list = sortedContacts.value
	const total = list.length
	if (total === 0) {
		return { totalHeight: 0, offsetY: 0, visibleItems: [] }
	}
	const start = Math.max(0, Math.floor(scrollTop.value / ROW_HEIGHT_PX) - OVERSCAN)
	const visibleCount = Math.ceil(containerHeight.value / ROW_HEIGHT_PX) + OVERSCAN * 2
	const end = Math.min(total, start + visibleCount)
	const visibleItems = list.slice(start, end).map((contact, i) => ({ contact, index: start + i }))
	return {
		totalHeight: total * ROW_HEIGHT_PX,
		offsetY: start * ROW_HEIGHT_PX,
		visibleItems,
	}
})

function requestLoadMore() {
	if (!props.hasMoreContacts || props.isLoadingMoreContacts || loadMoreRequested) return
	loadMoreRequested = true
	emit('load-more-contacts')
}

function ensureScrollableOrLoadMore() {
	const el = scrollContainer.value
	if (!el || !props.hasMoreContacts || props.isLoadingMoreContacts) return

	const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight
	if (el.scrollHeight <= el.clientHeight + 8 || distanceFromBottom < 320) {
		requestLoadMore()
	}
}

function setupLoadMoreObserver() {
	if (loadMoreObserver) {
		loadMoreObserver.disconnect()
		loadMoreObserver = null
	}

	const root = scrollContainer.value
	const target = loadMoreSentinel.value
	if (!root || !target || !props.hasMoreContacts) return

	loadMoreObserver = new IntersectionObserver(
		(entries) => {
			if (entries.some((entry) => entry.isIntersecting)) {
				requestLoadMore()
			}
		},
		{ root, rootMargin: '240px 0px', threshold: 0 },
	)

	loadMoreObserver.observe(target)
}

function onScroll() {
	const el = scrollContainer.value
	if (el) {
		scrollTop.value = el.scrollTop
		if (!containerHeight.value && el.clientHeight) containerHeight.value = el.clientHeight
		if (props.hasMoreContacts && !props.isLoadingMoreContacts) {
			const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight
			if (distanceFromBottom < 320) {
				requestLoadMore()
			}
		}
	}
}

const onScrollThrottled = debounce(onScroll, 120)

function updateContainerHeight() {
	const el = scrollContainer.value
	if (el) containerHeight.value = el.clientHeight
}

onMounted(() => {
	updateContainerHeight()
	requestAnimationFrame(() => {
		updateContainerHeight()
		setupLoadMoreObserver()
		ensureScrollableOrLoadMore()
	})
	window.addEventListener('resize', updateContainerHeight)
})
onUnmounted(() => {
	loadMoreObserver?.disconnect()
	window.removeEventListener('resize', updateContainerHeight)
})

watch(
	() => [props.hasMoreContacts, props.isLoadingMoreContacts, sortedContacts.value.length],
	() => {
		if (!props.isLoadingMoreContacts) {
			loadMoreRequested = false
		}
		nextTick(() => {
			updateContainerHeight()
			setupLoadMoreObserver()
			if (!props.isLoadingMoreContacts) {
				ensureScrollableOrLoadMore()
			}
		})
	},
)
</script>
<template>
	<div class="flex flex-col h-full min-h-0">
	<div class="px-4 py-4 border-b shrink-0">
		<div class="flex items-center justify-between space-x-1 text-xl">
			<div class="flex space-x-1 items-center flex-wrap gap-2">
				<h2>{{ $t('Chats') }}</h2>
				<span class="text-slate-500">{{ displayedRowCount }}</span>
				<button v-if="contactCategoriesEnabled && categoryFilterUuid" type="button"
					class="text-xs px-2 py-1 rounded bg-slate-200 hover:bg-slate-300 text-slate-700"
					@click="clearCategoryFilter">
					{{ $t('Reset filter') }}
				</button>
			</div>
		</div>
		<div class="bg-slate-50 rounded-md mt-3 flex items-center py-[2px]">
			<div class="pl-3 py-2">
				<svg class="text-slate-600" xmlns="http://www.w3.org/2000/svg" width="20" height="20"
					viewBox="0 0 24 24">
					<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
						stroke-width="2" d="M3 10a7 7 0 1 0 14 0a7 7 0 1 0-14 0m18 11l-6-6" />
				</svg>
			</div>
			<input @input="search" v-model="params.search"
				class="w-full bg-slate-50 outline-none rounded-xl py-2 pl-2 mr-2 text-sm" type="text"
				:placeholder="$t('Search name or number...')" />
			<button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
					<path fill="currentColor"
						d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z" />
				</svg>
			</button>
			<span v-if="isSearching" class="pr-2">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
					<circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
						<animateTransform attributeName="transform" calcMode="discrete" dur="2.4s"
							repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12" />
						<animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite"
							values="1;1;0" />
					</circle>
					<circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
						<animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s"
							repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12" />
						<animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1"
							repeatCount="indefinite" values="1;1;0" />
					</circle>
					<circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
						<animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s"
							repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12" />
						<animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1"
							repeatCount="indefinite" values="1;1;0" />
					</circle>
				</svg>
			</span>
		</div>
		<div v-if="ticketingIsEnabled" class="grid grid-cols-2 mt-4 items-center w-full">
			<TicketStatusToggle :status="statusFilter" :rowCount="displayedRowCount" @filter-change="onFilterChange" />
			<div class="flex ml-auto gap-x-1">
				<!--<span class="cursor-pointer hover:bg-slate-50 p-1 rounded-full">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 5.6c0-.56 0-.84-.11-1.054a.998.998 0 0 0-.436-.437C19.24 4 18.96 4 18.4 4H5.6c-.56 0-.84 0-1.054.109a1 1 0 0 0-.437.437C4 4.76 4 5.04 4 5.6v.737c0 .245 0 .367.028.482a1 1 0 0 0 .12.29c.061.1.148.187.32.36l5.063 5.062c.173.173.26.26.321.36c.055.09.096.188.12.29c.028.114.028.235.028.474v4.756c0 .857 0 1.286.18 1.544a1 1 0 0 0 .674.416c.311.046.695-.145 1.461-.529l.8-.4c.322-.16.482-.24.599-.36a1 1 0 0 0 .231-.374c.055-.158.055-.338.055-.697v-4.348c0-.245 0-.367.028-.482a.998.998 0 0 1 .12-.29c.06-.1.147-.186.317-.356l.004-.004l5.063-5.062c.172-.173.258-.26.32-.36a.994.994 0 0 0 .12-.29C20 6.706 20 6.584 20 6.345z"/></svg>
                </span>-->
				<SortDirectionToggle @sortChanged="sortChanged" :direction="props.chatSortDirection"
					:url="'/chats/update-sort-direction'" />
			</div>
		</div>
	</div>
	<div class="flex-1 min-h-0 overflow-y-auto" ref="scrollContainer" @scroll="onScrollThrottled">
		<div :style="{ height: virtualList.totalHeight + 'px', position: 'relative' }">
			<div :style="{ transform: `translateY(${virtualList.offsetY}px)` }">
				<a v-for="item in virtualList.visibleItems" :key="item.contact.uuid"
					:href="'/chats/' + item.contact.uuid"
					class="block border-b cursor-pointer group-hover:pr-0 no-underline text-inherit"
					:class="item.contact.unread_messages > 0 ? 'bg-green-50' : ''"
					:style="{ height: ROW_HEIGHT_PX + 'px', minHeight: ROW_HEIGHT_PX + 'px' }"
					@click="(e) => { if (e.ctrlKey || e.metaKey || e.which === 2) return; e.preventDefault(); openChat(item.contact); }">
					<div class="flex space-x-2 hover:bg-gray-50 overflow-auto cursor-pointer py-3 px-4 h-full">
						<div class="w-[15%] flex items-center gap-1">
							<img v-if="item.contact.avatar" class="rounded-full w-10 h-10" :src="item.contact.avatar" />
							<div v-else
								class="rounded-full w-10 h-10 flex items-center justify-center bg-slate-200 capitalize">
								{{ item.contact.full_name?.substring(0, 1) || '?' }}
							</div>
							<svg v-if="item.contact.is_blocked" width="20" height="20" viewBox="0 0 24 24" fill="none"
								xmlns="http://www.w3.org/2000/svg">
								<path
									d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z"
									stroke="#DC2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
								<path d="M4.92993 4.92999L19.07 19.07" stroke="#DC2626" stroke-width="2"
									stroke-linecap="round" stroke-linejoin="round" />
							</svg>
						</div>
						<div class="w-[85%] min-w-0">
							<div class="flex justify-between items-start gap-2 flex-wrap">
								<div class="min-w-0 flex-1 flex items-center gap-2 flex-wrap">
									<h3 class="truncate">{{ item.contact.full_name }}</h3>
									<div v-if="contactCategoriesEnabled && (item.contact.contact_categories?.length > 0)"
										class="flex flex-wrap gap-1 items-center">
										<span v-for="cat in (item.contact.contact_categories || [])"
											:key="cat.uuid || cat.id"
											class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium cursor-pointer hover:opacity-90 border"
											role="button" tabindex="0"
											@click.stop.prevent="setCategoryFilter($event, cat.uuid || cat.id)"
											@keydown.enter.prevent="setCategoryFilter($event, cat.uuid || cat.id)"
											@keydown.space.prevent="setCategoryFilter($event, cat.uuid || cat.id)"
											:style="{
												backgroundColor: cat.background_color ?? '#22c55e',
												color: cat.text_color ?? '#ffffff',
												borderColor: cat.background_color ?? '#22c55e'
											}">
											{{ cat.name }}
										</span>
									</div>
								</div>
								<span
									class="self-start text-slate-500 text-xs">{{ formatTime(item.contact?.last_chat?.created_at) }}
								</span>
							</div>
							<div v-if="item.contact?.last_chat?.deleted_at === null" class="flex justify-between">
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'text'"
									class="text-slate-500 text-xs truncate self-end">
									{{ content(item.contact?.last_chat?.metadata).text?.body ?? '' }}
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'button'"
									class="text-slate-500 text-xs truncate self-end">
									{{ content(item.contact?.last_chat?.metadata).button?.text ?? '' }}
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'interactive'"
									class="text-slate-500 text-xs truncate self-end">
									{{ content(item.contact?.last_chat?.metadata).interactive?.button_reply?.title ||
										content(item.contact?.last_chat?.metadata).interactive?.list_reply?.title
									}}
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'image'"
									class="text-slate-500 text-xs truncate self-end">
									<div class="flex items-center">
										<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
											viewBox="0 0 24 24">
											<path fill="currentColor"
												d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.9 13.98l2.1 2.53l3.1-3.99c.2-.26.6-.26.8.01l3.51 4.68a.5.5 0 0 1-.4.8H6.02c-.42 0-.65-.48-.39-.81L8.12 14c.19-.26.57-.27.78-.02z" />
										</svg>
										<span class="ml-2">{{ $t('Photo') }}</span>
									</div>
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'document'"
									class="text-slate-500 text-xs truncate self-end">
									<div class="flex items-center">
										<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
											viewBox="0 0 24 24">
											<path fill="currentColor" fill-rule="evenodd"
												d="M14.25 2.5a.25.25 0 0 0-.25-.25H7A2.75 2.75 0 0 0 4.25 5v14A2.75 2.75 0 0 0 7 21.75h10A2.75 2.75 0 0 0 19.75 19V9.147a.25.25 0 0 0-.25-.25H15a.75.75 0 0 1-.75-.75V2.5Zm.75 9.75a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1 0-1.5h6Zm0 4a.75.75 0 0 1 0 1.5H9a.75.75 0 0 1 0-1.5h6Z"
												clip-rule="evenodd" />
											<path fill="currentColor"
												d="M15.75 2.824c0-.184.193-.301.336-.186c.121.098.23.212.323.342l3.013 4.197c.068.096-.006.22-.124.22H16a.25.25 0 0 1-.25-.25V2.824Z" />
										</svg>
										<span class="ml-2">{{ getExtension(item.contact?.last_chat?.metadata?.type) }}
											{{ $t('File') }}</span>
									</div>
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'video'"
									class="text-slate-500 text-xs truncate self-end">
									<div class="flex items-center">
										<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
											viewBox="0 0 16 16">
											<path fill="currentColor"
												d="M3.5 2.5A2.5 2.5 0 0 0 1 5v6a2.5 2.5 0 0 0 2.5 2.5h5A2.5 2.5 0 0 0 11 11V5a2.5 2.5 0 0 0-2.5-2.5h-5Zm10.684 1.61L12 5.893v4.215l2.184 1.78a.5.5 0 0 0 .816-.389v-7a.5.5 0 0 0-.816-.387Z" />
										</svg>
										<span class="ml-2">{{ $t('Video') }}</span>
									</div>
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'audio'"
									class="text-slate-500 text-xs truncate self-end">
									<div class="flex items-center">
										<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
											viewBox="0 0 512 512">
											<path fill="currentColor"
												d="M256 80C149.9 80 62.4 159.4 49.6 262c9.4-3.8 19.6-6 30.4-6c26.5 0 48 21.5 48 48v128c0 26.5-21.5 48-48 48c-44.2 0-80-35.8-80-80V288C0 146.6 114.6 32 256 32s256 114.6 256 256v112c0 44.2-35.8 80-80 80c-26.5 0-48-21.5-48-48V304c0-26.5 21.5-48 48-48c10.8 0 21 2.1 30.4 6C449.6 159.4 362.1 80 256 80z" />
										</svg>
										<span class="ml-2">{{ $t('Audio') }}</span>
									</div>
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'sticker'"
									class="text-slate-500 text-xs truncate self-end">
									<div class="flex items-center">
										<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
											viewBox="0 0 256 256">
											<path fill="currentColor"
												d="M168 32H88a56.06 56.06 0 0 0-56 56v80a56.06 56.06 0 0 0 56 56h48a8.07 8.07 0 0 0 2.53-.41c26.23-8.75 76.31-58.83 85.06-85.06A8.07 8.07 0 0 0 224 136V88a56.06 56.06 0 0 0-56-56Zm-32 175.42V176a40 40 0 0 1 40-40h31.42c-9.26 21.55-49.87 62.16-71.42 71.42Z" />
										</svg>
										<span class="ml-2">{{ $t('Sticker') }}</span>
									</div>
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'contacts'"
									class="text-slate-500 text-xs truncate self-end">
									<div class="flex items-center">
										<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
											viewBox="0 0 16 16">
											<path fill="currentColor"
												d="M3 14s-1 0-1-1s1-4 6-4s6 3 6 4s-1 1-1 1H3Zm5-6a3 3 0 1 0 0-6a3 3 0 0 0 0 6Z" />
										</svg>
										<span class="ml-2">
											{{ getContactDisplayName(item.contact?.last_chat?.metadata) }}
										</span>
									</div>
								</div>
								<div v-if="contentType(item.contact?.last_chat?.metadata) === 'location'"
									class="text-slate-500 text-xs truncate self-end">
									<div class="flex items-center">
										<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
											viewBox="0 0 16 16">
											<path fill="currentColor"
												d="M9.156 14.544C10.899 13.01 14 9.876 14 7A6 6 0 0 0 2 7c0 2.876 3.1 6.01 4.844 7.544a1.736 1.736 0 0 0 2.312 0ZM6 7a2 2 0 1 1 4 0a2 2 0 0 1-4 0Z" />
										</svg>
										<span class="ml-2">{{ $t('Location') }}</span>
									</div>
								</div>
								<div v-if="!['text', 'button', 'interactive', 'image', 'document', 'video', 'audio', 'sticker', 'contacts', 'location'].includes(contentType(item.contact?.last_chat?.metadata))"
									class="text-slate-500 text-xs truncate self-end">
									<span>{{ $t('Message') }}</span>
								</div>
								<span v-if="item.contact.unread_messages > 0"
									class="bg-green-600 text-white rounded-md py-[1px] px-[8px] min-w-10 text-[10px] flex items-center justify-center">{{ item.contact.unread_messages }}</span>
							</div>
						</div>
					</div>
				</a>
			</div>
		</div>
		<div v-if="hasMoreContacts" ref="loadMoreSentinel" class="h-px w-full" aria-hidden="true"></div>
		<div v-if="isLoadingMoreContacts" class="flex justify-center items-center py-3 text-slate-400 text-sm">
			<svg class="animate-spin mr-2" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
				<path fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-2a8 8 0 0 0-8-8V2Z"/>
			</svg>
			{{ $t('Loading...') }}
		</div>
	</div>
	</div>
	<!-- <div class="px-4 pb-4">
		<Pagination class="mt-3" :pagination="rows.meta" />
	</div> -->
</template>
