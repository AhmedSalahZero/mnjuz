<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="flex justify-between gap-3 flex-wrap mt-8 md:mt-0">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Message templates') }}</h2>
                    <p class="mb-6 text-sm leading-6 text-gray-600">
                        {{ $t('Ready to use — no approval needed') }}
                    </p>
                </div>
                <div class="flex items-center">
                    <Link :href="`/templates/channel/${channel}/create`"
                        class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500">
                        {{ $t('Create {channel} template', { channel: channelLabel }) }}
                    </Link>
                </div>
            </div>

            <TemplateChannelTabs :active="channel" />

            <div v-if="templates.length === 0" class="bg-white border border-slate-200 rounded-lg p-8 text-center text-sm text-slate-500">
                {{ $t('No saved templates for this channel yet.') }}
            </div>

            <div v-else class="bg-white border border-slate-200 rounded-lg divide-y">
                <div v-for="template in templates" :key="template.uuid" class="p-4 flex items-start gap-3 flex-wrap">
                    <div class="min-w-0 flex-1">
                        <Link :href="`/templates/channel/${channel}/${template.uuid}`" class="text-sm font-medium hover:underline" dir="auto">
                            {{ template.name }}
                        </Link>
                        <div v-if="template.subject" class="text-xs text-slate-600 mt-0.5 truncate" dir="auto">{{ template.subject }}</div>
                        <div class="text-xs text-slate-400 mt-1 line-clamp-2 whitespace-pre-line" dir="auto">{{ template.excerpt }}</div>
                    </div>
                    <span v-if="template.has_media" class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 shrink-0">
                        {{ $t('Attachment') }}
                    </span>
                    <div class="flex items-center gap-2 shrink-0">
                        <Link :href="`/templates/channel/${channel}/${template.uuid}`"
                            class="text-xs px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-50">
                            {{ $t('Edit') }}
                        </Link>
                        <button type="button" @click="askDelete(template)"
                            class="text-xs px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50">
                            {{ $t('Delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <AlertModal v-model="showDelete" :label="$t('Delete template')" @confirm="confirmDelete">
            {{ $t('Scheduled campaigns that use this template keep their copy of the message.') }}
        </AlertModal>
    </AppLayout>
</template>

<script setup>
/**
 * القوالب المحفوظة لقناة بلا موافقة (تيليجرام، البريد): جاهزة فور الحفظ.
 */
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '../../Layout/App.vue'
import AlertModal from '@/Components/AlertModal.vue'
import TemplateChannelTabs from '@/Components/Template/TemplateChannelTabs.vue'

const props = defineProps({
    channel: { type: String, required: true },
    channelLabel: { type: String, default: '' },
    templates: { type: Array, default: () => [] },
})

const showDelete = ref(false)
const pendingDelete = ref(null)

const askDelete = (template) => {
    pendingDelete.value = template
    showDelete.value = true
}

const confirmDelete = () => {
    if (!pendingDelete.value) return

    router.delete(`/templates/channel/${props.channel}/${pendingDelete.value.uuid}`, {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null
        },
    })
}
</script>
