<script setup>
/**
 * بريد الحملات: خادم SMTP التاجر نفسه (Gmail، Zoho، Microsoft 365، دومينه).
 *
 * كلمة المرور لا تأتي من الخادم أبداً: الحقل فارغ، وتركه فارغاً يُبقي
 * المحفوظة ما لم يتغيّر الخادم أو المنفذ أو اسم المستخدم.
 */
import { ref, computed } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import SettingLayout from './Layout.vue'
import ChannelIcon from '@/Components/ChannelIcon.vue'
import AlertModal from '@/Components/AlertModal.vue'

const props = defineProps({
    account: { type: Object, default: null },
    smtp: { type: Object, default: null },
    ports: { type: Array, default: () => [465, 587, 2525] },
    modules: { type: Array, default: () => [] },
})

const form = useForm({
    host: props.smtp?.host ?? '',
    port: props.smtp?.port ?? 587,
    encryption: props.smtp?.encryption ?? 'tls',
    username: props.smtp?.username ?? '',
    password: '',
    from_address: props.smtp?.from_address ?? '',
    from_name: props.smtp?.from_name ?? '',
    reply_to: props.smtp?.reply_to ?? '',
})

const testing = ref(false)
const showDisconnect = ref(false)

const connected = computed(() => Boolean(props.account?.is_active))

// 465 = SSL من أوّل الاتصال، وغيره STARTTLS.
const onPort = () => {
    form.encryption = Number(form.port) === 465 ? 'ssl' : 'tls'
}

const save = () => {
    form.post('/settings/channels/email', {
        preserveScroll: true,
        onSuccess: () => form.reset('password'),
    })
}

const sendTest = () => {
    testing.value = true
    router.post('/settings/channels/email/test', {}, {
        preserveScroll: true,
        onFinish: () => {
            testing.value = false
        },
    })
}

const disconnect = () => {
    if (!props.account) return

    router.delete(`/settings/channels/${props.account.uuid}`, { preserveScroll: true })
}
</script>

