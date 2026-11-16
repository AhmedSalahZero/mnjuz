<script setup>
/**
 * صفحة قنوات التواصل — بطاقة لكل منصّة بالشكل نفسه.
 *
 * كانت واتساب والمحادثة المباشرة بطاقتين تفتحان صفحتيهما، والباقي تبويبات
 * تحتها نموذج الربط. الآن كلّها بطاقات: أيقونة، اسم، سطر حالة، و«إدارة»
 * تفتح صفحة القناة (Settings/Channel.vue) حيث الربط والفصل والقيود.
 */
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import SettingLayout from './Layout.vue'
import ChannelIcon from '@/Components/ChannelIcon.vue'
import { channelCardStatus, emailStatus, webChatStatus, whatsappStatus, toneDotClass } from '@/Composables/channelStatus.js'

const props = defineProps({
    channels: { type: Array, default: () => [] },
    whatsappConnected: { type: Boolean, default: false },
    webChat: { type: Object, default: () => ({ exists: false, enabled: false }) },
    emailChannel: { type: Object, default: () => ({ connected: false, needs_attention: false, from_address: null }) },
    modules: { type: Array, default: () => [] },
})

const whatsapp = computed(() => whatsappStatus(props.whatsappConnected))
const email = computed(() => emailStatus(props.emailChannel))
const webChat = computed(() => webChatStatus(props.webChat))

const cards = computed(() => props.channels.map((card) => ({
    channel: card.channel,
    label: card.label,
    status: channelCardStatus(card),
    href: `/settings/channels/${card.channel}`,
})))
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

                    <!-- واتساب: لها صفحتها (EmbeddedSignup ومسارات Meta) -->
                    <div class="bg-white border border-slate-200 rounded-lg p-4 mb-4 flex items-center gap-3 flex-wrap">
                        <ChannelIcon channel="whatsapp" :size="32" filled />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">{{ $t('WhatsApp') }}</div>
                            <div class="text-xs text-slate-500 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="toneDotClass(whatsapp.tone)" />
                                <span>{{ $t(whatsapp.key) }}</span>
                            </div>
                        </div>
                        <a href="/settings/whatsapp"
                           class="text-sm px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 shrink-0">
                            {{ $t('Manage') }}
                        </a>
                    </div>

                    <!-- المحادثة المباشرة: صفحتها الخاصّة (الكود والشكل والأسئلة) -->
                    <div v-if="$page.props.settingsModuleWebChat" class="bg-white border border-slate-200 rounded-lg p-4 mb-4 flex items-center gap-3 flex-wrap">
                        <ChannelIcon channel="webchat" :size="32" filled />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">{{ $t('Web Chat') }}</div>
                            <div class="text-xs text-slate-500 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="toneDotClass(webChat.tone)" />
                                <span>{{ $t(webChat.key) }}</span>
                                <span class="text-slate-400">· {{ $t('A chat window on your website.') }}</span>
                            </div>
                        </div>
                        <a href="/settings/webchat"
                           class="text-sm px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 shrink-0">
                            {{ $t('Manage') }}
                        </a>
                    </div>

                    <!-- البريد: SMTP التاجر لحملات البريد (إرسال فقط) -->
                    <div class="bg-white border border-slate-200 rounded-lg p-4 mb-4 flex items-center gap-3 flex-wrap">
                        <ChannelIcon channel="email" :size="32" filled />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">{{ $t('Email') }}</div>
                            <div class="text-xs text-slate-500 flex items-center gap-1.5 min-w-0">
                                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="toneDotClass(email.tone)" />
                                <span class="shrink-0">{{ $t(email.key) }}</span>
                                <span v-if="email.detail" class="text-slate-400 truncate" dir="ltr">· {{ email.detail }}</span>
                                <span v-else class="text-slate-400 truncate">· {{ $t('For email campaigns, through your own mail server.') }}</span>
                            </div>
                        </div>
                        <Link href="/settings/channels/email"
                              class="text-sm px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 shrink-0">
                            {{ $t('Manage') }}
                        </Link>
                    </div>

                    <!-- القنوات التي تُربط من هنا: بطاقة وصفحة لكلّ منها -->
                    <div v-for="card in cards" :key="card.channel"
                         class="bg-white border border-slate-200 rounded-lg p-4 mb-4 flex items-center gap-3 flex-wrap">
                        <ChannelIcon :channel="card.channel" :size="32" filled />
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">{{ card.label }}</div>
                            <div class="text-xs text-slate-500 flex items-center gap-1.5 min-w-0">
                                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="toneDotClass(card.status.tone)" />
                                <span class="shrink-0">{{ $t(card.status.key) }}</span>
                                <span v-if="card.status.detail" class="text-slate-400 truncate" dir="auto">· {{ card.status.detail }}</span>
                            </div>
                        </div>
                        <Link :href="card.href"
                              class="text-sm px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50 shrink-0">
                            {{ $t('Manage') }}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </SettingLayout>
</template>
