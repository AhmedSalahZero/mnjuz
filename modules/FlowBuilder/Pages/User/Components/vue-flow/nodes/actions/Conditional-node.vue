<template>
  <div class="conditional-node">
    <Handle
      type="target"
      :position="Position.Left"
      class="w-3 h-3 !bg-teal-500"
    />
    
    <div class="bg-white border-2 border-teal-200 rounded-lg p-4 shadow-sm w-[400px]" style="min-width: 400px;">
      <div class="flex items-center mb-3">
        <div class="flex items-center gap-2 flex-1">
          <div class="w-8 h-8 bg-slate-50 rounded-lg flex items-center justify-center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-teal-600">
              <path d="M9 11l3 3L22 4"/>
              <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
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
        <div class="space-y-0">
          <!-- Condition type header -->
          <div class="text-xs font-medium text-gray-700 mb-2">
            {{ getConditionTypeLabel(config.condition_type) }}
          </div>
          
          <!-- Conditions list -->
          <div v-for="(condition, index) in config.conditions || []" :key="index" class="relative border-b border-gray-200 pt-2">
            <div class="flex items-center justify-between py-2">
              <div class="flex-1">
                <span class="text-xs text-gray-600">{{ getConditionValue(condition) }}</span>
              </div>
            </div>

            <!-- Dynamic condition exit handles -->
            <Handle
              :key="`condition-${index}`"
              type="source"
              :position="Position.Right"
              :id="`condition-${index}|${props.id}`"
              style="right: -17px"
            />
          </div>
          
          <!-- Default exit row -->
          <div class="relative border-b border-gray-200 pt-2">
            <div class="flex items-center justify-between py-2">
              <div class="flex-1">
                <span class="text-xs text-gray-500">Default: No conditions match</span>
              </div>
            </div>

            <!-- Default exit handle (when no conditions match) -->
            <Handle
              type="source"
              :position="Position.Right"
              :id="`default|${props.id}`"
              style="right: -17px"
            />
          </div>
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

    <!-- Edit Modal -->
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg max-w-md w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">Edit Conditional Action</h3>
        </div>
        
        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Configuration Fields -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Configuration</label>
              <div class="space-y-3">
                <!-- Conditional -->
                <div class="space-y-4">
                  <div>
                    <FormSelect
                      v-model="editForm.config.condition_type"
                      name="Condition Type"
                      :options="[
                        { value: 'message_contains', label: 'Message Contains' },
                        { value: 'message_equals', label: 'Message Equals' },
                        { value: 'contact_field', label: 'Contact Field' }
                      ]"
                      required
                    />
                  </div>
                  
                  <!-- Contact field configuration -->
                  <div v-if="editForm.config.condition_type === 'contact_field'">
                    <div class="space-y-3">
                      <FormSelect
                        v-model="editForm.config.field_name"
                        name="Contact Field"
                        :options="availableContactFields"
                        required
                      />
                      <FormSelect
                        v-model="editForm.config.field_operator"
                        name="Operator"
                        :options="[
                          { value: 'equals', label: 'Equals' },
                          { value: 'contains', label: 'Contains' },
                          { value: 'not_equals', label: 'Not Equals' },
                          { value: 'is_empty', label: 'Is Empty' },
                          { value: 'is_not_empty', label: 'Is Not Empty' }
                        ]"
                        required
                      />
                    </div>
                  </div>
                  
                  <!-- Values List -->
                  <div class="space-y-3">
                    <div class="flex items-center justify-between">
                      <h4 class="text-xs font-medium text-gray-600 uppercase tracking-wide">Values</h4>
                      <button
                        type="button"
                        @click="addCondition"
                        class="text-xs bg-teal-600 text-white px-2 py-1 rounded hover:bg-teal-700"
                      >
                        Add Value
                      </button>
                    </div>
                    
                    <div v-if="editForm.config.conditions && editForm.config.conditions.length > 0" class="space-y-2">
                      <div 
                        v-for="(condition, index) in editForm.config.conditions" 
                        :key="index"
                        class="border border-gray-200 rounded-lg p-3 bg-gray-50"
                      >
                        <div class="flex items-center justify-between mb-2">
                          <span class="text-xs font-medium text-gray-700">Value {{ index + 1 }}</span>
                          <button
                            type="button"
                            @click="removeCondition(index)"
                            class="text-red-500 hover:text-red-700 text-xs"
                          >
                            Remove
                          </button>
                        </div>
                        
                        <div>
                          <FormInput
                            v-model="condition.value"
                            name="Value"
                            type="text"
                            :placeholder="getValuePlaceholder(editForm.config.condition_type)"
                            required
                          />
                        </div>
                      </div>
                    </div>
                    
                    <div v-else class="text-center py-4 text-gray-500 text-sm">
                      No values added yet. Click "Add Value" to create your first condition.
                    </div>
                  </div>
                  
                  <!-- Explanation -->
                  <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm font-medium text-blue-800 mb-3">
                      How Conditional Flow Works:
                    </p>
                    <div class="space-y-2 text-sm text-blue-700">
                      <p class="text-xs">• Each value will be evaluated against the selected condition type</p>
                      <p class="text-xs">• When a value matches, flow will branch to that value's connected node</p>
                      <p class="text-xs">• If no values match, flow will continue to the default path</p>
                      <p class="text-xs">• You can connect multiple nodes to different values</p>
                    </div>
                  </div>
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
import FormInput from '../../../../../../../../resources/js/Components/FormInput.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes } = useVueFlow()
const node = useNode()