<template>
    <SettingLayout :modules="props.modules">
        <div class="md:min-h-[90vh]">
            <div class="flex justify-center items-start">
                <div class="md:w-[60em] w-full space-y-4 mb-8">
                    <Link href="/settings/channels" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
                        <span aria-hidden="true">‹</span>
                        <span>{{ $t('Channels') }}</span>
                    </Link>

                    <!-- الرأس -->
                    <section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
                        <div class="flex items-center gap-3 flex-wrap">
                            <ChannelIcon channel="email" :size="32" filled />
                            <div class="min-w-0 flex-1">
                                <h4 class="text-[16px]">{{ $t('Email') }}</h4>
                                <p class="text-slate-500 mt-0.5">
                                    {{ $t('Email campaigns are sent through your own mail server (SMTP). Replies go to your inbox.') }}
                                </p>
                            </div>
                            <span v-if="account" class="text-xs px-2 py-1 rounded-full shrink-0"
                                :class="connected ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'">
                                {{ connected ? $t('Connected') : $t('Needs attention') }}
                            </span>
                        </div>
                        <div v-if="account?.needs_reauthorization || (account && !connected)"
                             class="mt-3 text-xs bg-amber-50 text-amber-800 border border-amber-200 rounded-md px-3 py-2">
                            {{ $t('The mail server stopped accepting the login. Enter the password again below.') }}
                            <div v-if="account.last_error" class="mt-1 opacity-75">{{ account.last_error }}</div>
                        </div>
                    </section>

                    <!-- خادم البريد -->
                    <form @submit.prevent="save" class="bg-white border border-slate-200 rounded-lg p-4 text-sm space-y-4">
                        <h4 class="text-[15px]">{{ $t('Mail server (SMTP)') }}</h4>

                        <div class="grid grid-cols-1 md:grid-cols-6 gap-4">
                            <div class="md:col-span-4">
                                <label class="block text-xs text-slate-600 mb-1">{{ $t('SMTP server') }}</label>
                                <input v-model="form.host" type="text" dir="ltr" autocomplete="off" placeholder="smtp.example.com"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                                <p v-if="form.errors.host" class="text-xs text-red-600 mt-1">{{ form.errors.host }}</p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs text-slate-600 mb-1">{{ $t('Port') }}</label>
                                <select v-model.number="form.port" @change="onPort"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400">
                                    <option v-for="port in ports" :key="port" :value="port">{{ port }}</option>
                                </select>
                                <p v-if="form.errors.port" class="text-xs text-red-600 mt-1">{{ form.errors.port }}</p>
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 mb-1">{{ $t('Username') }}</label>
                                <input v-model="form.username" type="text" dir="ltr" autocomplete="off"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                                <p v-if="form.errors.username" class="text-xs text-red-600 mt-1">{{ form.errors.username }}</p>
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 mb-1">{{ $t('Password') }}</label>
                                <input v-model="form.password" type="password" dir="ltr" autocomplete="new-password"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                                <p v-if="smtp?.has_password" class="text-xs text-slate-400 mt-1">{{ $t('Leave empty to keep the saved password.') }}</p>
                                <p v-if="form.errors.password" class="text-xs text-red-600 mt-1">{{ form.errors.password }}</p>
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 mb-1">{{ $t('From name') }}</label>
                                <input v-model="form.from_name" type="text" dir="auto" maxlength="100"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                                <p v-if="form.errors.from_name" class="text-xs text-red-600 mt-1">{{ form.errors.from_name }}</p>
                            </div>
                            <div class="md:col-span-3">
                                <label class="block text-xs text-slate-600 mb-1">{{ $t('From address') }}</label>
                                <input v-model="form.from_address" type="email" dir="ltr"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                                <p v-if="form.errors.from_address" class="text-xs text-red-600 mt-1">{{ form.errors.from_address }}</p>
                            </div>
                            <div class="md:col-span-6">
                                <label class="block text-xs text-slate-600 mb-1">{{ $t('Reply-to (optional)') }}</label>
                                <input v-model="form.reply_to" type="email" dir="ltr"
                                    class="w-full border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                                <p v-if="form.errors.reply_to" class="text-xs text-red-600 mt-1">{{ form.errors.reply_to }}</p>
                            </div>
                        </div>

                        <p class="text-xs text-slate-500">
                            {{ $t('We check the login before saving. The connection is always encrypted, and the password is stored encrypted and never shown again.') }}
                        </p>

                        <div class="flex gap-2 flex-wrap">
                            <button type="submit" :disabled="form.processing"
                                class="bg-primary text-white text-sm px-4 py-2 rounded-lg disabled:opacity-50">
                                {{ form.processing ? $t('Connecting…') : (account ? $t('Save') : $t('Connect')) }}
                            </button>
                            <button v-if="connected" type="button" :disabled="testing" @click="sendTest"
                                class="text-sm px-4 py-2 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-50">
                                {{ $t('Send a test email') }}
                            </button>
                        </div>
                    </form>

                    <!-- فصل -->
                    <section v-if="account" class="bg-white border border-red-100 rounded-lg p-4 text-sm flex items-center gap-3 flex-wrap">
                        <div class="min-w-0 flex-1">
                            <h4 class="text-[15px]">{{ $t('Disconnect email') }}</h4>
                            <p class="text-xs text-slate-500 mt-1">{{ $t('Email campaigns stop until you connect again.') }}</p>
                        </div>
                        <button type="button" @click="showDisconnect = true"
                            class="text-xs px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 shrink-0">
                            {{ $t('Disconnect') }}
                        </button>
                    </section>
                </div>
            </div>
        </div>

        <AlertModal v-model="showDisconnect" :label="$t('Disconnect email')" @confirm="disconnect">
            {{ $t('Email campaigns stop until you connect again.') }}
        </AlertModal>
    </SettingLayout>
</template>
