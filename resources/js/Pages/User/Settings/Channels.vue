<script setup>
/**
 * صفحة قنوات التواصل — تبويب لكل منصّة.
 *
 * واتساب معروضة للاطّلاع فقط وتوجّه إلى صفحتها: لها EmbeddedSignup ومسار
 * ربط مختلف تماماً، ودمجهما في نموذج واحد يُنتج شاشة تخدم الاثنين بسوء.
 *
 * التصميم: التبويبات تنساب أفقياً على الجوال (شاشة ٣٦٠ بكسل لا تتّسع
 * لخمسة تبويبات)، والنموذج عمود واحد على الجوال وعمودان على سطح المكتب.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { router, usePage } from '@inertiajs/vue3'
import { toast } from 'vue3-toastify'
import SettingLayout from './Layout.vue'
import ChannelIcon from '@/Components/ChannelIcon.vue'
import AlertModal from '@/Components/AlertModal.vue'

const props = defineProps({
    channels: { type: Array, default: () => [] },
    whatsappConnected: { type: Boolean, default: false },
    webhookBaseUrl: { type: String, default: '' },
    pendingSelection: { type: Object, default: null },
    modules: { type: Array, default: () => [] },
})

// التبويب المفتوح: تبويب الاختيار المعلّق إن وُجد، وإلّا الأول. من عاد
// للتوّ من تفويض يجب أن يرى صفحاته لا تبويباً آخر.
const { t } = useI18n()

const activeChannel = ref(props.pendingSelection?.channel ?? props.channels[0]?.channel ?? null)
const chosenAccount = ref(null)
const form = ref({})
const submitting = ref(false)
const showDisconnect = ref(false)
const pendingDisconnect = ref(null)

const active = computed(() => props.channels.find((c) => c.channel === activeChannel.value) || null)

const selectChannel = (channel) => {
    activeChannel.value = channel
    form.value = {}
}

/** عنوان الـ webhook الذي يلصقه المستخدم في لوحة المنصّة. */
const webhookUrl = computed(() => {
    if (!active.value) return ''
    // تيليجرام يُسجَّل تلقائياً بعد الحفظ فلا يحتاج المستخدم نسخه.
    if (active.value.channel === 'telegram') return ''
    return `${props.webhookBaseUrl}/${active.value.channel}`
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
    if (!active.value) return
    // زيارة كاملة لا Inertia: الوجهة نطاق خارجيّ.
    window.location.href = `/settings/channels/connect/${active.value.channel}`
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
    if (!active.value) return

    submitting.value = true

    router.post('/settings/channels', {
        channel: active.value.channel,
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

/**
 * نافذة الردّ بلغة المستخدم.
 *
 * كانت تُركّب `${hours}h` نصّاً — فتظهر «24h» في واجهة عربية، ولاصقةُ
 * حرفٍ إنجليزيّ برقم لا تمرّ على الترجمة أصلاً.
 */
const windowLabel = (hours) => (hours === null || hours === undefined
    ? t('Unlimited')
    : t('{count} hours', { count: hours }))
</script>

<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh] overflow-y-auto">
            <div class="flex justify-center">
                <div class="w-full md:w-[60em] pb-10">
                    <div class="mb-4">
                        <h2 class="text-xl mb-1">{{ $t('Channels') }}</h2>
                        <p class="text-sm text-slate-500">
                            {{ $t('Connect the platforms your customers message you on. Their conversations arrive in the same inbox.') }}
                        </p>
                    </div>

                    <!-- واتساب: بطاقة اطّلاع توجّه إلى صفحتها -->
                    <div class="bg-white border border-slate-200 rounded-lg p-4 mb-4 flex items-center gap-3 flex-wrap">
                        <ChannelIcon channel="whatsapp" :size="32" filled />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">WhatsApp</div>
                            <div class="text-xs text-slate-500">
                                {{ whatsappConnected ? $t('Connected') : $t('Not connected') }}
                            </div>
                        </div>
                        <a href="/settings/whatsapp"
                           class="text-sm px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 shrink-0">
                            {{ $t('Manage') }}
                        </a>
                    </div>

                    <!-- المحادثة المباشرة: صفحتها الخاصّة (الكود والشكل والنموذج) -->
                    <div v-if="$page.props.settingsModuleWebChat" class="bg-white border border-slate-200 rounded-lg p-4 mb-4 flex items-center gap-3 flex-wrap">
                        <ChannelIcon channel="webchat" :size="32" filled />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">{{ $t('Web Chat') }}</div>
                            <div class="text-xs text-slate-500">{{ $t('A chat window on your website.') }}</div>
                        </div>
                        <a href="/settings/webchat"
                           class="text-sm px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 shrink-0">
                            {{ $t('Manage') }}
                        </a>
                    </div>

                    <!-- تبويبات القنوات الأخرى -->
                    <div class="flex gap-1.5 overflow-x-auto pb-2 mb-4 scrollbar-none">
                        <button v-for="card in channels" :key="card.channel" type="button"
                            class="shrink-0 flex items-center gap-2 px-3 py-2 rounded-lg border text-sm transition-colors"
                            :class="activeChannel === card.channel
                                ? 'bg-slate-800 text-white border-slate-800'
                                : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'"
                            @click="selectChannel(card.channel)">
                            <ChannelIcon :channel="card.channel" :size="18" :filled="activeChannel === card.channel" />
                            <span>{{ card.label }}</span>
                            <span v-if="card.account"
                                class="w-1.5 h-1.5 rounded-full shrink-0"
                                :class="card.account.is_active ? 'bg-green-400' : 'bg-amber-400'" />
                        </button>
                    </div>

                    <div v-if="active" class="bg-white border border-slate-200 rounded-lg">
                        <!-- الحساب المربوط -->
                        <div v-if="active.account" class="p-4 border-b">
                            <div class="flex items-start gap-3 flex-wrap">
                                <img v-if="active.account.avatar" :src="active.account.avatar"
                                     class="w-10 h-10 rounded-full object-cover shrink-0" />
                                <ChannelIcon v-else :channel="active.channel" :size="40" filled />

                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium truncate">
                                        {{ active.account.name || active.label }}
                                    </div>
                                    <div v-if="active.account.username" class="text-xs text-slate-500 truncate">
                                        @{{ active.account.username }}
                                    </div>
                                    <div class="text-xs text-slate-400 mt-0.5 truncate">
                                        ID: {{ active.account.external_id }}
                                    </div>
                                </div>

                                <span class="text-xs px-2 py-1 rounded-full shrink-0"
                                    :class="active.account.is_active
                                        ? 'bg-green-50 text-green-700'
                                        : 'bg-amber-50 text-amber-700'">
                                    {{ active.account.is_active ? $t('Active') : $t('Needs attention') }}
                                </span>

                                <button type="button"
                                    class="text-xs px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 shrink-0"
                                    @click="askDisconnect(active.account)">
                                    {{ $t('Disconnect') }}
                                </button>
                            </div>

                            <div v-if="active.account.needs_reauthorization"
                                 class="mt-3 text-xs bg-amber-50 text-amber-800 border border-amber-200 rounded-md px-3 py-2">
                                {{ $t('The connection expired. Enter the credentials again below to reconnect.') }}
                                <div v-if="active.account.last_error" class="mt-1 opacity-75">
                                    {{ active.account.last_error }}
                                </div>
                            </div>
                        </div>

                        <!-- اختيار الصفحة بعد تفويض أعاد أكثر من واحدة -->
                        <div v-if="pendingSelection && pendingSelection.channel === active.channel"
                             class="p-4 border-b bg-amber-50/50">
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
                                    <ChannelIcon v-else :channel="active.channel" :size="32" filled />
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
                        </div>

                        <!-- الربط بالتفويض -->
                        <div v-else-if="active.uses_oauth" class="p-4">
                            <h3 class="text-sm font-medium mb-1">
                                {{ active.account ? $t('Reconnect {channel}', { channel: active.label }) : $t('Connect {channel}', { channel: active.label }) }}
                            </h3>
                            <p class="text-xs text-slate-500 mb-3">
                                {{ $t('You will be taken to {channel} to approve the permissions, then brought back here.', { channel: active.label }) }}
                            </p>

                            <div v-if="!active.platform_ready"
                                 class="text-xs bg-amber-50 text-amber-800 border border-amber-200 rounded-md px-3 py-2">
                                {{ $t('This platform is not configured on the server yet. Contact the administrator.') }}
                            </div>

                            <button v-else type="button"
                                class="flex items-center gap-2 bg-primary text-white text-sm px-4 py-2 rounded-lg"
                                @click="startOAuth">
                                <ChannelIcon :channel="active.channel" :size="18" />
                                <span>{{ active.account ? $t('Reconnect') : $t('Connect') }}</span>
                            </button>
                        </div>

                        <!-- الربط بلصق توكن (تيليجرام) -->
                        <div v-else class="p-4">
                            <h3 class="text-sm font-medium mb-1">
                                {{ active.account ? $t('Update credentials') : $t('Connect {channel}', { channel: active.label }) }}
                            </h3>

                            <div v-if="webhookUrl" class="mb-4">
                                <label class="block text-xs text-slate-500 mb-1">{{ $t('Webhook URL') }}</label>
                                <div class="flex gap-2">
                                    <input type="text" readonly :value="webhookUrl"
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
                                <div v-for="field in active.fields" :key="field.key">
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
                                {{ submitting ? $t('Connecting…') : (active.account ? $t('Save') : $t('Connect')) }}
                            </button>
                        </div>

                        <!-- قيود المنصّة -->
                        <div class="px-4 pb-4">
                            <div class="bg-slate-50 rounded-lg p-3">
                                <div class="text-xs font-medium text-slate-600 mb-2">
                                    {{ $t('What this channel supports') }}
                                </div>

                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 mb-2">
                                    <span>{{ $t('Reply window') }}: {{ windowLabel(active.capabilities.window_hours) }}</span>
                                    <span>{{ $t('Campaigns') }}: {{ active.capabilities.campaigns ? '✓' : '✗' }}</span>
                                    <span>{{ $t('Templates') }}: {{ active.capabilities.templates ? '✓' : '✗' }}</span>
                                    <span>{{ $t('Read receipts') }}: {{ active.capabilities.read_receipts ? '✓' : '✗' }}</span>
                                </div>

                                <ul class="text-xs text-slate-500 space-y-1 list-disc list-inside">
                                    <li v-for="(note, i) in active.notes" :key="i">{{ note }}</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <AlertModal v-model="showDisconnect" :label="$t('Disconnect channel')" @confirm="disconnect">
            {{ $t('Messages will stop arriving from this account. Past conversations are kept.') }}
        </AlertModal>
    </SettingLayout>
</template>

<style scoped>
.scrollbar-none {
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.scrollbar-none::-webkit-scrollbar {
    display: none;
}
</style>
