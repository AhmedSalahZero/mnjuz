<template>
    <div class="flex h-screen items-center justify-center bg-slate-50 px-4">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-sm border border-slate-200">
            <h1 class="text-2xl font-semibold text-slate-900">{{ $t('verification.required_title') }}</h1>
            <p class="mt-2 text-sm text-slate-600">
                {{ $t('verification.required') }}
            </p>

            <form class="mt-6 space-y-4" @submit.prevent="requestCode">
                <div>
                    <label class="mb-1 block text-sm text-slate-700">{{ $t('verification.method') }}</label>
                    <select v-model="method" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-primary">
                        <option value="email">{{ $t('Email') }} ({{ maskedEmail }})</option>
                        <option v-if="canUseWhatsapp" value="whatsapp">{{ $t('WhatsApp') }} ({{ maskedPhone }})</option>
                    </select>
                </div>

                <button type="submit" :disabled="sending || cooldown > 0"
                    class="w-full rounded-md bg-primary px-3 py-2 text-sm text-white disabled:opacity-60">
                    <span v-if="cooldown > 0">{{ $t('verification.resend_in') }} {{ cooldown }}s</span>
                    <span v-else>{{ sending ? $t('verification.sending') : $t('verification.send_code') }}</span>
                </button>
            </form>

            <form class="mt-4 space-y-3" @submit.prevent="confirmCode">
                <div>
                    <label class="mb-1 block text-sm text-slate-700">{{ $t('verification.code_label') }}</label>
                    <input v-model="code" maxlength="6" inputmode="numeric"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm tracking-[0.3em] outline-none focus:border-primary"
                        :placeholder="$t('verification.code_placeholder')" />
                </div>

                <div v-if="errorMessage" class="text-xs text-red-600">{{ errorMessage }}</div>
                <div v-if="successMessage" class="text-xs text-green-600">{{ successMessage }}</div>

                <button type="submit" :disabled="verifying"
                    class="w-full rounded-md bg-slate-900 px-3 py-2 text-sm text-white disabled:opacity-60">
                    {{ verifying ? $t('verification.verifying') : $t('verification.verify_continue') }}
                </button>
            </form>
        </div>
    </div>
</template>

<script setup>
import axios from 'axios'
import { ref } from 'vue'
import { useTrans } from '@/Composables/useTrans'

const props = defineProps({
    canUseWhatsapp: Boolean,
    maskedEmail: String,
    maskedPhone: String,
    email: String,
    phone: String,
})

const trans = useTrans()
const method = ref('email')
const code = ref('')
const cooldown = ref(0)
const sending = ref(false)
const verifying = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

let interval = null

const startCooldown = () => {
    cooldown.value = 60
    if (interval) clearInterval(interval)
    interval = setInterval(() => {
        if (cooldown.value <= 1) {
            clearInterval(interval)
            interval = null
            cooldown.value = 0
            return
        }
        cooldown.value -= 1
    }, 1000)
}

const requestCode = async () => {
    errorMessage.value = ''
    successMessage.value = ''
    sending.value = true
    try {
        const payload = {
            method: method.value,
            ...(method.value === 'email' && props.email ? { email: props.email } : {}),
            ...(method.value === 'whatsapp' && props.phone ? { phone: props.phone } : {}),
        }

        const { data } = await axios.post('/verification/request', payload)
        if (data?.success === false) {
            errorMessage.value = data?.message || trans('verification.request_failed')
            return
        }
        successMessage.value = data?.message || trans('verification.sent')
        startCooldown()
    } catch (error) {
        const errors = error?.response?.data?.errors
        if (errors) {
            const firstError = Object.values(errors)?.[0]
            errorMessage.value = Array.isArray(firstError) ? firstError[0] : error?.response?.data?.message
        } else {
            errorMessage.value = error?.response?.data?.message || trans('verification.request_failed')
        }
    } finally {
        sending.value = false
    }
}

const confirmCode = async () => {
    errorMessage.value = ''
    successMessage.value = ''
    if (!/^\d{6}$/.test(code.value)) {
        errorMessage.value = trans('verification.code_digits')
        return
    }

    verifying.value = true
    try {
        await axios.post('/verification/confirm', { code: code.value })
        window.location.href = '/dashboard'
    } catch (error) {
        const errors = error?.response?.data?.errors
        if (errors) {
            const firstError = Object.values(errors)?.[0]
            errorMessage.value = Array.isArray(firstError) ? firstError[0] : error?.response?.data?.message
        } else {
            errorMessage.value = error?.response?.data?.message || trans('verification.invalid')
        }
    } finally {
        verifying.value = false
    }
}
</script>
