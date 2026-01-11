<script setup>
    import { ref, watch, computed } from 'vue';
    import 'vue-tel-input/vue-tel-input.css';
    import { validateBrazilianPhone, formatBrazilianPhone } from '@/lib/brazilPhoneValidation.js';

    const props = defineProps({
        modelValue: [String, Number],
        name: String,
        type: String,
        className: String,
        labelClass: String,
        required: Boolean,
        error: String,
        disabled: Boolean
    })

    const phone = ref(props.modelValue);
    const localError = ref('');

    const emit = defineEmits(['update:modelValue']);

    const updateValue = (event) => {
        const phoneValue = event.target.value;
        phone.value = phoneValue;
        
        // Validate Brazilian numbers
        const validation = validateBrazilianPhone(phoneValue);
        localError.value = validation.error;
        
        emit('update:modelValue', phoneValue);
    };

    // Watch for external error changes
    watch(() => props.error, (newError) => {
        if (newError) {
            localError.value = newError;
        }
    });

    // Watch for modelValue changes
    watch(() => props.modelValue, (newValue) => {
        phone.value = newValue;
    });

    // Computed error to show either local validation error or prop error
    const displayError = computed(() => {
        return localError.value || props.error;
    });


</script>

<template>
    <div :class="className">
        <label for="name" class="block text-sm leading-6 text-gray-900" :class="labelClass">{{ name }}</label>
        <div>
            <div style="padding:1px" class="outline-none focus-none focus-within-none block w-full rounded-md border-0 text-gray-900 shadow-sm ring-1 ring-gray-300 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6">
                <vue-tel-input 
                    :inputOptions="{
                        autocomplete: 'off'
                    }"
                    v-model="phone"
                    :autoFormat="true"
                    :mode="'international'"
                    :validCharactersOnly="true"
                    @input="updateValue"
                    :disabled="disabled"
                >
                </vue-tel-input>
            </div>
        </div>
        <div v-if="displayError" class="form-error text-[#b91c1c] text-xs">{{ displayError }}</div>
    </div>
</template>