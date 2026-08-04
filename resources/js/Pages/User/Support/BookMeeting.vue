<template>
    <AppLayout>
        <div class="p-4 md:p-8 h-full overflow-y-auto">
            <div class="max-w-xl">
                <div class="mb-6">
                    <h1 class="text-xl font-semibold text-gray-900">{{ $t('Book a meeting') }}</h1>
                    <p class="text-sm text-slate-500">{{ $t('Pick a time and tell us what you need help with.') }}</p>
                </div>

                <div v-if="!props.available"
                    class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                    {{ $t('Your organization is not linked to the support platform yet.') }}
                </div>

                <form v-else @submit.prevent="submit()" class="bg-white border border-slate-200 rounded-lg p-5">
                    <FormInput v-model="form.title" :name="$t('Subject')" :error="form.errors.title" :type="'text'"/>

                    <div class="mt-4">
                        <FormSelect v-model="form.reason" :name="$t('Reason')" :type="'text'"
                            :placeholder="$t('Select option')" :options="reasonOptions" :error="form.errors.reason"/>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm leading-6 text-gray-900">{{ $t('Preferred time') }}</label>
                        <input v-model="form.start" type="datetime-local" :min="minStart"
                            class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset sm:text-sm"
                            :class="form.errors.start ? 'ring-[#b91c1c]' : 'ring-gray-300'"/>
                        <div v-if="form.errors.start" class="text-[#b91c1c] text-xs mt-1">{{ form.errors.start }}</div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm leading-6 text-gray-900">{{ $t('Details') }}</label>
                        <textarea v-model="form.description" rows="4"
                            class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset sm:text-sm"
                            :class="form.errors.description ? 'ring-[#b91c1c]' : 'ring-gray-300'"></textarea>
                        <div v-if="form.errors.description" class="text-[#b91c1c] text-xs mt-1">{{ form.errors.description }}</div>
                    </div>

                    <button type="submit" :disabled="form.processing"
                        class="mt-6 rounded-md bg-primary px-4 py-2.5 text-sm text-white w-full disabled:opacity-60">
                        {{ form.processing ? $t('Please wait') : $t('Send request') }}
                    </button>
                </form>

                <!-- المواعيد المحجوزة لهذه المنشأة وحدها -->
                <div v-if="props.available" class="mt-8">
                    <h2 class="text-sm font-bold text-gray-900 mb-3">{{ $t('Your meetings') }}</h2>
                    <div class="bg-white border border-slate-200 rounded-lg divide-y">
                        <div v-for="meeting in props.rows" :key="meeting.id"
                            class="flex items-start gap-3 px-4 py-3">
                            <span class="mt-1.5 h-2.5 w-2.5 rounded-full flex-shrink-0"
                                :style="{ backgroundColor: meeting.color }"></span>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-medium text-gray-900 truncate">{{ meeting.title }}</div>
                                <div class="text-xs text-slate-500">{{ meeting.start }}</div>
                            </div>
                            <button type="button" @click="cancel(meeting)"
                                class="text-xs font-bold text-red-700 hover:underline flex-shrink-0">
                                {{ $t('Cancel') }}
                            </button>
                        </div>
                        <div v-if="!props.rows || props.rows.length === 0"
                            class="px-4 py-8 text-center text-slate-400 text-sm">
                            {{ $t('No data available.') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '../Layout/App.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import { router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

const props = defineProps({
    reasons: { type: Array, default: () => [] },
    available: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
})

const form = useForm({
    title: null,
    description: null,
    start: null,
    reason: null,
})

const reasonOptions = computed(() => props.reasons.map(r => ({ value: r.value, label: r.label })))

// الخادم يشترط موعداً مستقبلياً؛ نمنع اختيار الماضي من المتصفح أيضاً.
const minStart = computed(() => {
    const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
    return now.toISOString().slice(0, 16)
})

const submit = () => form.post('/support/meetings', { preserveScroll: true })

const cancel = (meeting) => {
    if (!confirm(trans('Are you sure you want to cancel this meeting?'))) {
        return
    }
    router.delete(`/support/meetings/${meeting.id}`, { preserveScroll: true })
}
</script>
