<template>
  <AppLayout>
    <div class="p-4 md:p-8 h-full overflow-y-auto">
      <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-4">
        <div>
          <h1 class="text-xl font-semibold text-gray-900">{{ $t('Activity Log') }}</h1>
          <p class="text-sm text-slate-500">{{ $t('Every action taken by members of this organization.') }}</p>
        </div>
        <a :href="exportUrl"
          class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50 whitespace-nowrap">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
            <polyline points="7 10 12 15 17 10" />
            <line x1="12" y1="15" x2="12" y2="3" />
          </svg>
          {{ $t('Export CSV') }}
        </a>
      </div>

      <!-- إعلان الاحتفاظ: المستخدم يجب أن يعرف أن ما يراه مؤقّت قبل أن يبني عليه -->
      <div class="mb-5 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
          stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 shrink-0">
          <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
        <span>{{ retentionNotice }}</span>
      </div>

      <form @submit.prevent="applyFilters" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <div>
          <label class="block text-xs text-slate-500 mb-1">{{ $t('Member') }}</label>
          <select v-model="form.user_id" class="w-full rounded-md border px-2 py-2 text-sm outline-none">
            <option value="">{{ $t('All') }}</option>
            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-500 mb-1">{{ $t('Category') }}</label>
          <select v-model="form.group" class="w-full rounded-md border px-2 py-2 text-sm outline-none">
            <option value="">{{ $t('All') }}</option>
            <option v-for="g in groups" :key="g" :value="g">{{ $t(groupLabel(g)) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs text-slate-500 mb-1">{{ $t('From') }}</label>
          <input v-model="form.from" type="date" class="w-full rounded-md border px-2 py-2 text-sm outline-none" />
        </div>
        <div>
          <label class="block text-xs text-slate-500 mb-1">{{ $t('To') }}</label>
          <input v-model="form.to" type="date" class="w-full rounded-md border px-2 py-2 text-sm outline-none" />
        </div>
        <div class="lg:col-span-1">
          <label class="block text-xs text-slate-500 mb-1">{{ $t('Search') }}</label>
          <input v-model="form.search" type="text" :placeholder="$t('Member or subject')"
            class="w-full rounded-md border px-2 py-2 text-sm outline-none" />
        </div>
        <div class="flex items-end gap-2">
          <button type="submit" class="rounded-md bg-black px-4 py-2 text-white text-sm hover:bg-slate-700">
            {{ $t('Apply') }}
          </button>
          <button type="button" @click="resetFilters"
            class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
            {{ $t('Reset') }}
          </button>
        </div>
      </form>

      <div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-slate-500 border-b bg-slate-50">
              <th class="px-4 py-3 font-medium whitespace-nowrap">{{ $t('Date') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('Member') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('Activity') }}</th>
              <th class="px-4 py-3 font-medium whitespace-nowrap">IP</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!rows.data.length">
              <td colspan="4" class="px-4 py-10 text-center text-slate-400">{{ $t('No activity recorded yet.') }}</td>
            </tr>
            <tr v-for="row in rows.data" :key="row.id" class="border-b last:border-b-0 hover:bg-slate-50">
              <td class="px-4 py-3 text-slate-500 whitespace-nowrap">{{ row.created_at }}</td>
              <td class="px-4 py-3 font-medium text-gray-900">{{ row.user_name || '—' }}</td>
              <td class="px-4 py-3 text-gray-700">{{ row.description }}</td>
              <td class="px-4 py-3 text-slate-400 whitespace-nowrap">{{ row.ip || '—' }}</td>
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
    </div>
  </AppLayout>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from './Layout/App.vue'

const props = defineProps({
  rows: Object,
  retentionNotice: String,
  members: Array,
  groups: Array,
  filters: Object,
})

const form = reactive({
  user_id: props.filters?.user_id ?? '',
  group: props.filters?.group ?? '',
  from: props.filters?.from ?? '',
  to: props.filters?.to ?? '',
  search: props.filters?.search ?? '',
})

const activeParams = () => {
  const params = {}
  Object.entries(form).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) params[k] = v
  })
  return params
}

// التصدير يحمل نفس المرشِّحات الظاهرة، فيُصدَّر ما يُرى لا الجدول كلّه.
const exportUrl = computed(() => {
  const qs = new URLSearchParams(activeParams()).toString()
  return '/activity-log/export' + (qs ? `?${qs}` : '')
})

const applyFilters = () => {
  router.get('/activity-log', activeParams(), { preserveState: true, preserveScroll: true })
}

const resetFilters = () => {
  Object.keys(form).forEach((k) => { form[k] = '' })
  router.get('/activity-log')
}

const groupLabels = {
  account: 'Account',
  contacts: 'Contacts',
  chats: 'Chats',
  tickets: 'Tickets',
  team: 'Team',
  campaigns: 'Campaigns',
  settings: 'Settings',
}
const groupLabel = (g) => groupLabels[g] ?? g
</script>
