<template>
  <AppLayout>
    <div class="p-4 md:p-8 h-full overflow-y-auto">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
        <div>
          <h1 class="text-xl font-semibold text-gray-900">{{ $t('Agent Performance') }}</h1>
          <p class="text-sm text-slate-500">{{ $t('Track your team activity and responsiveness.') }}</p>
        </div>
        <form @submit.prevent="applyRange" class="flex items-end gap-2">
          <div>
            <label class="block text-xs text-slate-500 mb-1">{{ $t('From') }}</label>
            <input v-model="range.from" type="date" class="rounded-md border px-2 py-2 text-sm outline-none" />
          </div>
          <div>
            <label class="block text-xs text-slate-500 mb-1">{{ $t('To') }}</label>
            <input v-model="range.to" type="date" class="rounded-md border px-2 py-2 text-sm outline-none" />
          </div>
          <button type="submit" class="rounded-md bg-black px-4 py-2 text-white text-sm hover:bg-slate-700">
            {{ $t('Apply') }}
          </button>
        </form>
      </div>

      <div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-slate-500 border-b bg-slate-50">
              <th class="px-4 py-3 font-medium">{{ $t('Agent') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('Status') }}</th>
              <th class="px-4 py-3 font-medium text-center">{{ $t('Messages') }}</th>
              <th class="px-4 py-3 font-medium text-center">{{ $t('Assigned') }}</th>
              <th class="px-4 py-3 font-medium text-center">{{ $t('Closed') }}</th>
              <th class="px-4 py-3 font-medium text-center">{{ $t('Avg first response') }}</th>
              <th class="px-4 py-3 font-medium text-center">{{ $t('Avg resolution') }}</th>
              <th class="px-4 py-3 font-medium text-center">{{ $t('Active time') }}</th>
              <th class="px-4 py-3 font-medium">{{ $t('Last activity') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="agent in metrics.agents" :key="agent.user_id" class="border-b last:border-b-0 hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900">{{ agent.name }}</div>
                <div class="text-xs text-slate-400">{{ $t(roleLabel(agent.role)) }}</div>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center gap-1.5">
                  <span class="h-2 w-2 rounded-full" :class="agent.online ? 'bg-green-500' : 'bg-slate-300'"></span>
                  <span class="text-xs" :class="agent.online ? 'text-green-600' : 'text-slate-400'">
                    {{ agent.online ? $t('Online') : $t('Offline') }}
                  </span>
                </span>
              </td>
              <td class="px-4 py-3 text-center">{{ agent.messages_sent }}</td>
              <td class="px-4 py-3 text-center">{{ agent.tickets_assigned }}</td>
              <td class="px-4 py-3 text-center">{{ agent.tickets_closed }}</td>
              <td class="px-4 py-3 text-center">{{ formatDuration(agent.avg_first_response_seconds) }}</td>
              <td class="px-4 py-3 text-center">{{ formatDuration(agent.avg_resolution_seconds) }}</td>
              <td class="px-4 py-3 text-center">{{ formatDuration(agent.active_seconds) }}</td>
              <td class="px-4 py-3 text-slate-500">{{ formatLastActivity(agent.last_activity_at) }}</td>
            </tr>
            <tr v-if="!metrics.agents || metrics.agents.length === 0">
              <td colspan="9" class="px-4 py-8 text-center text-slate-400">{{ $t('No data available.') }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from './Layout/App.vue'
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
  metrics: { type: Object, default: () => ({ agents: [] }) },
  filters: { type: Object, default: () => ({}) },
})

const range = ref({
  from: props.filters.from,
  to: props.filters.to,
})

const applyRange = () => {
  router.get('/performance', { from: range.value.from, to: range.value.to }, {
    preserveState: true,
    preserveScroll: true,
  })
}

const roleLabel = (role) => {
  const map = { owner: 'Owner', manager: 'Manager', agent: 'Agent' }
  return map[role] || role
}

const formatDuration = (seconds) => {
  if (seconds === null || seconds === undefined) return '—'
  if (seconds < 60) return `${seconds}s`
  const m = Math.floor(seconds / 60)
  if (m < 60) return `${m}m ${seconds % 60}s`
  const h = Math.floor(m / 60)
  if (h < 24) return `${h}h ${m % 60}m`
  const d = Math.floor(h / 24)
  return `${d}d ${h % 24}h`
}

const formatLastActivity = (value) => {
  if (!value) return '—'
  const date = new Date(value.replace(' ', 'T') + (value.endsWith('Z') ? '' : 'Z'))
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('en-US', {
    month: 'short', day: 'numeric', hour: 'numeric', minute: 'numeric', hour12: true,
  }).format(date)
}
</script>
