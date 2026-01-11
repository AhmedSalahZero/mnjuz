<script setup>
import { ref,watch } from 'vue';

const props = defineProps({
  modelValue: {
    type: Boolean,
    required: true
  },
  organizationId: {
    type: String,
    required: true
  },
  apiEndpoint: {
    type: String,
    default: '/admin/organizations'
  }
});

const emit = defineEmits(['update:modelValue']);

const isLoading = ref(false);
const localValue = ref(props.modelValue);

const toggle = async () => {
  const newValue = !localValue.value;
  isLoading.value = true;

  try {
    const response = await fetch(`${props.apiEndpoint}/${props.organizationId}/toggle`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      },
      body: JSON.stringify({
        is_banned: newValue
      })
    });

    if (!response.ok) {
      throw new Error('Failed to update');
    }

    const data = await response.json();
    
    localValue.value = newValue;
    emit('update:modelValue', newValue);
    
    console.log('Toggle updated successfully', data);
  } catch (error) {
    console.error('Error updating toggle:', error);
    // Revert to previous state on error
    localValue.value = !newValue;
  } finally {
    isLoading.value = false;
  }
};

watch(() => props.modelValue, (newValue) => {
  localValue.value = newValue;
});
</script>

<template>
  <button
    @click="toggle"
    :disabled="isLoading"
    class="relative inline-flex h-6 w-12 items-center rounded-full transition-colors duration-300 focus:outline-none focus:ring-2 focus:ring-offset-2"
    :class="[
      localValue ? 'bg-red-500 focus:ring-red-500' : 'bg-green-500 focus:ring-green-500',
      isLoading ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'
    ]"
  >
    <!-- Toggle Circle -->
    <span
      class="inline-block h-4 w-4 transform rounded-full bg-white shadow-lg transition-transform duration-300"
      :class="localValue ? 'translate-x-7' : 'translate-x-1'"
    >
      <!-- Loading Spinner -->
      <svg
        v-if="isLoading"
        class="animate-spin h-full w-full text-gray-400 p-1"
        xmlns="http://www.w3.org/2000/svg"
        fill="none"
        viewBox="0 0 24 24"
      >
        <circle
          class="opacity-25"
          cx="12"
          cy="12"
          r="10"
          stroke="currentColor"
          stroke-width="4"
        ></circle>
        <path
          class="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
        ></path>
      </svg>
    </span>

    <!-- Labels -->
    <span
      class="absolute text-xs font-medium text-white pointer-events-none"
      :class="localValue ? 'left-2' : 'right-2'"
    >
      {{ localValue ? 'Yes' : 'No' }}
    </span>
  </button>
</template>
