<template>
    <AppLayout>
        <div class="p-4 md:p-8 h-full overflow-y-auto">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">{{ $t('Meetings') }}</h1>
                    <p class="text-sm text-slate-500">{{ $t('Pick a time and tell us what you need help with.') }}</p>
                </div>
                <button v-if="props.available" type="button" @click="openModal()"
                    class="rounded-md bg-primary px-4 py-2.5 text-sm text-white shrink-0">
                    {{ $t('Book a meeting') }}
                </button>
            </div>

            <div v-if="!props.available"
                class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                {{ $t('Your organization is not linked to the support platform yet.') }}
            </div>

            <!-- الجدول بعرض الصفحة كاملاً -->
            <div v-else class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b bg-slate-50">
                            <th class="px-4 py-3 font-medium">{{ $t('Subject') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Reason') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Preferred time') }}</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="meeting in props.rows" :key="meeting.id" class="border-b last:border-b-0 hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ meeting.title }}</div>
                                <div class="text-xs text-slate-400 truncate max-w-md">{{ meeting.description }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-2 text-slate-600">
                                    <span class="h-2.5 w-2.5 rounded-full shrink-0" :style="{ backgroundColor: meeting.color }"></span>
                                    {{ $t(reasonLabel(meeting.color)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ meeting.start }}</td>
                            <td class="px-4 py-3 text-end">
                                <button type="button" @click="cancel(meeting)"
                                    class="text-xs font-bold text-red-700 hover:underline">{{ $t('Cancel') }}</button>
                            </td>
                        </tr>
                        <tr v-if="!props.rows || props.rows.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-slate-400">{{ $t('No data available.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- نافذة الحجز -->
        <Modal :label="$t('Book a meeting')" :isOpen="isOpen" :closeBtn="true" @close="closeModal()">
            <form @submit.prevent="submit()" class="mt-5">
                <FormInput v-model="form.title" :name="$t('Subject')" :error="form.errors.title" :type="'text'" />

                <div class="mt-4">
                    <FormSelect v-model="form.reason" :name="$t('Reason')" :type="'text'"
                        :placeholder="$t('Select option')" :options="reasonOptions" :error="form.errors.reason" />
                </div>

                <div class="mt-4">
                    <label class="block text-sm leading-6 text-gray-900">{{ $t('Preferred time') }}</label>
                    <input v-model="form.start" type="datetime-local" :min="minStart"
                        class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset sm:text-sm"
                        :class="form.errors.start ? 'ring-[#b91c1c]' : 'ring-gray-300'" />
                    <div v-if="form.errors.start" class="text-[#b91c1c] text-xs mt-1">{{ form.errors.start }}</div>
                </div>

                <div class="mt-4">
                    <label class="block text-sm leading-6 text-gray-900">{{ $t('Details') }}</label>
                    <textarea v-model="form.description" rows="4"
                        class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset sm:text-sm"
                        :class="form.errors.description ? 'ring-[#b91c1c]' : 'ring-gray-300'"></textarea>
                    <div v-if="form.errors.description" class="text-[#b91c1c] text-xs mt-1">{{ form.errors.description }}</div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button type="button" @click="closeModal()"
                        class="rounded-md bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="submit" :disabled="form.processing"
                        class="rounded-md bg-primary px-4 py-2 text-sm text-white flex-1 disabled:opacity-60">
                        {{ form.processing ? $t('Please wait') : $t('Send request') }}
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>

<script setup>
import AppLayout from '../Layout/App.vue'
import Modal from '@/Components/Modal.vue'
import FormInput from '@/Components/FormInput.vue'
import FormSelect from '@/Components/FormSelect.vue'
import { router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

const props = defineProps({
    reasons: { type: Array, default: () => [] },
    available: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
})

const isOpen = ref(false)

const form = useForm({
    title: null,
    description: null,
    start: null,
    reason: null,
})

const reasonOptions = computed(() => props.reasons.map(r => ({ value: r.value, label: r.label })))

/** اللون هو ما تُرجعه المنصة، فنستدلّ منه على السبب لعرض اسمه بدل رمزه. */
const reasonLabel = (color) => {
    const match = props.reasons.find(r => r.color === color)
    return match ? match.label : trans('Other')
}

// الخادم يشترط موعداً مستقبلياً؛ نمنع اختيار الماضي من المتصفح أيضاً.
const minStart = computed(() => {
    const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
    return now.toISOString().slice(0, 16)
})

const openModal = () => {
    form.clearErrors()
    isOpen.value = true
}

const closeModal = () => {
    isOpen.value = false
}

const submit = () => form.post('/support/meetings', {
    preserveScroll: true,
    onSuccess: () => {
        form.reset()
        closeModal()
    },
})

const cancel = (meeting) => {
    if (!confirm(trans('Are you sure you want to cancel this meeting?'))) {
        return
    }
    router.delete(`/support/meetings/${meeting.id}`, { preserveScroll: true })
}
</script>
