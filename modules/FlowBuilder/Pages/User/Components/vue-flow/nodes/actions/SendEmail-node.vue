<template>
  <div class="send-email-node">
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
              <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
              <polyline points="22,6 12,13 2,6"/>
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
          <span class="font-medium">Subject:</span> {{ config.subject }}
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
          <h3 class="text-lg font-semibold text-gray-900">Edit Send Email Action</h3>
        </div>
        
        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <!-- Configuration Fields -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Configuration</label>
              <div class="space-y-3">
                <!-- Send Email -->
                <div class="space-y-4">
                  <!-- Email Content -->
                  <div class="space-y-3">
                    <h4 class="text-xs font-medium text-gray-600 uppercase tracking-wide">Email Content</h4>
                    <div>
                      <FormInput
                        v-model="editForm.config.subject"
                        label="Subject"
                        type="text"
                        required
                      />
                    </div>
                    <div>
                      <FormTextArea
                        v-model="editForm.config.body"
                        name="Body"
                        rows="4"
                        required
                      />
                    </div>
                  </div>

                  <!-- SMTP Settings -->
                  <div class="space-y-3">
                    <h4 class="text-xs font-medium text-gray-600 uppercase tracking-wide">SMTP Settings</h4>
                    <div class="grid grid-cols-2 gap-3">
                      <div>
                        <FormInput
                          v-model="editForm.config.smtp_host"
                          label="SMTP Host"
                          type="text"
                          placeholder="e.g., smtp.gmail.com"
                          required
                        />
                      </div>
                      <div>
                        <FormInput
                          v-model="editForm.config.smtp_port"
                          label="SMTP Port"
                          type="number"
                          placeholder="587"
                          required
                        />
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                      <div>
                        <FormInput
                          v-model="editForm.config.smtp_username"
                          label="Username"
                          type="text"
                          placeholder="your-email@gmail.com"
                          required
                        />
                      </div>
                      <div>
                        <FormInput
                          v-model="editForm.config.smtp_password"
                          label="Password"
                          type="password"
                          placeholder="App password or email password"
                          required
                        />
                      </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                      <div>
                        <FormSelect
                          v-model="editForm.config.smtp_encryption"
                          label="Encryption"
                          name="Encryption"
                          :options="[
                            { value: 'tls', label: 'TLS' },
                            { value: 'ssl', label: 'SSL' },
                            { value: 'none', label: 'None' }
                          ]"
                          required
                        />
                      </div>
                      <div>
                        <FormInput
                          v-model="editForm.config.from_name"
                          label="From Name"
                          type="text"
                          placeholder="Your Company Name"
                          required
                        />
                      </div>
                    </div>
                    <div>
                      <FormInput
                        v-model="editForm.config.from_email"
                        label="From Email"
                        type="email"
                        placeholder="noreply@yourdomain.com"
                        required
                      />
                      <p class="text-xs text-gray-500 mt-1">
                        This must be a valid email address that you're authorized to send from through your SMTP provider.
                      </p>
                    </div>
                  </div>

                  <!-- What happens explanation -->
                  <div class="bg-gray-50 border border-blue-200 rounded-lg p-3">
                    <p class="text-xs text-gray-600 mb-1">
                      <strong>What happens:</strong> An email will be sent to the contact using the configured SMTP settings.
                    </p>
                    <p class="text-xs text-gray-500">
                      The email will be sent from the configured SMTP account to the contact's email address.
                    </p>
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
import { ref, reactive, computed, onMounted } from 'vue'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'
import FormSelect from '../../../../../../../../resources/js/Components/FormSelect.vue'
import FormCheckbox from '../../../../../../../../resources/js/Components/FormCheckbox.vue'
import FormInput from '../../../../../../../../resources/js/Components/FormInput.vue'
import FormTextArea from '../../../../../../../../resources/js/Components/FormTextArea.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const { removeNodes } = useVueFlow()
const node = useNode()

const config = computed(() => props.data?.config || {})
const isActive = computed(() => props.data?.is_active !== false)

const showEditModal = ref(false)

const actionName = computed(() => {
  const actionConfig = config.value
  return actionConfig.subject ? `Send Email: ${actionConfig.subject}` : 'Send Email'
})

const editForm = reactive({
  config: {},
  is_active: true
})

const toggleEdit = () => {
  // Initialize edit form with current values
  const currentConfig = config.value || {}
  editForm.config = {
    subject: currentConfig.subject || '',
    body: currentConfig.body || '',
    smtp_host: currentConfig.smtp_host || '',
    smtp_port: currentConfig.smtp_port || 587,
    smtp_username: currentConfig.smtp_username || '',
    smtp_password: currentConfig.smtp_password || '',
    smtp_encryption: currentConfig.smtp_encryption || 'tls',
    from_name: currentConfig.from_name || '',
    from_email: currentConfig.from_email || ''
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
    actionType: 'send_email',
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
.send-email-node {
  position: relative;
}

.send-email-node:hover {
  z-index: 10;
}
</style> 