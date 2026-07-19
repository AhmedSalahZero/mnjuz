<script setup>
import Modal from '@/Components/Modal.vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { toast } from 'vue3-toastify'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

const props = defineProps({
	// مصفوفة جهات الاتصال كما تصل من واتساب (metadata.contacts)
	contacts: { type: Array, default: () => [] },
})

const detailsOpen = ref(false)

const displayName = (contact) => {
	const name = contact?.name || {}
	return name.formatted_name
		|| `${name.first_name || ''} ${name.last_name || ''}`.trim()
		|| trans('Unknown')
}

const headerName = computed(() => {
	if (!props.contacts.length) return trans('No contacts available')
	if (props.contacts.length === 1) return displayName(props.contacts[0])
	return `${displayName(props.contacts[0])} ${trans('and')} ${props.contacts.length - 1} ${trans('other contacts')}`
})

const firstPhone = computed(() => {
	const phones = props.contacts?.[0]?.phones
	return Array.isArray(phones) && phones.length ? (phones[0].phone || phones[0].wa_id || '') : ''
})

const initials = (contact) => {
	const name = displayName(contact)
	return name && name !== '—' ? name.trim().charAt(0).toUpperCase() : '#'
}

const sanitizeNumber = (phone) => (phone || '').replace(/[^\d+]/g, '')

const copyNumber = async (phone) => {
	try {
		await navigator.clipboard.writeText(phone)
		toast.success(trans('Copied to clipboard'))
	} catch {
		toast.error(trans('Failed to copy'))
	}
}

// عند الضغط على زر الواتساب: نفتح المحادثة الداخلية في منجز مع هذا الرقم
// (نبحث عن جهة الاتصال أو ننشئها في الخادم ثم ننتقل إلى /chats/{uuid})
const openConversation = async (contact, phone) => {
	const num = sanitizeNumber(phone)
	if (!num) return
	const name = contact?.name || {}
	try {
		const { data } = await axios.post('/chats/open-by-phone', {
			phone: num,
			first_name: name.first_name || name.formatted_name || null,
			last_name: name.last_name || null,
		})
		if (data?.success && data?.uuid) {
			detailsOpen.value = false
			router.visit(`/chats/${data.uuid}`)
		} else {
			toast.error(data?.message || trans('Something went wrong'))
		}
	} catch (e) {
		toast.error(e?.response?.data?.message || trans('Something went wrong'))
	}
}

const callNumber = (phone) => {
	const num = sanitizeNumber(phone)
	if (!num) return
	window.location.href = `tel:${num}`
}

// تنزيل جهة الاتصال كملف vCard حتى يمكن حفظها في الهاتف
const saveContact = (contact) => {
	const name = contact?.name || {}
	const lines = ['BEGIN:VCARD', 'VERSION:3.0']
	lines.push(`FN:${displayName(contact)}`)
	if (name.first_name || name.last_name) {
		lines.push(`N:${name.last_name || ''};${name.first_name || ''};;;`)
	}
	if (contact?.org?.company) lines.push(`ORG:${contact.org.company}`)
		; (contact?.phones || []).forEach((p) => {
			if (p.phone || p.wa_id) lines.push(`TEL;type=${p.type || 'CELL'}:${p.phone || p.wa_id}`)
		})
		; (contact?.emails || []).forEach((e) => {
			if (e.email) lines.push(`EMAIL;type=${e.type || 'WORK'}:${e.email}`)
		})
	lines.push('END:VCARD')

	const blob = new Blob([lines.join('\n')], { type: 'text/vcard' })
	const url = URL.createObjectURL(blob)
	const a = document.createElement('a')
	a.href = url
	a.download = `${displayName(contact)}.vcf`
	document.body.appendChild(a)
	a.click()
	document.body.removeChild(a)
	URL.revokeObjectURL(url)
}
</script>

