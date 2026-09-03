<script setup>
import { explainWhatsappError } from '@/Composables/whatsappErrors'
import Modal from '@/Components/Modal.vue'
import Table from '@/Components/Table.vue'
import TableBody from '@/Components/TableBody.vue'
import TableBodyRow from '@/Components/TableBodyRow.vue'
import TableBodyRowItem from '@/Components/TableBodyRowItem.vue'
import TableHeader from '@/Components/TableHeader.vue'
import TableHeaderRow from '@/Components/TableHeaderRow.vue'
import TableHeaderRowItem from '@/Components/TableHeaderRowItem.vue'
import { router } from '@inertiajs/vue3'
import debounce from 'lodash/debounce'
import { ref } from 'vue'
import 'vue3-toastify/dist/index.css'

const props = defineProps({
	rows: {
		type: Object,
		required: true,
	},
	filters: {
		type: Object
	},
	uuid: {
		type: String
	}
})

const params = ref({
	search: props.filters.search,
})

const logs = ref(null)
const messageStatus = ref(null)
const isOpenModal = ref(false)
const isAttemptsModalOpen = ref(false)
const selectedAttempts = ref([])
const isSearching = ref(false)
const emit = defineEmits(['delete'])

const clearSearch = () => {
	params.value.search = null
	runSearch()
}

const search = debounce(() => {
	isSearching.value = true
	runSearch()
}, 1000)

const runSearch = () => {
	router.visit('/campaigns/' + props.uuid, {
		method: 'get',
		data: params.value,
	})
}

const openModal = (status, value) => {
	messageStatus.value = status
	logs.value = value
	isOpenModal.value = true
}

const openAttemptsModal = (attempts) => {
	selectedAttempts.value = Array.isArray(attempts) ? attempts : []
	isAttemptsModalOpen.value = true
}

const getAttemptStatusClass = (status) => {
	if (status === 'success') return 'bg-green-700 text-white'
	if (status === 'failed') return 'bg-red-500 text-white'
	return 'bg-slate-200 text-slate-700'
}

const getStatus = (metadata) => {
	return JSON.parse(metadata).status
}

/**
 * سبب الفشل بلغة صاحب الحساب.
 *
 * سجلّ الحملة كان يعرض «failed» وحدها: الحملة تُقبل من واتساب ثم تفشل عند
 * التسليم لمستلِم بعينه، فيرى الموظّف كلمةً بلا سبب ويظنّ الخلل عندنا —
 * بينما السبب مكتوب في السجلّ ذاته (كود 131049 مثلاً).
 */
const failureReason = (metadata) => explainWhatsappError(metadata)

const getErrorDetails = (metadata) => {
	try {
		return typeof metadata === 'string' ? JSON.parse(metadata) : metadata
	} catch {
		return { data: { error: { message: metadata || 'Unknown error' } } }
	}
}