// Inject contact fields from parent component
const contactFields = inject('contactFields', [])

const config = computed(() => props.data?.config || {})
const isActive = computed(() => props.data?.is_active !== false)

const showEditModal = ref(false)

// Computed property for available contact fields
const availableContactFields = computed(() => {
  const fields = []
  
  // Standard fields
  fields.push(
    { value: 'first_name', label: 'First Name' },
    { value: 'last_name', label: 'Last Name' },
    { value: 'email', label: 'Email' }
  )
  
  // Address fields
  fields.push(
    { value: 'address.street', label: 'Street' },
    { value: 'address.city', label: 'City' },
    { value: 'address.state', label: 'State' },
    { value: 'address.zip', label: 'Zip Code' },
    { value: 'address.country', label: 'Country' }
  )
  
  // Custom fields
  contactFields.forEach(field => {
    fields.push({
      value: `metadata.${field.name}`,
      label: field.name
    })
  })
  
  return fields
})

const actionName = computed(() => {
  const actionConfig = config.value
  const conditionCount = actionConfig.conditions?.length || 0
  return conditionCount > 0 ? `Conditional (${conditionCount} conditions)` : 'Conditional'
})

const editForm = reactive({
  config: {},
  is_active: true
})

const toggleEdit = () => {
  // Initialize edit form with current values
  const currentConfig = config.value || {}
  editForm.config = {
    condition_type: currentConfig.condition_type || 'message_contains',
    field_name: currentConfig.field_name || '',
    field_operator: currentConfig.field_operator || 'equals',
    conditions: currentConfig.conditions || []
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
    actionType: 'conditional',
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

const addCondition = () => {
  editForm.config.conditions = [...(editForm.config.conditions || []), {
    value: ''
  }];
};

const removeCondition = (index) => {
  editForm.config.conditions.splice(index, 1);
};

const getConditionHandleStyle = (index) => {
  const conditionCount = config.value.conditions?.length || 0;
  
  // Calculate exact pixel positions based on the actual layout
  // Node structure:
  // - Top padding: ~16px
  // - Header: ~16px + 8px margin bottom = 24px
  // - Each condition row: 8px padding top + ~16px content + 8px padding bottom + 1px border = 33px
  // - Default row: 8px padding top + ~16px content + 1px border = 25px
  // - Bottom padding: ~16px
  
  const nodeTopPadding = 16;
  const headerHeight = 24; // 16px content + 8px margin bottom
  const conditionRowHeight = 33; // 8px top + 16px content + 8px bottom + 1px border
  const defaultRowHeight = 25; // 8px top + 16px content + 1px border
  const nodeBottomPadding = 16;
  
  // Calculate the position of the bottom border for each condition row
  let cumulativeHeight = nodeTopPadding + headerHeight;
  
  // Add height of all previous condition rows
  for (let i = 0; i < index; i++) {
    cumulativeHeight += conditionRowHeight;
  }
  
  // Add the current condition row height (position at the bottom border)
  cumulativeHeight += conditionRowHeight;
  
  // Calculate total node height
  const totalNodeHeight = nodeTopPadding + headerHeight + (conditionCount * conditionRowHeight) + defaultRowHeight + nodeBottomPadding;
  
  // Convert to percentage
  const topPercentage = (cumulativeHeight / totalNodeHeight) * 100;
  
  return { top: `${Math.max(5, Math.min(95, topPercentage))}%` };
};

const getDefaultHandleStyle = () => {
  const conditionCount = config.value.conditions?.length || 0;
  
  // Calculate exact pixel positions based on the actual layout
  // Node structure:
  // - Top padding: ~16px
  // - Header: ~16px + 8px margin bottom = 24px
  // - Each condition row: 8px padding top + ~16px content + 8px padding bottom + 1px border = 33px
  // - Default row: 8px padding top + ~16px content + 1px border = 25px
  // - Bottom padding: ~16px
  
  const nodeTopPadding = 16;
  const headerHeight = 24; // 16px content + 8px margin bottom
  const conditionRowHeight = 33; // 8px top + 16px content + 8px bottom + 1px border
  const defaultRowHeight = 25; // 8px top + 16px content + 1px border
  const nodeBottomPadding = 16;
  
  // Calculate the position of the bottom border of the default row
  let cumulativeHeight = nodeTopPadding + headerHeight;
  
  // Add height of all condition rows
  cumulativeHeight += conditionCount * conditionRowHeight;
  
  // Add the default row height (position at the bottom border)
  cumulativeHeight += defaultRowHeight;
  
  // Calculate total node height
  const totalNodeHeight = nodeTopPadding + headerHeight + (conditionCount * conditionRowHeight) + defaultRowHeight + nodeBottomPadding;
  
  // Convert to percentage
  const topPercentage = (cumulativeHeight / totalNodeHeight) * 100;
  
  return { top: `${Math.max(5, Math.min(95, topPercentage))}%` };
};

const getConditionTypeLabel = (type) => {
  switch (type) {
    case 'message_contains':
      return 'Message Contains';
    case 'message_equals':
      return 'Message Equals';
    case 'contact_field':
      return 'Contact Field';

    default:
      return 'Unknown Condition Type';
  }
};

const getConditionValue = (condition) => {
  if (!condition || !condition.value) return 'No value set';
  
  const conditionType = config.value.condition_type;
  
  switch (conditionType) {
    case 'message_contains':
      return `Message contains "${condition.value}"`;
    case 'message_equals':
      return `Message equals "${condition.value}"`;
    case 'contact_field':
      if (!config.value.field_name) return 'Contact field (no field selected)';
      const fieldLabel = availableContactFields.value.find(f => f.value === config.value.field_name)?.label || config.value.field_name;
      const operator = config.value.field_operator;
      if (operator === 'is_empty') return `${fieldLabel} is empty`;
      if (operator === 'is_not_empty') return `${fieldLabel} is not empty`;
      return `${fieldLabel} ${operator} "${condition.value}"`;

    default:
      return `Value: "${condition.value}"`;
  }
};

const getValuePlaceholder = (type) => {
  switch (type) {
    case 'message_contains':
      return 'Enter text to search for...';
    case 'message_equals':
      return 'Enter exact message...';
    case 'contact_field':
      return 'Enter value to compare...';

    default:
      return 'Enter value...';
  }
};

onMounted(() => {
  // Node data loaded
})
</script>

<style scoped>
.conditional-node {
  position: relative;
}

.conditional-node:hover {
  z-index: 10;
}
</style> 