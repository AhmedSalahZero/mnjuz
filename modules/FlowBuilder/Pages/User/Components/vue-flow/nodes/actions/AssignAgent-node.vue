<template>
  <div class="assign-agent-node">
    <Handle
      type="target"
      :position="Position.Top"
      class="w-3 h-3 !bg-teal-500"
    />

    <div class="bg-white border-2 border-teal-200 rounded-lg p-4 shadow-sm w-[400px]" style="min-width: 400px;">
      <div class="flex items-center mb-3">
        <div class="flex items-center gap-2 flex-1">
          <div class="w-8 h-8 bg-slate-50 rounded-lg flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-teal-600">
              <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
              <circle cx="9" cy="7" r="4"/>
              <path d="m16 11 2 2 4-4"/>
            </svg>
          </div>
          <div>
            <h3 class="font-medium text-gray-900 text-sm">{{ $t('Transfer to agent') }}</h3>
          </div>
        </div>
        <div class="flex items-center gap-1 ml-auto">
          <button
            @click="toggleEdit"
            class="p-1 text-gray-400 hover:text-gray-600 rounded"
            :title="$t('Edit Action')"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button
            @click="deleteNode"
            class="p-1 text-red-400 hover:text-red-600 rounded"
            :title="$t('Delete Action')"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 6h18"/>
              <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/>
              <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>
            </svg>
          </button>
        </div>
      </div>

      <!-- من تُحوَّل إليهم المحادثة -->
      <div class="text-xs text-gray-600 mb-2">
        <span class="font-medium">{{ $t('Team members') }}:</span>
        <span v-if="chosenNames.length === 0" class="text-red-500"> {{ $t('No team member selected') }}</span>
        <span v-else> {{ chosenNames.join('، ') }}</span>
      </div>

      <div v-if="!ticketingActive" class="mb-2 text-xs bg-amber-50 text-amber-800 border border-amber-200 rounded-md px-2 py-1.5">
        {{ $t('Ticketing is off, so the conversation will not be assigned. Turn on tickets in Settings.') }}
      </div>

      <!-- المخرجان: يُختار أحدهما بحضور من اختيروا لحظة التحويل -->
      <div class="mb-3">
        <div class="relative border-b border-gray-200 pt-2">
          <div class="flex items-center gap-2 py-2">
            <span class="w-2 h-2 rounded-full bg-green-500 shrink-0" />
            <span class="text-xs text-gray-700">{{ $t('Available') }}</span>
          </div>
          <Handle
            type="source"
            :position="Position.Right"
            id="available"
            style="right: -17px"
          />
        </div>
        <div class="relative border-b border-gray-200 pt-2">
          <div class="flex items-center gap-2 py-2">
            <span class="w-2 h-2 rounded-full bg-amber-400 shrink-0" />
            <span class="text-xs text-gray-700">{{ $t('Not available') }}</span>
          </div>
          <Handle
            type="source"
            :position="Position.Right"
            id="unavailable"
            style="right: -17px"
          />
        </div>
      </div>

      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">{{ $t('Action') }}</span>
        <div class="flex items-center gap-1">
          <span
            :class="isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
            class="px-2 py-1 rounded-full text-xs"
          >
            {{ isActive ? $t('Active') : $t('Inactive') }}
          </span>
        </div>
      </div>
    </div>

    <!-- Edit Modal -->
    <NodeEditModal :open="showEditModal" @close="closeEditModal">
      <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">{{ $t('Edit transfer to agent') }}</h3>
      </div>

      <form @submit.prevent="saveAction" class="p-6">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">{{ $t('Team members') }}</label>
            <ChannelPicker
              v-model="editForm.config.user_ids"
              :options="teamMembers"
              name="user_ids"
            />
            <div v-if="teamMembers.length === 0" class="text-xs text-red-500 mt-1">
              {{ $t('No team member selected') }}
            </div>
          </div>

          <div class="bg-gray-50 border border-blue-200 rounded-lg p-3 space-y-1">
            <p class="text-xs text-gray-600">
              {{ $t('Online now: the least busy of them gets the conversation.') }}
            </p>
            <p class="text-xs text-gray-600">
              {{ $t('Nobody online: the least busy still gets it, and the customer follows the Not available path.') }}
            </p>
          </div>

          <div class="flex items-center">
            <FormCheckbox
              v-model="editForm.is_active"
              :label="$t('Active')"
              class="h-4 w-4 text-teal-600 focus:ring-teal-500 border-gray-300 rounded"
            />
          </div>
        </div>

        <div class="flex justify-end space-x-3 mt-6">
          <button
            type="button"
            @click="closeEditModal"
            class="px-4 py-2 text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
          >
            {{ $t('Cancel') }}
          </button>
          <button
            type="submit"
            class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors"
          >
            {{ $t('Save Changes') }}
          </button>
        </div>
      </form>
    </NodeEditModal>
  </div>
</template>

<script setup>
/**
 * خطوة «تحويل إلى موظف»: تُسند المحادثة لأحد من يختارهم التاجر، ولها مخرجان.
 *
 * «متاح» حين يكون أحدهم متصلاً الآن (ويأخذها الأقلّ انشغالاً منهم). «غير
 * متاح» حين لا أحد — تُسند مع ذلك للأقلّ انشغالاً، ويوصل التاجر هذا المخرج
 * بعقدة نصّ أو أزرار أو قائمة لتصل العميلَ رسالته.
 */
import { useTrans } from '@/Composables/useTrans'
import NodeEditModal from './NodeEditModal.vue'
import { ref, reactive, computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import FormCheckbox from '../../../../../../../../resources/js/Components/FormCheckbox.vue'
import ChannelPicker from '../../../../../../../../resources/js/Components/ChannelPicker.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const trans = useTrans()
const page = usePage()

const { removeNodes } = useVueFlow()
const node = useNode()

// أعضاء الفريق النشطون بالمعرّف والاسم فقط (FlowController).
const teamMembers = computed(() => page.props.teamMembers || [])
const ticketingActive = computed(() => page.props.ticketingActive !== false)

const config = computed(() => props.data?.config || {})
const isActive = computed(() => props.data?.is_active !== false)

const showEditModal = ref(false)

const chosenIds = computed(() => (Array.isArray(config.value.user_ids) ? config.value.user_ids : [])
  .map((id) => Number(id))
  .filter((id) => Number.isInteger(id) && id > 0))

/** أسماء من اختيروا؛ من غادر الفريق بعد الحفظ يظهر «عضو أُزيل». */
const chosenNames = computed(() => chosenIds.value.map((id) => {
  const member = teamMembers.value.find((m) => Number(m.value) === id)
  return member ? member.label : trans('Removed member')
}))

const editForm = reactive({
  config: { user_ids: [] },
  is_active: true,
})

const toggleEdit = () => {
  editForm.config = {
    user_ids: chosenIds.value.filter((id) => teamMembers.value.some((m) => Number(m.value) === id)),
  }
  editForm.is_active = isActive.value
  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const saveAction = () => {
  node.node.data = {
    ...node.node.data,
    actionType: 'assign_agent',
    config: { user_ids: editForm.config.user_ids.map((id) => Number(id)) },
    is_active: editForm.is_active,
  }

  closeEditModal()
}

const deleteNode = () => {
  if (confirm(trans('Are you sure you want to delete this action?'))) {
    removeNodes([props.id])
  }
}
</script>

<style scoped>
.assign-agent-node {
  position: relative;
}

.assign-agent-node:hover {
  z-index: 10;
}
</style>