const getErrorMessage = (details) => {
	if (!details) return '—'
	const d = getErrorDetails(details)
	const err = d?.data?.error || d?.error
	if (!err) return d?.message || '—'
	return err.error_user_msg || err.message || err.error_user_title || '—'
}
</script>
<template>
	<div class="bg-white flex items-center shadow-sm h-10 w-80 rounded-[0.5rem] mb-6 text-sm">
		<span class="pl-3">
			<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
				<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
					d="m15 15l6 6m-11-4a7 7 0 1 1 0-14a7 7 0 0 1 0 14Z" />
			</svg>
		</span>
		<input @input="search" v-model="params.search" type="text" class="outline-none px-4 w-full"
			:placeholder="$t('Search campaigns')">
		<button v-if="isSearching === false && params.search" @click="clearSearch" type="button" class="pr-2">
			<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
				<path fill="currentColor"
					d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10s10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4c-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0c-.4-.4-.4-1 0-1.4l2.3-2.3l-2.3-2.3c-.4-.4-.4-1 0-1.4c.4-.4 1-.4 1.4 0l2.3 2.3l2.3-2.3c.4-.4 1-.4 1.4 0c.4.4.4 1 0 1.4L13.4 12l2.3 2.3z" />
			</svg>
		</button>
		<span v-if="isSearching" class="pr-2">
			<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
				<circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
					<animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite"
						type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12" />
					<animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite"
						values="1;1;0" />
				</circle>
				<circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
					<animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s"
						repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12" />
					<animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite"
						values="1;1;0" />
				</circle>
				<circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0">
					<animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s"
						repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12" />
					<animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite"
						values="1;1;0" />
				</circle>
			</svg>
		</span>
	</div>
	<Table :rows="rows">
		<TableHeader>
			<TableHeaderRow>
				<TableHeaderRowItem :position="'first'" class="hidden sm:table-cell">{{ $t('Contact') }}
				</TableHeaderRowItem>
				<TableHeaderRowItem>{{ $t('Phone') }}</TableHeaderRowItem>
				<TableHeaderRowItem class="hidden sm:table-cell">{{ $t('Last updated') }}</TableHeaderRowItem>
				<TableHeaderRowItem>{{ $t('Retries') }}</TableHeaderRowItem>
				<TableHeaderRowItem>{{ $t('Status') }}</TableHeaderRowItem>
				<TableHeaderRowItem :position="'last'"></TableHeaderRowItem>
			</TableHeaderRow>
		</TableHeader>
		<TableBody>
			<TableBodyRow v-for="(item, index) in rows.data" :key="index">
				<TableBodyRowItem :position="'first'" class="hidden sm:table-cell">{{ item.contact.full_name }}
				</TableBodyRowItem>
				<TableBodyRowItem>
					{{ item.contact.phone }}
				</TableBodyRowItem>
				<TableBodyRowItem class="hidden sm:table-cell">
					<span v-if="item.status === 'success'"
						class="border-b border-dashed border-black">{{ item.chat.created_at }}</span>
					<span v-else class="border-b border-dashed border-black">{{ item.created_at }}</span>
				</TableBodyRowItem>
				<TableBodyRowItem>
					<div class="flex items-center gap-2">
						<span>{{ item.retry_count }}</span>
						<button type="button" class="underline text-xs" @click="openAttemptsModal(item.attempts)">
							{{ $t('View attempts') }}
						</button>
					</div>
				</TableBodyRowItem>
				<TableBodyRowItem>
					<span class="px-2 py-1 text-xs rounded-md capitalize"
						:class="item.status === 'success' ? 'bg-green-700 text-white' : 'bg-red-400 text-white'">
						{{ item.status === 'success' ? item.chat.status : item.status }}
					</span>
				</TableBodyRowItem>
				<TableBodyRowItem>
					<div @click="openModal(item.status, item.status === 'success' ? item.chat?.logs : item.metadata)"
						class="flex items-center underline cursor-pointer">
						<svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24">
							<g fill="currentColor">
								<path
									d="M11 10.98a1 1 0 1 1 2 0v6a1 1 0 1 1-2 0zm1-4.929a1 1 0 1 0 0 2a1 1 0 0 0 0-2" />
								<path fill-rule="evenodd"
									d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10s10-4.477 10-10S17.523 2 12 2M4 12a8 8 0 1 0 16 0a8 8 0 0 0-16 0"
									clip-rule="evenodd" />
							</g>
						</svg>
						<span>{{ $t('More info') }}</span>
					</div>
				</TableBodyRowItem>
			</TableBodyRow>
		</TableBody>
	</Table>
	<Modal :label="$t('Message info')" :isOpen="isOpenModal">
		<div class="max-w-md w-full space-y-8">
			<div class="mt-8 space-y-2">
				<div v-if="messageStatus === 'success'" v-for="(log, index) in logs" class="text-sm border-b pb-2">
					<div class="flex items-center capitalize">
						<svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
							<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
								stroke-width="1.5" d="m1.75 9.75l2.5 2.5m3.5-4l2.5-2.5m-4.5 4l2.5 2.5l6-6.5" />
						</svg>
						<span>{{ $t(getStatus(log.metadata)) }}</span>
					</div>
					<div>{{ log.created_at }}</div>
					<div v-if="failureReason(log.metadata)" class="mt-2 rounded bg-red-50 border border-red-200 p-2 text-red-800">
						<div class="font-medium">
							{{ failureReason(log.metadata).translatable
								? $t(failureReason(log.metadata).explanation)
								: failureReason(log.metadata).explanation }}
						</div>
						<div v-if="failureReason(log.metadata).code" class="mt-1 text-[11px] text-red-600">
							{{ $t('WhatsApp error code') }}: {{ failureReason(log.metadata).code }}
						</div>
					</div>
					<!-- Failed logs 
					<span style="display: flex; flex-direction: column;"
						v-if="JSON.parse(log.metadata).status === 'failed' && JSON.parse(log.metadata).errors">
						<div class="text-sm mb-1 bg-red-800 p-2 rounded text-white">Error:
							{{ JSON.parse(log.metadata).errors[0].message }}</div>
						<div v-if="JSON.parse(log.metadata).errors[0].error_data?.details"
							class="text-sm mb-1 bg-red-800 p-2 rounded text-white">
							{{ JSON.parse(log.metadata).errors[0].error_data?.details }}
						</div>
						<div class="text-sm mb-1 bg-red-800 p-2 rounded text-white" v-else>
							{{ JSON.parse(log.metadata).errors[0].message }}</div>
					</span>
					-->
				</div>
				<div v-else-if="messageStatus === 'failed'">
					<div v-if="failureReason(logs)" class="text-sm mb-3 rounded bg-red-50 border border-red-200 p-2 text-red-800">
						<div class="font-medium">
							{{ failureReason(logs).translatable ? $t(failureReason(logs).explanation) : failureReason(logs).explanation }}
						</div>
						<div v-if="failureReason(logs).code" class="mt-1 text-[11px] text-red-600">
							{{ $t('WhatsApp error code') }}: {{ failureReason(logs).code }}
						</div>
					</div>
					<div class="text-sm mb-3 bg-red-800 p-2 rounded text-white">
						{{ $t('Error') }}: {{ getErrorMessage(logs) }}
					</div>
					<div v-if="getErrorDetails(logs).data?.error?.error_data?.details" class="text-sm text-red-700">
						{{ getErrorDetails(logs).data.error.error_data.details }}
					</div>
				</div>
			</div>
		</div>
		<div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
			<div class="mt-2 w-full">
				<button type="button" @click="isOpenModal = false"
					class="inline-flex float-right justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ $t('Close') }}</button>
			</div>
		</div>
	</Modal>
	<Modal :label="$t('Retry attempts')" :isOpen="isAttemptsModalOpen">
		<div class="max-w-2xl w-full">
			<div v-if="selectedAttempts.length" class="space-y-2 max-h-96 overflow-y-auto pr-1">
				<div v-for="attempt in selectedAttempts" :key="attempt.id" class="border rounded-md p-3 text-sm">
					<div class="flex items-center justify-between mb-1">
						<span class="font-medium">
							{{ $t('Attempt') }} #{{ attempt.attempt_number }}
						</span>
						<span class="px-2 py-1 text-xs rounded-md capitalize" :class="getAttemptStatusClass(attempt.status)">
							{{ attempt.status }}
						</span>
					</div>
					<div class="text-slate-600">{{ attempt.executed_at || '—' }}</div>
					<div v-if="attempt.failure_reason" class="mt-2 text-red-700">
						{{ attempt.failure_reason }}
					</div>
				</div>
			</div>
			<div v-else class="text-sm text-slate-500">
				{{ $t('No attempts recorded yet.') }}
			</div>
		</div>
		<div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
			<div class="mt-2 w-full">
				<button type="button" @click="isAttemptsModalOpen = false"
					class="inline-flex float-right justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ $t('Close') }}</button>
			</div>
		</div>
	</Modal>
</template>
