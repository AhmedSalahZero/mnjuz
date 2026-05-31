<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg py-4 px-4 text-sm mb-4">
                        <div class="mb-4">
                            <h2 class="text-[17px]">{{ $t('Linked Devices') }}</h2>
                            <span class="text-slate-500">{{ $t('Manage your linked login device and browser session') }}</span>
                        </div>

                        <div v-if="isLoading" class="text-slate-500">{{ $t('Loading...') }}</div>

                        <div v-else-if="!devices.length" class="text-slate-500">
                            {{ $t('No linked device found') }}
                        </div>

                        <div v-else class="flex flex-col gap-3">
                            <div
                                v-for="device in devices"
                                :key="device.id"
                                class="border border-slate-200 rounded-lg p-4"
                            >
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-base font-medium flex items-center gap-2">
                                            <span>{{ device.device_name || $t('Unknown device') }}</span>
                                            <span
                                                class="text-xs px-2 py-0.5 rounded-full"
                                                :class="device.device_category === 'mobile'
                                                    ? 'bg-green-100 text-green-700'
                                                    : 'bg-blue-100 text-blue-700'"
                                            >
                                                {{ device.device_category === 'mobile' ? $t('Mobile') : $t('Web') }}
                                            </span>
                                        </div>
                                        <div class="text-slate-600 mt-1">
                                            {{ device.browser || $t('Unknown browser') }} - {{ device.platform || $t('Unknown platform') }}
                                        </div>
                                        <div class="text-slate-500 mt-1">
                                            {{ $t('Last active') }}: {{ formatDate(device.last_used_at) }}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        @click="confirmRemove(device)"
                                        class="rounded-md bg-red-600 px-3 py-2 text-sm text-white hover:bg-red-700"
                                        :disabled="isRemoving"
                                    >
                                        {{ $t('Remove') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :label="$t('Remove Device')" :isOpen="isOpenRemoveModal" :closeBtn="true" @close="closeModal">
            <div class="pt-4">
                <p class="text-slate-700">{{ $t('Are you sure you want to remove this device? You will be logged out immediately.') }}</p>
                <div class="mt-5 flex gap-2 justify-end">
                    <button
                        type="button"
                        class="rounded-md bg-slate-100 px-4 py-2 text-sm text-slate-700 hover:bg-slate-200"
                        @click="isOpenRemoveModal = false"
                        :disabled="isRemoving"
                    >
                        {{ $t('Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700"
                        @click="removeDevice"
                        :disabled="isRemoving"
                    >
                        {{ isRemoving ? $t('Processing') : $t('Confirm') }}
                    </button>
                </div>
            </div>
        </Modal>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from './Layout.vue'
import Modal from '@/Components/Modal.vue'
import axios from 'axios'
import { onMounted, ref } from 'vue'

const props = defineProps(['modules'])

const isLoading = ref(true)
const isRemoving = ref(false)
const isOpenRemoveModal = ref(false)
const devices = ref([])
const deviceToRemove = ref(null)

const closeModal = () => {
    isOpenRemoveModal.value = false
    deviceToRemove.value = null
}

const confirmRemove = (device) => {
    deviceToRemove.value = device
    isOpenRemoveModal.value = true
}

const fetchDevices = async () => {
    isLoading.value = true
    try {
        const response = await axios.get('/settings/device')
        devices.value = response?.data?.data ?? []
    } catch (error) {
        devices.value = []
    } finally {
        isLoading.value = false
    }
}

const removeDevice = async () => {
    isRemoving.value = true
    try {
        await axios.delete('/settings/device', {
            data: { device_id: deviceToRemove.value?.id }
        })
        window.location.href = '/login'
    } finally {
        isRemoving.value = false
        isOpenRemoveModal.value = false
    }
}

const formatDate = (value) => {
    if (!value) return '-'
    return new Date(value).toLocaleString()
}

onMounted(() => {
    fetchDevices()
})
</script>
