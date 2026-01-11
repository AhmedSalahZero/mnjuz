<script setup>
import { ref, watch, computed, onMounted } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
    customValues: {
        type: Array,
        default: () => [],
    },
})

const textInput = ref(props.modelValue);
const placeholders = ref([]);
const originalNumbers = ref({});
const customValues = ref(props.customValues);
const characterLimit = ref('60');
const characterCount = ref('0');
const variableButtonDisabled = ref(false);

const transformToPlaceholders = () => {
    if (!Array.isArray(customValues.value)) {
        console.error("customValues is not an array");
        return;
    }

    if (customValues.value.some(value => value === '')) {
        console.warn("Some custom values are empty.");
        return;
    }
    
    placeholders.value = customValues.value.map((_, index) => `{{${index + 1}}}`);
};

// Run transformation on component mount
onMounted(() => {
    transformToPlaceholders();
    countCharacters();
});

const addVariable = () => {
    let limit = parseInt(characterLimit.value);
    let count = parseInt(textInput.value?.length);

    if (count < limit && placeholders.value.length === 0) {
        const newPlaceholder = `{{1}}`;
        textInput.value += newPlaceholder;
        placeholders.value.push(newPlaceholder);
        originalNumbers.value[newPlaceholder] = 1;
        customValues.value.push('');
        variableButtonDisabled.value = true;
        countCharacters();
    }
};

const updatePlaceholders = () => {
    const regex = /\{\{\d+\}\}/g;
    const matches = textInput.value.match(regex);

    // If there are matches and more than one variable exists, remove additional variables
    if (matches && matches.length > 1) {
        textInput.value = matches[0]; // Keep only the first variable
    }

    // Update placeholders and original numbers
    if (matches) {
        placeholders.value = [matches[0]]; // Ensure only the first match is kept
        originalNumbers.value = { [matches[0]]: 1 };
        //customValues.value = [''];
        variableButtonDisabled.value = true;
    } else {
        placeholders.value = [];
        originalNumbers.value = {};
        customValues.value = [];
        variableButtonDisabled.value = false;
    }

    countCharacters();
    updateValue();
};


const countCharacters = (type) => {
    if (!textInput.value) {
        characterCount.value = 0;
        return;
    }
    
    let limit = parseInt(characterLimit.value);
    let count = parseInt(textInput.value.length);

    if (count <= limit) {
        characterCount.value = count;
    } else {
        textInput.value = textInput.value.slice(0, limit);
        characterCount.value = limit;
    }

    updateValue();
};

const emit = defineEmits(['update:modelValue', 'updateExamples']);
const updateValue = (event) => {
    emit('update:modelValue', textInput.value);
    emit('updateExamples', customValues.value);
};

// Watch for changes in textInput to handle character limit
watch(textInput, (newVal) => {
    const limit = parseInt(characterLimit.value);
    if (newVal.length > limit) {
        textInput.value = newVal.slice(0, limit);
    }
    countCharacters();
    updatePlaceholders();
});

const isCustomValuesIncomplete = computed(() => {
    // Check if any item in customValues is empty
    return customValues.value.some(value => !value);
});

const checkCustomValuesCompleteness = () => {
    // Check if any item in customValues is empty
    return customValues.value.some(value => !value);
};
</script>
<template>
    <div class="normal-case">
        <div>
            <div :class="'sm:col-span-6'">
                <div class="mt-2">
                    <input
                        type="text" 
                        class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6 ring-gray-300"
                        v-model="textInput"
                        @input="updatePlaceholders"
                        :rows="'5'"
                        >
                    </input>
                </div>
            </div>
            <div class="flex items-center justify-between mt-2 mb-2">
                <span class="text-xs">{{ $t('Characters') }}: {{ characterCount + '/' + characterLimit }}</span>
                <div class="flex items-center space-x-3">
                    <button type="button" @click="addVariable" class="hover:bg-slate-100 rounded p-1 text-sm cursor-pointer" :disabled="variableButtonDisabled">{{ $t('Add variable') }} ({{ $t('Max: 1') }})</button>
                </div>
            </div>
        </div>
        <div v-if="placeholders.length > 0" class="bg-gray-100 p-2 rounded-md">
            <div class="mb-4">
                <h2 class="text-slate-600 mb-2">{{ $t('Samples for header content') }}</h2>
                <p class="text-xs">{{ $t('Please add an example for each variable in your body text. Do not use real customer information. Cloud API hosted by Meta reviews templates and variable parameters to protect the security and integrity of their services.') }}</p>
            </div>
            <div v-for="(placeholder, index) in placeholders" :key="index" class="flex items-center mb-1">
                <div class="w-[10%] text-sm">{{ placeholder }}</div>
                <div class="w-[90%]">
                    <input type="text" v-model="customValues[index]" :placeholder="'Enter content for ' + placeholder" class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6 ring-gray-300" required>
                </div>
            </div>
            <div v-if="isCustomValuesIncomplete" class="p-2 bg-red-100 rounded-md flex items-center gap-x-2 mt-4">
                <svg class="text-red-800" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M13 9a1 1 0 1 1-2 0a1 1 0 0 1 2 0m-1 2.75a.75.75 0 0 1 .75.75v5a.75.75 0 1 1-1.5 0v-5a.75.75 0 0 1 .75-.75"/><path fill="currentColor" fill-rule="evenodd" d="M14.27 3.993a2.749 2.749 0 0 0-4.54 0l-.432.632a75.95 75.95 0 0 0-6.944 12.563l-.09.208a2.511 2.511 0 0 0 2.024 3.497a69.43 69.43 0 0 0 15.424 0a2.511 2.511 0 0 0 2.024-3.497l-.09-.208a75.951 75.951 0 0 0-6.944-12.563zm-3.302.846a1.25 1.25 0 0 1 2.064 0l.432.632a74.444 74.444 0 0 1 6.806 12.315l.09.208a1.011 1.011 0 0 1-.814 1.408c-5.015.56-10.077.56-15.092 0a1.011 1.011 0 0 1-.815-1.408l.09-.208a74.45 74.45 0 0 1 6.807-12.315z" clip-rule="evenodd"/></svg>
                <p class="text-sm">{{ $t('Add sample text') }}</p>
            </div>
        </div>
    </div>
</template>