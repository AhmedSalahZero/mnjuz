<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div v-if="!isFeatureEnabled" class="bg-white border border-slate-200 rounded-lg p-6 text-sm text-slate-600">
                {{ $t('Ice Breakers are not available in your plan.') }}
            </div>

            <div v-else class="flex justify-center items-start">
                <div class="w-full max-w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg text-sm mb-4 overflow-hidden">
                        <div class="px-4 pt-4 pb-2 border-b">
                            <h2 class="text-[17px]">{{ $t('Conversation components') }}</h2>
                            <p class="text-slate-500 mt-1 text-xs">
                                {{ $t('Configure WhatsApp conversation starters that appear when a user opens a chat for the first time.') }}
                            </p>
                        </div>

                        <div class="flex border-b px-4">
                            <button
                                type="button"
                                class="px-4 py-3 text-sm border-b-2 -mb-px"
                                :class="activeTab === 'ice_breakers' ? 'border-primary text-primary font-medium' : 'border-transparent text-slate-500 hover:text-slate-700'"
                                @click="activeTab = 'ice_breakers'"
                            >
                                {{ $t('Conversation starter elements') }}
                            </button>
                            <button
                                type="button"
                                class="px-4 py-3 text-sm border-b-2 -mb-px"
                                :class="activeTab === 'commands' ? 'border-primary text-primary font-medium' : 'border-transparent text-slate-500 hover:text-slate-700'"
                                @click="activeTab = 'commands'"
                            >
                                {{ $t('Commands') }}
                            </button>
                        </div>

                        <div class="grid md:grid-cols-2 gap-6 p-4">
                            <div class="order-2 md:order-1">
                                <IceBreakersPreview
                                    v-if="activeTab === 'ice_breakers'"
                                    :items="items"
                                    :display-name="whatsappProfile.verified_name || organizationName"
                                    :display-phone="whatsappProfile.display_phone_number"
                                />
                                <IceBreakersCommandsPreview
                                    v-else
                                    :items="commandItems"
                                    :display-name="whatsappProfile.verified_name || organizationName"
                                    :display-phone="whatsappProfile.display_phone_number"
                                />
                            </div>

                            <div class="order-1 md:order-2">
                                <template v-if="activeTab === 'ice_breakers'">
                                    <h3 class="text-[15px] mb-1">{{ $t('Conversation starter elements') }}</h3>
                                    <p class="text-xs text-slate-500 mb-4">
                                        {{ $t('Add up to 4 ice breakers. Each can contain up to 80 characters.') }}
                                    </p>

                                    <IceBreakersRepeater :key="iceBreakersKey" v-model="items" :max="4" />
                                </template>

                                <template v-else>
                                    <h3 class="text-[15px] mb-1">{{ $t('Commands') }}</h3>
                                    <p class="text-xs text-slate-500 mb-4">
                                        {{ $t('Add up to 4 bot commands. Users type / followed by the command name in WhatsApp.') }}
                                    </p>

                                    <IceBreakersCommandsRepeater :key="commandsKey" v-model="commandItems" :max="4" />
                                </template>

                                <p v-if="syncStatus" class="mt-3 text-xs" :class="syncStatusClass">
                                    {{ syncStatusLabel }}
                                </p>
                            </div>
                        </div>

                        <div class="flex justify-between items-center px-4 py-4 border-t bg-slate-50">
                            <button
                                type="button"
                                class="rounded-md bg-primary px-4 py-2 text-sm text-white hover:opacity-90 disabled:opacity-50"
                                :disabled="isSaving"
                                @click="save"
                            >
                                {{ isSaving ? $t('Saving...') : $t('Save') }}
                            </button>
                            <Link href="/settings" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                {{ $t('Cancel') }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from './Layout.vue'
import IceBreakersRepeater from '@/Components/IceBreakers/IceBreakersRepeater.vue'
import IceBreakersCommandsRepeater from '@/Components/IceBreakers/IceBreakersCommandsRepeater.vue'
import IceBreakersPreview from '@/Components/IceBreakers/IceBreakersPreview.vue'
import IceBreakersCommandsPreview from '@/Components/IceBreakers/IceBreakersCommandsPreview.vue'
import axios from 'axios'
import { Link, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { toast } from 'vue3-toastify'
import 'vue3-toastify/dist/index.css'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

const props = defineProps({
    modules: Array,
    iceBreakers: {
        type: Array,
        default: () => [],
    },
    commands: {
        type: Array,
        default: () => [],
    },
    whatsappProfile: {
        type: Object,
        default: () => ({}),
    },
    syncStatus: {
        type: Object,
        default: null,
    },
})

const page = usePage()
const activeTab = ref('ice_breakers')
const isSaving = ref(false)
const localSyncStatus = ref(props.syncStatus)
const iceBreakersKey = ref(0)
const commandsKey = ref(0)

const isFeatureEnabled = computed(() => page.props.organization?.plan?.features?.ice_breakers === true)

const organizationName = computed(() => page.props.organization?.name || 'Business')

const items = ref(
    props.iceBreakers.length
        ? props.iceBreakers.map((item) => ({
              id: item.id,
              text: item.text,
              sort_order: item.sort_order,
          }))
        : []
)

const commandItems = ref(
    props.commands.length
        ? props.commands.map((item) => ({
              id: item.id,
              command_name: item.command_name,
              command_description: item.command_description,
              sort_order: item.sort_order,
          }))
        : []
)

const syncStatusClass = computed(() => {
    if (!localSyncStatus.value) {
        return ''
    }
    return localSyncStatus.value.status === 'success' ? 'text-green-600' : 'text-amber-600'
})

const syncStatusLabel = computed(() => {
    if (!localSyncStatus.value) {
        return ''
    }
    if (localSyncStatus.value.status === 'success') {
        const suffix = localSyncStatus.value.synced_at ? ` · ${formatDate(localSyncStatus.value.synced_at)}` : ''
        return `${trans('Last synced successfully')}${suffix}`
    }
    return localSyncStatus.value.message || trans('Last Meta sync failed')
})

const formatDate = (value) => {
    try {
        return new Date(value).toLocaleString()
    } catch {
        return value
    }
}

const saveIceBreakers = async () => {
    const payload = items.value
        .filter((item) => item.text && item.text.trim().length > 0)
        .map((item, index) => ({
            id: item.id,
            text: item.text.trim(),
            sort_order: index,
        }))

    if (payload.some((item) => item.text.length > 80)) {
        toast(trans('Each ice breaker must be 80 characters or less.'), { autoClose: 3000, type: 'error' })
        return false
    }

    const response = await axios.post('/settings/conversations/ice-breakers/sync', { items: payload })

    if (response.data.success) {
        items.value = response.data.data.map((item) => ({
            id: item.id,
            text: item.text,
            sort_order: item.sort_order,
        }))
        iceBreakersKey.value++
        toast(response.data.message, { autoClose: 3000, type: 'success' })
        return true
    }

    toast(response.data.message || trans('Something went wrong'), { autoClose: 3000, type: 'error' })
    return false
}

const saveCommands = async () => {
    const payload = commandItems.value
        .filter((item) => item.command_name?.trim() && item.command_description?.trim())
        .map((item, index) => ({
            id: item.id,
            command_name: item.command_name.trim(),
            command_description: item.command_description.trim(),
            sort_order: index,
        }))

    const response = await axios.post('/settings/conversations/commands/sync', { items: payload })

    if (response.data.success) {
        commandItems.value = response.data.data.map((item) => ({
            id: item.id,
            command_name: item.command_name,
            command_description: item.command_description,
            sort_order: item.sort_order,
        }))
        commandsKey.value++
        toast(response.data.message, { autoClose: 3000, type: 'success' })
        return true
    }

    toast(response.data.message || trans('Something went wrong'), { autoClose: 3000, type: 'error' })
    return false
}

const save = async () => {
    isSaving.value = true

    try {
        if (activeTab.value === 'ice_breakers') {
            await saveIceBreakers()
        } else {
            await saveCommands()
        }
    } catch (error) {
        const message = error.response?.data?.message || trans('Something went wrong')
        toast(message, { autoClose: 3000, type: 'error' })
    } finally {
        isSaving.value = false
    }
}
</script>
