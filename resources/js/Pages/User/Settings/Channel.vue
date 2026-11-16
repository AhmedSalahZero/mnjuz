<script setup>
/**
 * صفحة إدارة قناة واحدة (تيليجرام، ماسنجر، إنستغرام، تيك توك).
 *
 * ما كان تبويباً في صفحة القنوات صار صفحة مستقلّة، بالشكل نفسه الذي لصفحتي
 * واتساب والمحادثة المباشرة. السلوك منقول كما هو: الحساب المربوط والفصل،
 * اختيار الحساب بعد تفويض أعاد أكثر من واحد، زرّ التفويض أو نموذج اللصق،
 * وقيود المنصّة.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Link, router } from '@inertiajs/vue3'
import { toast } from 'vue3-toastify'
import SettingLayout from './Layout.vue'
import ChannelIcon from '@/Components/ChannelIcon.vue'
import AlertModal from '@/Components/AlertModal.vue'
import { channelCardStatus, toneDotClass } from '@/Composables/channelStatus.js'

const props = defineProps({
    card: { type: Object, required: true },
    webhookBaseUrl: { type: String, default: '' },
    pendingSelection: { type: Object, default: null },
    modules: { type: Array, default: () => [] },
})

const { t } = useI18n()

const chosenAccount = ref(null)
const form = ref({})
const submitting = ref(false)
const showDisconnect = ref(false)
const pendingDisconnect = ref(null)

const card = computed(() => props.card)
const status = computed(() => channelCardStatus(props.card))

/** عنوان الـ webhook الذي يلصقه المستخدم في لوحة المنصّة. */
const webhookUrl = computed(() => {
    // تيليجرام يُسجَّل تلقائياً بعد الحفظ فلا يحتاج المستخدم نسخه.
    if (card.value.channel === 'telegram') return ''
    return `${props.webhookBaseUrl}/${card.value.channel}`
})

const copyWebhook = async () => {
    try {
        await navigator.clipboard.writeText(webhookUrl.value)
        toast.success(window.trans ? window.trans('Copied') : 'Copied')
    } catch {
        // الحافظة محجوبة في سياق غير آمن (http). لا نُفشل الصفحة — الحقل
        // ظاهر ويمكن تحديده يدوياً.
    }
}

const startOAuth = () => {
    // زيارة كاملة لا Inertia: الوجهة نطاق خارجيّ.
    window.location.href = `/settings/channels/connect/${card.value.channel}`
}

const confirmSelection = () => {
    if (!chosenAccount.value) return

    router.post('/settings/channels/select', { external_id: chosenAccount.value }, {
        preserveScroll: true,
    })
}

const cancelSelection = () => {
    router.delete('/settings/channels/select', { preserveScroll: true })
}

const connect = () => {
    submitting.value = true

    router.post('/settings/channels', {
        channel: card.value.channel,
        credentials: form.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            submitting.value = false
            form.value = {}
        },
    })
}

const askDisconnect = (account) => {
    pendingDisconnect.value = account
    showDisconnect.value = true
}

const disconnect = () => {
    if (!pendingDisconnect.value) return

    router.delete(`/settings/channels/${pendingDisconnect.value.uuid}`, {
        preserveScroll: true,
        onFinish: () => {
            pendingDisconnect.value = null
        },
    })
}