<template>
	<div class="w-[290px]">
		<!-- البطاقة المضغوطة -->
		<div class="flex items-center gap-3">
			<div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-slate-200 text-slate-600 font-semibold">
				{{ initials(contacts[0] || {}) }}
			</div>
			<div class="min-w-0">
				<p class="truncate font-medium text-gray-900">{{ headerName }}</p>
				<p v-if="firstPhone" class="truncate text-xs text-slate-500">{{ firstPhone }}</p>
			</div>
		</div>
		<div class="mt-2 border-t pt-2 text-center text-[#00a5f4] cursor-pointer hover:opacity-80"
			@click="detailsOpen = true">
			{{ $t('View') }}
		</div>
	</div>

	<Modal :label="$t('Contact')" :isOpen="detailsOpen" :closeBtn="true" @close="detailsOpen = false">
		<div class="space-y-5 pt-2">
			<div v-for="(contact, i) in contacts" :key="i" class="space-y-3">
				<div class="flex items-center gap-3">
					<div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-green-100 text-green-700 text-lg font-semibold">
						{{ initials(contact) }}
					</div>
					<div class="min-w-0">
						<p class="truncate font-semibold text-gray-900">{{ displayName(contact) }}</p>
						<p v-if="contact?.org?.company" class="truncate text-xs text-slate-500">{{ contact.org.company }}</p>
					</div>
				</div>

				<div v-for="(p, pi) in (contact.phones || [])" :key="pi"
					class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
					<div class="min-w-0">
						<p class="truncate text-sm text-gray-800">{{ p.phone || p.wa_id }}</p>
						<p class="text-[10px] uppercase text-slate-400">{{ p.type || 'Mobile' }}</p>
					</div>
					<div class="flex items-center gap-1">
						<button type="button" :title="$t('Message')" @click="openConversation(contact, p.phone || p.wa_id)"
							class="rounded-full p-2 text-green-600 hover:bg-green-50">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2m0 18.15c-1.48 0-2.93-.4-4.2-1.15l-.3-.18l-3.12.82l.83-3.04l-.2-.31a8.26 8.26 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24s8.24 3.7 8.24 8.24s-3.7 8.24-8.24 8.24m4.52-6.16c-.25-.12-1.47-.72-1.69-.81c-.23-.08-.39-.12-.56.12c-.17.25-.64.81-.79.97c-.14.17-.29.19-.54.06c-.25-.12-1.05-.39-1.99-1.23c-.74-.66-1.23-1.47-1.38-1.72c-.14-.25-.02-.38.11-.51c.11-.11.25-.29.37-.43s.17-.25.25-.41c.08-.17.04-.31-.02-.43c-.06-.12-.56-1.34-.76-1.84c-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31c-.22.25-.86.85-.86 2.07c0 1.22.89 2.4 1.01 2.56c.12.17 1.75 2.67 4.23 3.74c.59.26 1.05.41 1.41.52c.59.19 1.13.16 1.56.1c.48-.07 1.47-.6 1.68-1.18c.21-.58.21-1.07.14-1.18s-.22-.16-.47-.28"/></svg>
						</button>
						<button type="button" :title="$t('Call')" @click="callNumber(p.phone || p.wa_id)"
							class="rounded-full p-2 text-blue-600 hover:bg-blue-50">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57c.11.35.03.74-.25 1.02z"/></svg>
						</button>
						<button type="button" :title="$t('Copy')" @click="copyNumber(p.phone || p.wa_id)"
							class="rounded-full p-2 text-slate-500 hover:bg-slate-200">
							<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M19 21H8V7h11m0-2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2m-3-4H4a2 2 0 0 0-2 2v14h2V3h12z"/></svg>
						</button>
					</div>
				</div>

				<div v-for="(e, ei) in (contact.emails || [])" :key="'e' + ei"
					class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-gray-800">
					{{ e.email }}
				</div>

				<button type="button" @click="saveContact(contact)"
					class="w-full rounded-md border border-green-600 py-2 text-sm font-medium text-green-700 hover:bg-green-50">
					{{ $t('Save contact') }}
				</button>
			</div>
		</div>
	</Modal>
</template>
