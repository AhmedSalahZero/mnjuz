<template>
  <SettingLayout :modules="props.modules">
    <div class="md:h-[90vh]">
      <div class="flex justify-center items-center">
        <div class="md:w-[60em] w-full">
          <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 px-4 pb-6">
            <div class="w-full py-2 mb-2 mt-2">
              <h4 class="text-[16px]">{{ $t('Working hours') }}</h4>
              <p class="text-slate-500 mt-1 mb-4">
                {{ $t('Define one or more intervals per day. Outside these hours, WhatsApp contacts receive an automatic reply with your weekly schedule.') }}
              </p>

              <div v-for="(row, index) in form.slots" :key="index" class="border border-slate-200 rounded-lg p-4 mb-3">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                  <div class="md:col-span-4">
                    <FormSelect
                      v-model="row.day"
                      :options="dayOptions"
                      :name="$t('Day')"
                      className="w-full"
                      :placeholder="$t('Day')"
                    />
                  </div>
                  <div class="md:col-span-3">
                    <label class="block text-xs text-slate-600 mb-1">{{ $t('Start') }}</label>
                    <input
                      v-model="row.open"
                      type="time"
                      step="60"
                      class="w-full border border-slate-300 rounded-md px-2 py-2"
                    />
                  </div>
                  <div class="md:col-span-3">
                    <label class="block text-xs text-slate-600 mb-1">{{ $t('End') }}</label>
                    <input
                      v-model="row.close"
                      type="time"
                      step="60"
                      class="w-full border border-slate-300 rounded-md px-2 py-2"
                    />
                  </div>
                  <div class="md:col-span-2 flex md:justify-end">
                    <button
                      type="button"
                      class="text-red-600 text-sm hover:underline"
                      @click="removeRow(index)">
                      {{ $t('Remove') }}
                    </button>
                  </div>
                </div>
              </div>

              <div class="flex flex-wrap gap-3 mt-2">
                <button
                  type="button"
                  class="rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                  @click="addRow">
                  {{ $t('Add time slot') }}
                </button>
                <button
                  type="button"
                  class="rounded-md bg-black px-3 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-50"
                  :disabled="form.processing"
                  @click="submitForm">
                  {{ $t('Save') }}
                </button>
              </div>
              <p v-if="form.errors.slots" class="text-red-600 text-sm mt-2">{{ form.errors.slots }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </SettingLayout>
</template>

<script setup>
import SettingLayout from './Layout.vue'
import FormSelect from '@/Components/FormSelect.vue'
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { locale } = useI18n()

const props = defineProps({
  modules: Array,
  working_hours: { type: Array, default: () => [] },
})

/** 2024-01-07 is Sunday — matches PHP date('w') 0–6 */
const dayOptions = computed(() => {
  const loc = locale.value || 'ar'
  const fmt = new Intl.DateTimeFormat(loc, { weekday: 'long' })
  return Array.from({ length: 7 }, (_, d) => {
    const date = new Date(2024, 0, 7 + d)
    return { value: d, label: fmt.format(date) }
  })
})

const normalizeSlots = (rows) => {
  if (!Array.isArray(rows) || rows.length === 0) {
    return []
  }
  return rows.map((r) => ({
    day: Number(r.day),
    open: (r.open || '09:00').slice(0, 5),
    close: (r.close || '17:00').slice(0, 5),
  }))
}

const form = useForm({
  slots: normalizeSlots(props.working_hours),
})

const addRow = () => {
  form.slots.push({ day: 6, open: '09:00', close: '17:00' })
}

const removeRow = (index) => {
  form.slots.splice(index, 1)
}

const submitForm = () => {
  form.slots = (form.slots || []).map((s) => ({
    day: Number(s.day),
    open: s.open,
    close: s.close,
  }))
  form.post('/settings/working-hours', {
    preserveScroll: true,
  })
}
</script>
