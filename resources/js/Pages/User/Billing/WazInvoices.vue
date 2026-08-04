<template>
    <AppLayout>
        <div class="p-4 md:p-8 h-full overflow-y-auto">
            <div class="mb-6">
                <h1 class="text-xl font-semibold text-gray-900">{{ $t('Invoices') }}</h1>
                <p class="text-sm text-slate-500">{{ $t('Your official tax invoices.') }}</p>
            </div>

            <div v-if="props.loadError" class="mb-5 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                {{ props.loadError }}
            </div>

            <div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-slate-500 border-b bg-slate-50">
                            <th class="px-4 py-3 font-medium">{{ $t('Invoice number') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ $t('Due date') }}</th>
                            <th class="px-4 py-3 font-medium text-center">{{ $t('Total') }}</th>
                            <th class="px-4 py-3 font-medium text-center">{{ $t('Status') }}</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in props.rows" :key="invoice.id" class="border-b last:border-b-0 hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ invoice.number }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ invoice.date }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ invoice.duedate }}</td>
                            <td class="px-4 py-3 text-center">{{ invoice.total }} {{ invoice.currency_name }}</td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-block rounded-full px-2 py-0.5 text-xs" :class="statusClass(invoice.status)">
                                    {{ $t(statusLabel(invoice.status)) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <a v-if="invoice.url" :href="invoice.url" target="_blank" rel="noopener noreferrer"
                                    class="text-primary text-xs font-bold hover:underline">{{ $t('View') }}</a>
                            </td>
                        </tr>
                        <tr v-if="!props.rows || props.rows.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">{{ $t('No data available.') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '../Layout/App.vue'

const props = defineProps({
    rows: { type: Array, default: () => [] },
    loadError: { type: String, default: null },
})

// حالات الفاتورة في واز أعمال.
const STATUSES = {
    1: 'Unpaid',
    2: 'Paid',
    3: 'Partially paid',
    4: 'Overdue',
    5: 'Cancelled',
    6: 'Draft',
}

const statusLabel = (status) => STATUSES[Number(status)] ?? 'Unknown'

const statusClass = (status) => {
    switch (Number(status)) {
        case 2: return 'bg-green-100 text-green-700'
        case 3: return 'bg-amber-100 text-amber-700'
        case 4: return 'bg-red-100 text-red-700'
        case 5: return 'bg-slate-100 text-slate-500'
        default: return 'bg-slate-100 text-slate-600'
    }
}
</script>
