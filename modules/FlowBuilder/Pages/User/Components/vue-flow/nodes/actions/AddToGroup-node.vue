<template>
  <div class="add-to-group-node">
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
            </svg>
          </div>
          <div>
            <h3 class="font-medium text-gray-900 text-sm">{{ actionName }}</h3>
          </div>
        </div>
        <div class="flex items-center gap-1 ml-auto">
          <button
            @click="toggleEdit"
            class="p-1 text-gray-400 hover:text-gray-600 rounded"
            title="Edit Action"
          >
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button
            @click="deleteNode"
            class="p-1 text-red-400 hover:text-red-600 rounded"
            title="Delete Action"
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
          <span class="font-medium">Add to:</span> {{ getGroupName(config.group_id) }}
        </div>
      </div>
      
      <div class="flex items-center justify-between text-xs">
        <span class="text-gray-500">Action</span>
        <div class="flex items-center gap-1">
          <span 
            :class="isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
            class="px-2 py-1 rounded-full text-xs"
          >
            {{ isActive ? 'Active' : 'Inactive' }}
          </span>
        </div>
      </div>
    </div>

    <!-- Single exit handle -->
    <Handle
      type="source"
      :position="Position.Bottom"
      class="w-3 h-3 !bg-teal-500"
    />

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg max-w-md w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">Edit Add to Group Action</h3>
        </div>
        
        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Configuration Fields -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Configuration</label>
              <div class="space-y-3">
                <!-- Add to Group -->
                <div>
                  <label class="block text-sm font-medium text-gray-700">Select Group</label>
                  <FormSelect
                    v-model="editForm.config.group_id"
                    :options="availableGroups"
                    placeholder="Select a group"
                    required
                  />
                  <div v-if="availableGroups.length === 0" class="text-xs text-red-500 mt-1">
                    No groups available. Please check if groups exist.
                  </div>
                </div>

                <!-- What happens explanation -->
                <div class="bg-gray-50 border border-blue-200 rounded-lg p-3">
                  <p class="text-xs text-gray-600 mb-1">
                    <strong>What happens:</strong> The contact will be automatically added to the selected group.
                  </p>
                  <p class="text-xs text-gray-500">
                    If the contact is already in the group, no action will be taken.
                  </p>
                </div>
              </div>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
              <FormCheckbox
                v-model="editForm.is_active"
                label="Active"
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
              Cancel
            </button>
            <button 
              type="submit"
              class="px-4 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition-colors"
            >
              Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, inject } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import FormSelect from '../../../../../../../../resources/js/Components/FormSelect.vue'
import FormCheckbox from '../../../../../../../../resources/js/Components/FormCheckbox.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes } = useVueFlow()
const node = useNode()

// Inject contact groups from parent component
const contactGroups = inject('contactGroups', [])

const config = computed(() => props.data?.config || {})
const isActive = computed(() => props.data?.is_active !== false)

const showEditModal = ref(false)
const availableGroups = computed(() => contactGroups || [])

const actionName = computed(() => {
  const actionConfig = config.value
  
  if (actionConfig.group_id) {
    const group = availableGroups.value.find(g => g.value == actionConfig.group_id)
    return group ? `Add to ${group.label}` : `Add to Group (ID: ${actionConfig.group_id})`
  }
  return 'Add to Group'
})

const editForm = reactive({
  config: {},
  is_active: true
})

const getGroupName = (groupId) => {
  if (!groupId) return 'No group selected'
  const group = availableGroups.value.find(g => g.value == groupId)
  return group ? group.label : `Group ID: ${groupId}`
}

const toggleEdit = () => {
  // Initialize edit form with current values
  editForm.config = {
    group_id: config.value.group_id || ''
  }
  editForm.is_active = isActive.value
  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const saveAction = () => {
  // Update the node data directly using useNode
  node.node.data = {
    ...node.node.data,
    actionType: 'add_to_group',
    config: editForm.config,
    is_active: editForm.is_active
  }
  
  closeEditModal()
}

const deleteNode = () => {
  if (confirm('Are you sure you want to delete this action?')) {
    removeNodes([props.id])
  }
}

onMounted(() => {
  // Node data loaded
})
</script>

<style scoped>
.add-to-group-node {
  position: relative;
}

.add-to-group-node:hover {
  z-index: 10;
}
</style> 