/** نافذة الردّ بلغة المستخدم — لا «24h» لاصقة في واجهة عربية. */
const windowLabel = (hours) => (hours === null || hours === undefined
    ? t('Unlimited')
    : t('{count} hours', { count: hours }))
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

                    <!-- الرأس: القناة وحالتها -->
                    <section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
                        <div class="flex items-center gap-3 flex-wrap">
                            <ChannelIcon :channel="card.channel" :size="32" filled />
                            <div class="min-w-0 flex-1">
                                <h4 class="text-[16px]">{{ card.label }}</h4>
                                <div class="text-xs text-slate-500 flex items-center gap-1.5 mt-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="toneDotClass(status.tone)" />
                                    <span>{{ $t(status.key) }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- الحساب المربوط -->
                    <section v-if="card.account" class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
                        <div class="flex items-start gap-3 flex-wrap">
                            <img v-if="card.account.avatar" :src="card.account.avatar"
                                 class="w-10 h-10 rounded-full object-cover shrink-0" />
                            <ChannelIcon v-else :channel="card.channel" :size="40" filled />

                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium truncate">
                                    {{ card.account.name || card.label }}
                                </div>
                                <div v-if="card.account.username" class="text-xs text-slate-500 truncate">
                                    @{{ card.account.username }}
                                </div>
                                <div class="text-xs text-slate-400 mt-0.5 truncate">
                                    ID: {{ card.account.external_id }}
                                </div>
                            </div>

                            <span class="text-xs px-2 py-1 rounded-full shrink-0"
                                :class="card.account.is_active
                                    ? 'bg-green-50 text-green-700'
                                    : 'bg-amber-50 text-amber-700'">
                                {{ card.account.is_active ? $t('Active') : $t('Needs attention') }}
                            </span>

                            <button type="button"
                                class="text-xs px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 shrink-0"
                                @click="askDisconnect(card.account)">
                                {{ $t('Disconnect') }}
                            </button>
                        </div>

                        <div v-if="card.account.needs_reauthorization"
                             class="mt-3 text-xs bg-amber-50 text-amber-800 border border-amber-200 rounded-md px-3 py-2">
                            {{ $t('The connection expired. Enter the credentials again below to reconnect.') }}
                            <div v-if="card.account.last_error" class="mt-1 opacity-75">
                                {{ card.account.last_error }}
                            </div>
                        </div>
                    </section>

                    <!-- اختيار الحساب بعد تفويض أعاد أكثر من واحد -->
                    <section v-if="pendingSelection" class="bg-amber-50/50 border border-amber-200 rounded-lg p-4 text-sm">
                        <h3 class="text-sm font-medium mb-1">{{ $t('Choose the account to connect') }}</h3>
                        <p class="text-xs text-slate-500 mb-3">
                            {{ $t('You authorized more than one account. Pick the one that receives your customers’ messages.') }}
                        </p>

                        <div class="space-y-2">
                            <label v-for="option in pendingSelection.accounts" :key="option.external_id"
                                class="flex items-center gap-3 p-2 rounded-lg border cursor-pointer transition-colors"
                                :class="chosenAccount === option.external_id
                                    ? 'border-slate-800 bg-white'
                                    : 'border-slate-200 bg-white hover:bg-slate-50'">
                                <input type="radio" :value="option.external_id" v-model="chosenAccount"
                                    class="shrink-0" />
                                <img v-if="option.avatar" :src="option.avatar"
                                     class="w-8 h-8 rounded-full object-cover shrink-0" />
                                <ChannelIcon v-else :channel="card.channel" :size="32" filled />
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm truncate">{{ option.name || option.external_id }}</div>
                                    <div v-if="option.username" class="text-xs text-slate-500 truncate">
                                        @{{ option.username }}
                                    </div>
                                </div>
                            </label>
                        </div>

                        <div class="flex gap-2 mt-3">
                            <button type="button" :disabled="!chosenAccount"
                                class="bg-primary text-white text-sm px-4 py-2 rounded-lg disabled:opacity-50"
                                @click="confirmSelection">
                                {{ $t('Connect') }}
                            </button>
                            <button type="button"
                                class="text-sm px-4 py-2 rounded-lg border border-slate-200 hover:bg-white"
                                @click="cancelSelection">
                                {{ $t('Cancel') }}
                            </button>
                        </div>
                    </section>

                    <!-- الربط بالتفويض -->
                    <section v-else-if="card.uses_oauth" class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
                        <h3 class="text-sm font-medium mb-1">
                            {{ card.account ? $t('Reconnect {channel}', { channel: card.label }) : $t('Connect {channel}', { channel: card.label }) }}
                        </h3>
                        <p class="text-xs text-slate-500 mb-3">
                            {{ $t('You will be taken to {channel} to approve the permissions, then brought back here.', { channel: card.label }) }}
                        </p>

                        <div v-if="!card.platform_ready"
                             class="text-xs bg-amber-50 text-amber-800 border border-amber-200 rounded-md px-3 py-2">
                            {{ $t('This platform is not configured on the server yet. Contact the administrator.') }}
                        </div>

                        <button v-else type="button"
                            class="flex items-center gap-2 bg-primary text-white text-sm px-4 py-2 rounded-lg"
                            @click="startOAuth">
                            <ChannelIcon :channel="card.channel" :size="18" />
                            <span>{{ card.account ? $t('Reconnect') : $t('Connect') }}</span>
                        </button>
                    </section>

                    <!-- الربط بلصق توكن (تيليجرام) -->
                    <section v-else class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
                        <h3 class="text-sm font-medium mb-1">
                            {{ card.account ? $t('Update credentials') : $t('Connect {channel}', { channel: card.label }) }}
                        </h3>

                        <div v-if="webhookUrl" class="mb-4">
                            <label class="block text-xs text-slate-500 mb-1">{{ $t('Webhook URL') }}</label>
                            <div class="flex gap-2">
                                <input type="text" readonly :value="webhookUrl" dir="ltr"
                                    class="flex-1 min-w-0 text-xs border border-slate-200 rounded-lg px-3 py-2 bg-slate-50 font-mono"
                                    @focus="$event.target.select()" />
                                <button type="button" @click="copyWebhook"
                                    class="text-xs px-3 py-2 rounded-lg border border-slate-200 hover:bg-slate-50 shrink-0">
                                    {{ $t('Copy') }}
                                </button>
                            </div>
                            <p class="text-xs text-slate-400 mt-1">
                                {{ $t('Paste this into the platform’s webhook settings.') }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div v-for="field in card.fields" :key="field.key">
                                <label class="block text-xs text-slate-600 mb-1">{{ field.label }}</label>
                                <input
                                    :type="field.type === 'password' ? 'password' : 'text'"
                                    v-model="form[field.key]"
                                    autocomplete="off"
                                    class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-slate-400" />
                                <p v-if="field.hint" class="text-xs text-slate-400 mt-1">{{ field.hint }}</p>
                            </div>
                        </div>

                        <button type="button" :disabled="submitting"
                            class="mt-4 bg-primary text-white text-sm px-4 py-2 rounded-lg disabled:opacity-50"
                            @click="connect">
                            {{ submitting ? $t('Connecting…') : (card.account ? $t('Save') : $t('Connect')) }}
                        </button>
                    </section>

                    <!-- قيود المنصّة -->
                    <section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
                        <div class="text-xs font-medium text-slate-600 mb-2">
                            {{ $t('What this channel supports') }}
                        </div>

                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 mb-2">
                            <span>{{ $t('Reply window') }}: {{ windowLabel(card.capabilities.window_hours) }}</span>
                            <span>{{ $t('Campaigns') }}: {{ card.capabilities.campaigns ? '✓' : '✗' }}</span>
                            <span>{{ $t('Templates') }}: {{ card.capabilities.templates ? '✓' : '✗' }}</span>
                            <span>{{ $t('Read receipts') }}: {{ card.capabilities.read_receipts ? '✓' : '✗' }}</span>
                        </div>

                        <ul class="text-xs text-slate-500 space-y-1 list-disc list-inside">
                            <li v-for="(note, i) in card.notes" :key="i">{{ note }}</li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>

        <AlertModal v-model="showDisconnect" :label="$t('Disconnect channel')" @confirm="disconnect">
            {{ $t('Messages will stop arriving from this account. Past conversations are kept.') }}
        </AlertModal>
    </SettingLayout>
</template>
