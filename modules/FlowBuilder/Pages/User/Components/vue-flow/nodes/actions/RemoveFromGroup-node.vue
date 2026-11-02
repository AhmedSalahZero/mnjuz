<template>
  <div class="remove-from-group-node">
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
              <path d="M5 8h8"/>
              <path d="M5 12h8"/>
            </svg>
          </div>
          <div>
            <h3 class="font-medium text-gray-900 text-sm">{{ actionName }}</h3>
          </div>
        </div>
        <!-- لا زر تعديل: الإجراء بلا إعدادات، وحالة التفعيل تُبدَّل من الشارة أسفل البطاقة -->
        <div class="flex items-center gap-1 ml-auto">
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
      
      <div class="text-xs text-gray-600 mb-2">
        <div>
          <span class="font-medium">{{ $t('Removes from:') }}</span> {{ $t('All groups') }}
        </div>
        <div class="text-gray-500 mt-1">
          {{ $t('Marks the contact as opted out of marketing') }}
        </div>
        <div class="text-amber-700 mt-1">
          {{ $t('This cannot be undone from the flow. Re-subscribing a contact has to be done deliberately.') }}
        </div>
      </div>

      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">{{ $t('Action') }}</span>
        <div class="flex items-center gap-1">
          <!-- nodrag: الضغط على الشارة يبدّل الحالة بدل أن يبدأ سحب العقدة -->
          <button
            type="button"
            role="switch"
            :aria-checked="isActive"
            @click.stop="toggleActive"
            :class="isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
            class="nodrag px-2 py-1 rounded-full text-xs cursor-pointer hover:opacity-80"
          >
            {{ isActive ? $t('Active') : $t('Inactive') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Single exit handle -->
    <Handle
      type="source"
      :position="Position.Bottom"
      class="w-3 h-3 !bg-teal-500"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes } = useVueFlow()
const node = useNode()

const isActive = computed(() => props.data?.is_active !== false)

// الاسم ثابت: الإجراء لم يعد يخصّ مجموعة بعينها.
const actionName = computed(() => 'Remove from all groups')

// تعديل data يكفي للحفظ: main-canvas يراقب العقد ويستدعي saveNodesAndEdges.
const toggleActive = () => {
  node.node.data = {
    ...node.node.data,
    actionType: 'remove_from_group',
    config: {},
    is_active: !isActive.value,
  }
}

const deleteNode = () => {
  if (confirm('Are you sure you want to delete this action?')) {
    removeNodes([props.id])
  }
}
</script>

<style scoped>
.remove-from-group-node {
  position: relative;
}

.remove-from-group-node:hover {
  z-index: 10;
}
</style> 