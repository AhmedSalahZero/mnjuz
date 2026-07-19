<template>
	<div :class="rtlClass">
		<div v-if="adminOrganizationImpersonation"
			class="sticky top-0 z-40 border-b border-slate-200 bg-slate-50">
			<div
				class="mx-auto flex max-w-[100rem] flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:py-2.5 md:px-6"
				role="status"
				aria-live="polite">
				<div class="flex min-w-0 flex-1 items-start gap-2.5 sm:items-center">
					<span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-primary sm:mt-0.5" aria-hidden="true" />
					<p class="min-w-0 flex-1 text-sm leading-relaxed text-slate-600">
						<span class="font-semibold text-slate-900">{{ $t('Admin preview mode') }}</span>
						<template v-if="impersonationOrgName">
							<span class="text-slate-300"> · </span>
							<span class="font-medium text-slate-800">{{ impersonationOrgName }}</span>
						</template>
						<span class="text-slate-300"> — </span>
						<span>{{ $t('You are viewing this workspace as an administrator.') }}</span>
					</p>
				</div>
				<button type="button"
					class="shrink-0 self-stretch rounded-md border border-slate-300 bg-white px-3 py-1.5 text-center text-xs font-medium text-slate-800 shadow-sm transition-colors hover:border-slate-400 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 sm:self-center sm:px-4 md:text-sm"
					:disabled="exitForm.processing" @click="submitExitPreview">
					{{ $t('Return to organizations') }}
				</button>
			</div>
		</div>
		<MobileSidebar :user="user" :config="config" :organization="organization" :organizations="organizations"
			:title="currentPageTitle" :displayCreateBtn="displayCreateBtn" :displayTopBar="viewTopBar"></MobileSidebar>
		<div class="md:mt-0 md:pt-0 flex md:h-screen w-full tracking-[0.3px] bg-gray-300/10"
			:class="viewTopBar === false ? 'mt-0 pt-0' : ''">
			<Sidebar :user="user" :config="config" :organization="organization" :organizations="organizations"
				:unreadMessages="unreadMessages"></Sidebar>
			<div class="md:min-h-screen flex flex-col w-full min-w-0">
				<slot :user="user" :toggleNavBar="toggleTopBar"></slot>
			</div>
		</div>
		<audio ref="audioPlayer" allow="autoplay"></audio>
	</div>
</template>
<script setup>
import { useRtl } from '@/Composables/useRtl'
import { useForm, usePage } from "@inertiajs/vue3"
import { default as axios } from 'axios'
import { computed, onMounted, onUnmounted, provide, ref, watch } from 'vue'
import { toast } from 'vue3-toastify'
import 'vue3-toastify/dist/index.css'
import { getOrJoinChatChannel } from '../../../echo'
import MobileSidebar from "./MobileSidebar.vue"
import Sidebar from "./Sidebar.vue"

const { rtlClass, isRtl } = useRtl()

const viewTopBar = ref(true)
const user = computed(() => usePage().props.auth.user)
const config = computed(() => usePage().props.config)
const organization = computed(() => usePage().props.organization)
const organizations = computed(() => usePage().props.organizations)
const adminOrganizationImpersonation = computed(() => usePage().props.admin_organization_impersonation)
const impersonationOrgName = computed(() => usePage().props.admin_impersonation_org_name || '')
const exitForm = useForm({})

const submitExitPreview = () => {
	exitForm.post('/admin-exit-organization-preview')
}
const currentPageTitle = computed(() => usePage().props.title)
const displayCreateBtn = computed(() => usePage().props.allowCreate)
const unreadMessages = ref(usePage().props.unreadMessages)

// إعادة مزامنة العدّاد العام من الخادم عند أي إعادة تحميل جزئية للصفحة،
// لتجنّب انحراف العدّاد بسبب التحديثات المتفائلة عبر الويب سوكِت.
watch(
	() => usePage().props.unreadMessages,
	(val) => {
		if (val !== undefined && val !== null) {
			unreadMessages.value = val
		}
	}
)

const audioPlayer = ref(null)

watch(
	() => usePage().props.flash,
	() => {
		if (usePage().props.flash?.status != null) {
			toast(usePage().props.flash.status.message, { autoClose: 3000 })
		}
	},
	{ deep: true }
)

const toggleTopBar = () => {
	viewTopBar.value = !viewTopBar.value
}

const getValueByKey = (key) => {
	const found = config.value.find(item => item.key === key)
	return found ? found.value : ''
}

const setupSound = () => {
	if (!organization.value) return
	const notifications = organization.value.notifications || {}

	if (notifications?.enable_sound && audioPlayer.value) {
		audioPlayer.value.src = notifications?.tone
		audioPlayer.value.volume = notifications?.volume || 1.0
	}
}

const playSound = () => {
	if (audioPlayer.value) {
		audioPlayer.value.play().catch((error) => {
			console.warn("Audio playback failed:", error)
		})
	}
}
provide('updateTotalUnreadMessages', (val) => {
	unreadMessages.value -= val
})

// نبضة نشاط لقياس أداء الموظفين (مفعّلة فقط عند اشتراك المنظمة في الميزة).
// نرسل نبضة فقط عندما تكون النافذة مرئية لتمثيل الوقت النشط الفعلي.
let heartbeatTimer = null
const sendHeartbeat = () => {
	if (document.visibilityState !== 'visible') return
	axios.post('/performance/heartbeat').catch(() => { })
}
const setupActivityHeartbeat = () => {
	if (!organization.value?.plan?.features?.agent_performance) return
	sendHeartbeat()
	heartbeatTimer = setInterval(sendHeartbeat, 60000)
	document.addEventListener('visibilitychange', sendHeartbeat)
}

onUnmounted(() => {
	if (heartbeatTimer) clearInterval(heartbeatTimer)
	document.removeEventListener('visibilitychange', sendHeartbeat)
})

onMounted(() => {
	setupSound()

	if (!organization.value?.id || !user.value?.id) {
		return
	}

	const { subscribe } = getOrJoinChatChannel(
		organization.value.id,
		user.value.id,
		getValueByKey('pusher_app_key'),
		getValueByKey('pusher_app_cluster')
	)
	subscribe((event) => {
		const chat = event.chat
		if (chat[0].value.deleted_at == null && chat[0].value.type === 'inbound') {
			playSound()
			unreadMessages.value += 1
		}
	})

	setupActivityHeartbeat()

	// const SB_BASE = 'https://business.waz.com.sa/support'
	// const loadExternalScript = (src, id) =>
	// 	new Promise((resolve, reject) => {
	// 		if (id && document.getElementById(id)) {
	// 			resolve()
	// 			return
	// 		}
	// 		const el = document.createElement('script')
	// 		el.src = src
	// 		if (id) el.id = id
	// 		el.onload = () => resolve()
	// 		el.onerror = () => reject(new Error(`Failed to load script: ${src}`))
	// 		document.body.appendChild(el)
	// 	})

	// window.SB_INIT_URL = SB_BASE
	// loadExternalScript(`${SB_BASE}/js/min/jquery.min.js`, 'sb-support-jquery')
	// 	.then(() => loadExternalScript(`${SB_BASE}/js/main.js`, 'sbinit'))
	// 	.catch((e) => console.warn('Support board widget failed to load:', e))
})
</script>
