<template>
  <div class="ai-response-node">
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
              <path d="M12 2L2 7l10 5 10-5-10-5z"/>
              <path d="M2 17l10 5 10-5"/>
              <path d="M2 12l10 5 10-5"/>
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
        <span class="font-medium">Prompt:</span> {{ config.prompt ? (config.prompt.substring(0, 30) + (config.prompt.length > 30 ? '...' : '')) : 'No prompt set' }}
        <div v-if="config.proceed_condition === 'confidence_threshold'" class="text-xs text-gray-500 mt-1">
          Confidence: {{ (config.confidence_threshold || 0.8) * 100 }}%
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
    <Handle
      type="source"
      :position="Position.Bottom"
      class="w-3 h-3 !bg-teal-500"
    />
    <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg max-w-md w-full mx-4 max-h-[80vh] overflow-y-auto">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">Edit AI Response Action</h3>
        </div>
        <form @submit.prevent="saveAction" class="p-6">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Configuration</label>
              <div class="space-y-3">
                <div>
                  <FormTextArea
                    v-model="editForm.config.prompt"
                    name="AI Prompt"
                    rows="4"
                    placeholder="Enter the prompt for AI response... (optional)"
                  />
                  <p class="text-xs text-gray-500 mt-1">
                    Leave empty to use default AI behavior, or add specific instructions for the AI.
                  </p>
                </div>
                <div>
                  <FormSelect
                    v-model="editForm.config.proceed_condition"
                    name="Proceed Condition"
                    :options="[
                      { value: 'always', label: 'Always Proceed' },
                      { value: 'confidence_threshold', label: 'Wait for High Confidence' }
                    ]"
                    required
                  />
                </div>
                <div v-if="editForm.config.proceed_condition === 'confidence_threshold'" class="space-y-3">
                  <div>
                    <FormInput
                      v-model="editForm.config.confidence_threshold"
                      name="Confidence Threshold"
                      type="number"
                      min="0.1"
                      max="1"
                      step="0.1"
                      placeholder="0.8"
                      required
                    />
                    <p class="text-xs text-gray-500 mt-1">
                      Set between 0.1 (10%) and 1.0 (100%). Higher values require more confident AI responses.
                    </p>
                  </div>
                  <div>
                    <FormTextArea
                      v-model="editForm.config.low_confidence_message"
                      name="Low Confidence Message"
                      rows="2"
                      placeholder="I'm not confident about my response. Could you rephrase your question?"
                    />
                    <p class="text-xs text-gray-500 mt-1">
                      Message sent when AI confidence is below the threshold. Leave empty for default message.
                    </p>
                  </div>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                  <p class="text-sm font-medium text-blue-800 mb-3">
                    How AI Response Flow Works:
                  </p>
                  <div class="space-y-3 text-sm text-blue-700">
                    <div>
                      <p class="font-medium">Always Proceed:</p>
                      <p class="text-xs">AI sends response and flow immediately continues to next node.</p>
                    </div>
                    <div v-if="editForm.config.proceed_condition === 'confidence_threshold'">
                      <p class="font-medium">Wait for High Confidence:</p>
                      <div class="text-xs space-y-2">
                        <p>• AI generates response and calculates confidence (0-100%)</p>
                        <p>• If confidence ≥ {{ (editForm.config.confidence_threshold || 0.8) * 100 }}%: Send response and continue</p>
                        <p>• If confidence < {{ (editForm.config.confidence_threshold || 0.8) * 100 }}%: Send fallback message and wait for user input</p>
                        <p>• AI keeps trying until it reaches the confidence threshold</p>
                      </div>
                    </div>
                    <div v-else>
                      <p class="font-medium">Wait for High Confidence:</p>
                      <p class="text-xs">AI only proceeds when response confidence meets your threshold. Keeps conversing until confident.</p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
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
  if (actionConfig.proceed_condition === 'confidence_threshold') {
    const threshold = (actionConfig.confidence_threshold || 0.8) * 100
    return `AI Response (${threshold}% confidence)`
  }
  return actionConfig.prompt ? `AI Response: ${actionConfig.prompt.substring(0, 30)}${actionConfig.prompt.length > 30 ? '...' : ''}` : 'AI Response'
})
const editForm = reactive({
  config: {},
  is_active: true
})
const toggleEdit = () => {
  const currentConfig = config.value || {}
  editForm.config = {
    prompt: currentConfig.prompt || '',
    proceed_condition: currentConfig.proceed_condition || 'always',
    confidence_threshold: currentConfig.confidence_threshold || 0.8,
    low_confidence_message: currentConfig.low_confidence_message || ''
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
    actionType: 'ai_response',
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
.ai-response-node {
  position: relative;
}
.ai-response-node:hover {
  z-index: 10;
}
</style> 