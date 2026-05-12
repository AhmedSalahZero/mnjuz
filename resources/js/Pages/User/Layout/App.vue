<template>
	<div :class="rtlClass">
		<div v-if="adminOrganizationImpersonation"
			class="flex flex-wrap items-center justify-between gap-2 border-b border-amber-700 bg-amber-500 px-3 py-2 text-sm text-white md:px-4">
			<p class="min-w-0 font-medium">
				{{ $t('Admin preview mode') }}
				<span v-if="impersonationOrgName" class="opacity-90"> — {{ impersonationOrgName }}</span>.
				{{ $t('You are viewing this workspace as an administrator.') }}
			</p>
			<button type="button"
				class="shrink-0 rounded-md bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow hover:bg-amber-50"
				:disabled="exitForm.processing" @click="submitExitPreview">
				{{ $t('Return to organizations') }}
			</button>
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
import { usePage, useForm } from "@inertiajs/vue3"
import { computed, onMounted, ref, watch, provide } from 'vue'
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
	const settings = organization.value.metadata ? JSON.parse(organization.value.metadata) : {}
	const notifications = settings.notifications || {}

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
