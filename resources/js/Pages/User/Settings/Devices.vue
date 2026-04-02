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

                        <div v-else-if="!device" class="text-slate-500">
                            {{ $t('No linked device found') }}
                        </div>

                        <div v-else class="border border-slate-200 rounded-lg p-4">
                            <div class="text-base font-medium">{{ device.device_name || $t('Unknown device') }}</div>
                            <div class="text-slate-600 mt-1">
                                {{ device.browser || $t('Unknown browser') }} - {{ device.platform || $t('Unknown platform') }}
                            </div>
                            <div class="text-slate-500 mt-1">
                                {{ $t('Device Type') }}: {{ formatDeviceType(device.device_type) }}
                            </div>
                            <div class="text-slate-500 mt-1">
                                {{ $t('Last active') }}: {{ formatDate(device.last_used_at) }}
                            </div>

                            <button
                                type="button"
                                @click="isOpenRemoveModal = true"
                                class="mt-4 rounded-md bg-red-600 px-3 py-2 text-sm text-white hover:bg-red-700"
                                :disabled="isRemoving"
                            >
                                {{ $t('Remove Device') }}
                            </button>
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
const device = ref(null)

const closeModal = () => {
    isOpenRemoveModal.value = false
}

const fetchDevice = async () => {
    isLoading.value = true
    try {
        const response = await axios.get('/settings/device')
        device.value = response?.data?.data ?? null
    } catch (error) {
        device.value = null
    } finally {
        isLoading.value = false
    }
}

const removeDevice = async () => {
    isRemoving.value = true
    try {
        await axios.delete('/settings/device')
        window.location.href = '/login'
    } finally {
        isRemoving.value = false
        isOpenRemoveModal.value = false
    }
}

const formatDeviceType = (value) => {
    const map = {
        desktop: 'Desktop',
        mobile: 'Mobile',
        tablet: 'Tablet',
    }
    return map[value] ? map[value] : value || 'Unknown'
}

const formatDate = (value) => {
    if (!value) {
        return '-'
    }

    return new Date(value).toLocaleString()
}

onMounted(() => {
    fetchDevice()
})
</script>
