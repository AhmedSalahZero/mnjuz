<template>
  <div class="update-contact-node">
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
              <path d="m16 3 4 4-4 4"/>
              <path d="M20 7H4"/>
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
          <span class="font-medium">Update field:</span> {{ getTargetFieldName(config.target_field) }}
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
          <h3 class="text-lg font-semibold text-gray-900">Edit Update Contact Action</h3>
        </div>
        
        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Configuration Fields -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Configuration</label>
              <div class="space-y-3">
                <!-- Update Contact -->
                <div>
                  <div class="space-y-4">
                    <label class="block text-sm font-medium text-gray-700">Save User's Message to Contact Field</label>
                    
                    <!-- Field Selection -->
                    <div class="space-y-3">
                      <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Contact Field</label>
                        <FormSelect
                          v-model="editForm.config.target_field"
                          :options="availableContactFields"
                          placeholder="Choose which field to update"
                        />
                      </div>
                    </div>
                    
                    <!-- Invalid Email Message (only show when email field is selected) -->
                    <div v-if="editForm.config.target_field === 'email'" class="space-y-3">
                      <div>
                        <FormTextArea
                          v-model="editForm.config.invalid_email_message"
                          name="Invalid Email Message"
                          rows="3"
                          placeholder="Please provide a valid email address."
                        />
                        <p class="text-xs text-gray-500 mt-1">
                          Message to send when user provides an invalid email format. Leave empty to use default message.
                        </p>
                      </div>
                    </div>
                    
                    <!-- What happens explanation - below dropdown -->
                    <div class="bg-gray-50 border border-blue-200 rounded-lg p-3">
                      <div class="text-sm text-blue-800">
                        <p class="text-xs text-gray-600 mb-1">
                          <strong>What happens:</strong> When a user sends a message, their message content will be automatically saved to the selected field.
                        </p>
                        <p class="text-xs text-gray-500">
                          Example: If user types "john@example.com" and you select "Email", their email will be updated to "john@example.com"
                        </p>
                        <p v-if="editForm.config.target_field === 'email'" class="text-xs text-orange-600 mt-2">
                          <strong>Email validation:</strong> If the user provides an invalid email format, they will receive the configured error message.
                        </p>
                      </div>
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
import { ref, reactive, computed, inject } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import FormSelect from '../../../../../../../../resources/js/Components/FormSelect.vue'
import FormCheckbox from '../../../../../../../../resources/js/Components/FormCheckbox.vue'
import FormTextArea from '../../../../../../../../resources/js/Components/FormTextArea.vue'

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

// Computed property for available contact fields (same as Action-node.vue)
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
  
  if (actionConfig.target_field) {
    const field = availableContactFields.value.find(f => f.value === actionConfig.target_field)
    return field ? `Update Contact: ${field.label}` : `Update Contact: ${actionConfig.target_field}`
  }
  return 'Update Contact'
})

const editForm = reactive({
  config: {},
  is_active: true
})

const getTargetFieldName = (fieldValue) => {
  if (!fieldValue) return 'No field selected'
  const field = availableContactFields.value.find(f => f.value === fieldValue)
  return field ? field.label : fieldValue
}

const toggleEdit = () => {
  // Initialize the form with proper structure (same as Action-node.vue)
  const currentConfig = config.value || {}
  
  editForm.config = {
    ...currentConfig,
    target_field: currentConfig.target_field || '',
    invalid_email_message: currentConfig.invalid_email_message || 'Please provide a valid email address.',
    // Keep existing fields structure for backward compatibility
    fields: {
      first_name: currentConfig.fields?.first_name || '',
      last_name: currentConfig.fields?.last_name || '',
      email: currentConfig.fields?.email || '',
      phone: currentConfig.fields?.phone || '',
      address: {
        street: currentConfig.fields?.address?.street || '',
        city: currentConfig.fields?.address?.city || '',
        state: currentConfig.fields?.address?.state || '',
        zip: currentConfig.fields?.address?.zip || '',
        country: currentConfig.fields?.address?.country || ''
      },
      metadata: currentConfig.fields?.metadata || {}
    }
  }
  
  editForm.is_active = isActive.value
  showEditModal.value = true
}

const closeEditModal = () => {
  showEditModal.value = false
}

const saveAction = () => {
  // Update the node data directly using useNode (same as Action-node.vue)
  node.node.data = {
    ...node.node.data,
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
</script>

<style scoped>
.update-contact-node {
  position: relative;
}
</style> 