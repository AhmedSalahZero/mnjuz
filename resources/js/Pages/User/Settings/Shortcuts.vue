<template>
  <SettingLayout :modules="props.modules">
    <div class="md:h-[90vh]">
      <div class="flex justify-center">
        <div class="md:w-[60em] w-full">
          <div class="bg-white border border-slate-200 rounded-lg text-sm mb-20 px-4 py-4">
            <div class="flex items-start justify-between mb-4 mt-2">
              <div>
                <h4 class="text-[16px]">{{ $t('Shortcuts') }}</h4>
                <div class="text-slate-500">
                  {{ $t('Create quick replies. Agents type / in the chat to insert and send them instantly.') }}
                </div>
              </div>
              <button type="button" @click="addRow"
                class="flex-shrink-0 rounded-md bg-black px-3 py-2 text-white text-sm hover:bg-slate-700">
                + {{ $t('Add') }}
              </button>
            </div>

            <div v-if="rows.length === 0" class="text-slate-400 text-center py-8">
              {{ $t('No shortcuts yet.') }}
            </div>

            <div v-for="(row, index) in rows" :key="index"
              class="border rounded-xl p-3 mb-3 grid grid-cols-1 md:grid-cols-12 gap-3 items-start">
              <div class="md:col-span-3">
                <label class="block text-xs text-slate-500 mb-1">{{ $t('Shortcut') }}</label>
                <div class="flex items-center rounded-md border px-2" :class="{ 'bg-slate-50': !row.editable }">
                  <span class="text-slate-400">/</span>
                  <input v-model="row.command" type="text" :placeholder="$t('e.g. hello')"
                    :disabled="!row.editable"
                    class="w-full outline-none px-1 py-2 text-sm disabled:bg-transparent disabled:text-slate-500" />
                </div>
              </div>
              <div class="md:col-span-6">
                <label class="block text-xs text-slate-500 mb-1">{{ $t('Message') }}</label>
                <textarea v-model="row.message" rows="2" :placeholder="$t('Reply text...')"
                  :disabled="!row.editable"
                  class="w-full rounded-md border px-2 py-2 text-sm outline-none resize-none disabled:bg-slate-50 disabled:text-slate-500"></textarea>
              </div>
              <div class="md:col-span-2">
                <label class="block text-xs text-slate-500 mb-1">{{ $t('Visibility') }}</label>
                <select v-model="row.scope" :disabled="!row.editable"
                  class="w-full rounded-md border px-2 py-2 text-sm outline-none bg-white disabled:bg-slate-50 disabled:text-slate-500">
                  <option value="personal">{{ $t('Only me') }}</option>
                  <option value="company" :disabled="!canManageCompany">{{ $t('Whole company') }}</option>
                </select>
              </div>
              <div class="md:col-span-1 flex md:justify-center md:pt-6">
                <button v-if="row.editable" type="button" @click="removeRow(index)" :title="$t('Delete')"
                  class="rounded-full p-2 text-red-500 hover:bg-red-50">
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M6 19a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7H6zM19 4h-3.5l-1-1h-5l-1 1H5v2h14z"/></svg>
                </button>
              </div>
            </div>

            <div class="pt-2">
              <button type="button" @click="save" :disabled="form.processing"
                class="rounded-md bg-black px-4 py-2 text-white text-sm hover:bg-slate-700 disabled:opacity-50">
                {{ $t('Save') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </SettingLayout>
</template>

<script setup>
import SettingLayout from './Layout.vue'
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
  shortcuts: { type: Array, default: () => [] },
  canManageCompany: { type: Boolean, default: false },
  currentUserId: { type: Number, default: null },
  modules: { type: Array, default: () => [] },
})

const canManageCompany = props.canManageCompany

const rows = ref(
  (props.shortcuts || []).map((s) => ({
    id: s.id,
    command: s.command,
    message: s.message,
    scope: s.scope,
    // اختصارات الشركة يديرها المدير فقط
    editable: s.scope !== 'company' || props.canManageCompany,
  }))
)

const form = useForm({ shortcuts: [] })

const addRow = () => {
  rows.value.push({ id: null, command: '', message: '', scope: 'personal', editable: true })
}

const removeRow = (index) => {
  rows.value.splice(index, 1)
}

const save = () => {
  // أرسل فقط الصفوف القابلة للتحرير حتى لا يُعاد إرسال اختصارات الشركة من حساب الموظف.
  form.shortcuts = rows.value
    .filter((r) => r.editable && r.command && r.command.trim() !== '' && r.message && r.message.trim() !== '')
    .map((r) => ({
      id: r.id,
      command: r.command.trim(),
      message: r.message,
      scope: r.scope,
    }))

  form.post('/settings/shortcuts', { preserveScroll: true })
}
</script>
