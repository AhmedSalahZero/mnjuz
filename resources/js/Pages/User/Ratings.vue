<template>
  <AppLayout>
    <div class="p-4 md:p-8 h-full overflow-y-auto">
      <div class="mb-4">
        <h1 class="text-xl font-semibold text-gray-900">{{ $t('Customer Ratings') }}</h1>
        <p class="text-sm text-slate-500">{{ $t('What customers said after their conversation was closed.') }}</p>
      </div>

      <!-- الملخّص محسوب على كامل النتائج المُرشَّحة لا على الصفحة المعروضة -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
        <div class="bg-white border border-slate-200 rounded-lg px-4 py-3">
          <div class="text-xs text-slate-500">{{ $t('Average rating') }}</div>
          <div class="flex items-center gap-2 mt-1">
            <span class="text-2xl font-semibold text-gray-900">{{ summary.average ?? '—' }}</span>
            <span v-if="summary.average" class="flex gap-0.5" dir="ltr">
              <svg v-for="s in 5" :key="s" width="16" height="16" viewBox="0 0 24 24"
                :fill="s <= Math.round(summary.average) ? '#f59e0b' : 'none'"
                :stroke="s <= Math.round(summary.average) ? '#f59e0b' : '#cbd5e1'" stroke-width="1.5" stroke-linejoin="round">
                <path d="M12 2.5l2.9 5.88 6.49.95-4.7 4.58 1.11 6.46L12 17.33l-5.8 3.05 1.1-6.46-4.69-4.58 6.49-.95L12 2.5Z" />
              </svg>
            </span>
          </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg px-4 py-3">
          <div class="text-xs text-slate-500">{{ $t('Ratings received') }}</div>
          <div class="text-2xl font-semibold text-gray-900 mt-1">{{ summary.total }}</div>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg px-4 py-3">
          <div class="text-xs text-slate-500">{{ $t('Awaiting response') }}</div>
          <div class="text-2xl font-semibold text-gray-900 mt-1">{{ summary.pending }}</div>
        </div>
      </div>

      <form @submit.prevent="applyFilters" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div>
          <label class="block text-xs text-slate-500 mb-1">{{ $t('Rating') }}</label>
          <select v-model="form.rating" class="w-full rounded-md border px-2 py-2 text-sm outline-none">
            <option value="">{{ $t('All') }}</option>
            <option v-for="s in [5, 4, 3, 2, 1]" :key="s" :value="s">{{ s }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-500 mb-1">{{ $t('Search') }}</label>
          <input v-model="form.search" type="text" :placeholder="$t('Name, phone or comment')"
            class="w-full rounded-md border px-2 py-2 text-sm outline-none" />
        </div>
        <div class="flex items-end gap-2">
          <button type="submit" class="rounded-md bg-black px-4 py-2 text-sm text-white">{{ $t('Apply') }}</button>
          <button type="button" @click="resetFilters"
            class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">{{ $t('Reset') }}</button>
        </div>
      </form>

      <div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-slate-500 border-b bg-slate-50">
              <th class="px-4 py-3 font-medium whitespace-nowrap">{{ $t('Date') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('Customer') }}</th>
              <th class="px-4 py-3 font-medium whitespace-nowrap">{{ $t('Phone') }}</th>
              <th class="px-4 py-3 font-medium whitespace-nowrap">{{ $t('Rating') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('Comment') }}</th>
              <th class="px-4 py-3 font-medium whitespace-nowrap">{{ $t('Agent') }}</th>
              <th v-if="canDelete" class="px-4 py-3 font-medium"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.data.length">
              <td :colspan="canDelete ? 7 : 6" class="px-4 py-10 text-center text-slate-400">
                {{ $t('No ratings yet.') }}
              </td>
            </tr>
            <tr v-for="row in rows.data" :key="row.uuid" class="border-b last:border-b-0 hover:bg-slate-50">
              <td class="px-4 py-3 text-slate-500 whitespace-nowrap">{{ row.submitted_at }}</td>
              <td class="px-4 py-3 font-medium text-gray-900">{{ row.contact_name || '—' }}</td>
              <!-- الرقم ظاهر عمداً: الغرض المعلن أن يتمكّنوا من معاودة الاتصال -->
              <td class="px-4 py-3 text-slate-600 whitespace-nowrap" dir="ltr">{{ row.contact_phone || '—' }}</td>
              <td class="px-4 py-3 whitespace-nowrap">
                <span class="flex gap-0.5" dir="ltr">
                  <svg v-for="s in 5" :key="s" width="15" height="15" viewBox="0 0 24 24"
                    :fill="s <= row.rating ? '#f59e0b' : 'none'" :stroke="s <= row.rating ? '#f59e0b' : '#cbd5e1'"
                    stroke-width="1.5" stroke-linejoin="round">
                    <path d="M12 2.5l2.9 5.88 6.49.95-4.7 4.58 1.11 6.46L12 17.33l-5.8 3.05 1.1-6.46-4.69-4.58 6.49-.95L12 2.5Z" />
                  </svg>
                </span>
              </td>
              <td class="px-4 py-3 text-gray-700 max-w-md">{{ row.comment || '—' }}</td>
              <td class="px-4 py-3 text-slate-500 whitespace-nowrap">{{ row.agent_name || '—' }}</td>
              <td v-if="canDelete" class="px-4 py-3 text-right">
                <button type="button" class="text-xs text-[#b91c1c] hover:underline" @click="confirmDelete(row)">
                  {{ $t('Delete') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="rows.links && rows.links.length > 3" class="mt-4 flex flex-wrap gap-1">
        <Link v-for="(link, i) in rows.links" :key="i" :href="link.url || ''"
          :class="[
            'rounded-md border px-3 py-1.5 text-sm',
            link.active ? 'bg-black text-white border-black' : 'border-slate-300 hover:bg-slate-50',
            !link.url ? 'pointer-events-none opacity-40' : ''
          ]" v-html="link.label" />
      </div>

      <!-- نوضّح سبب غياب زرّ الحذف بدل أن نتركه غامضاً -->
      <p v-if="!canDelete" class="mt-4 text-xs text-slate-400">
        {{ deletionAllowedByPlan
          ? $t('Only the business owner can delete ratings.')
          : $t('Deleting ratings is not available in your plan.') }}
      </p>
    </div>
  </AppLayout>
</template>

<script setup>
import { reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useTrans } from '@/Composables/useTrans'
import AppLayout from './Layout/App.vue'

const props = defineProps({
  rows: Object,
  summary: Object,
  filters: Object,
  canDelete: Boolean,
  deletionAllowedByPlan: Boolean,
})

const trans = useTrans()

const form = reactive({
  rating: props.filters?.rating ?? '',
  search: props.filters?.search ?? '',
})

const activeParams = () => {
  const params = {}
  Object.entries(form).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) params[k] = v
  })
  return params
}

const applyFilters = () => {
  router.get('/ratings', activeParams(), { preserveState: true, replace: true })
}

const resetFilters = () => {
  form.rating = ''
  form.search = ''
  router.get('/ratings', {}, { preserveState: true, replace: true })
}

// الحذف نهائي في نظر المستخدم — نطلب تأكيداً صريحاً قبله
const confirmDelete = (row) => {
  if (!window.confirm(trans('Delete this rating permanently? This cannot be undone.'))) return
  router.delete(`/ratings/${row.uuid}`, { preserveScroll: true })
}
</script>
