<script setup>
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
    modelValue: [String, Number],
    customValues: {
        type: Array,
        default: () => [],
    },
})

const textInput = ref(props.modelValue);
const textAreaRef = ref(null);
const placeholders = ref([]);
const customValues = ref(props.customValues);
const characterLimit = ref('1098');
const characterCount = ref('0');
const maxNu = ref(0);

const transformToPlaceholders = () => {
    placeholders.value = customValues.value.map((_, index) => `{{${index + 1}}}`);
};

// Run transformation on component mount
onMounted(() => {
    transformToPlaceholders();
    countCharacters();
});

const addVariable = () => {
    let limit = parseInt(characterLimit.value);
    let count = parseInt(textInput.value.length);

    if(count < limit){
        let nextIndex = placeholders.value.length + 1;
        const newPlaceholder = `{{${nextIndex}}}`;
        if (textInput.value.indexOf(newPlaceholder) === -1) {
            textInput.value += newPlaceholder;
            placeholders.value.push(newPlaceholder);
            customValues.value.push('');
        }

        countCharacters();
        updateValue();
    }
};

const updatePlaceholders = () => {
    const regex = /\{\{\d+\}\}/g;
    const matches = textInput.value.match(regex);
    if (matches) {
        // Create a set of unique numbers in the placeholders
        let allNumbersMatch = true;
        const maxNumber = matches.reduce((max, placeholder) => {
            const number = parseInt(placeholder.match(/\d+/)[0]);
            return number > max ? number : max;
        }, 0);
        
        for (let i = 1; i <= matches.length; i++) {
            const expectedPlaceholder = `{{${i}}}`;
            if (!placeholders.value.includes(expectedPlaceholder)) {
                allNumbersMatch = false;
                break;
            }
        }

        maxNu.value = matches.length;
        
        const uniquePlaceholders = [...new Set(matches)];
            placeholders.value = uniquePlaceholders;
            
            placeholders.value.forEach((placeholder, index) => {
                const oldNumber = parseInt(placeholder.match(/\d+/)[0]);
                
                const newNumber = index + 1;
                    const oldPlaceholder = `{{${oldNumber}}}`;
                    const newPlaceholder = `{{${newNumber}}}`;
                    textInput.value = textInput.value.replace(oldPlaceholder, newPlaceholder);

                    placeholders.value[index] = newPlaceholder; 
            });
    } else {
        placeholders.value = [];
        customValues.value = [];
    }

    updateCustomValues();
    countCharacters();
    updateValue();
};

const updateCustomValues = () => {
    const placeholdersLength = placeholders.value.length;
    const customValuesLength = customValues.value.length;

    if (placeholdersLength !== customValuesLength) {
        const difference = placeholdersLength - customValuesLength;
        if (difference > 0) {
            for (let i = 0; i < difference; i++) {
                customValues.value.push('');
            }
        } else if (difference < 0) {
            customValues.value.splice(placeholdersLength);
        }
    }
}

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
};

const emit = defineEmits(['update:modelValue', 'updateExamples']);
const updateValue = (event) => {
    emit('update:modelValue', textInput.value);
    emit('updateExamples', customValues.value);
};

const isCustomValuesIncomplete = computed(() => {
    // Check if any item in customValues is empty
    return customValues.value.some(value => !value);
});

const checkCustomValuesCompleteness = () => {
    // Check if any item in customValues is empty
    return customValues.value.some(value => !value);
};

const format = (type) => {
    const textarea = textAreaRef.value;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selectedText = textInput.value.slice(start, end);
    let newText = '';

    if(type == 'bold'){
        newText = textInput.value.slice(0, start) + '*' + selectedText + '*' + textInput.value.slice(end);
    } else if(type == 'italic'){
        newText = textInput.value.slice(0, start) + '_' + selectedText + '_' + textInput.value.slice(end);
    } else if(type == 'strike-through'){
        newText = textInput.value.slice(0, start) + '~' + selectedText + '~' + textInput.value.slice(end);
    } else if(type == 'monospace'){
        newText = textInput.value.slice(0, start) + '```' + selectedText + '```' + textInput.value.slice(end);
    }

    textInput.value = newText;
    countCharacters();
    updateValue();

    // Set selection to highlight the content between the asterisks
    setTimeout(() => {
        if(type == 'monospace'){
            textarea.setSelectionRange(start + 3, end + 3);
        } else {
            textarea.setSelectionRange(start + 1, end + 1);
        }
        textarea.focus();
    }, 0);
};
</script>
<template>
    <div class="normal-case">
        <div>
            <div :class="'sm:col-span-6'">
                <div class="mt-2">
                    <textarea 
                        ref="textAreaRef"
                        class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6 ring-gray-300"
                        v-model="textInput"
                        @input="updatePlaceholders"
                        :rows="'5'"
                        >
                    </textarea>
                </div>
            </div>
            <div class="flex items-center justify-between mt-2 mb-2">
                <span class="text-xs">{{ $t('Characters') }}: {{ characterCount + '/' + characterLimit }}</span>
                <div class="flex items-center space-x-3">
                    <button @click="format('bold')" class="hover:bg-slate-100 rounded p-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3h8c1.06 0 2.078.474 2.828 1.318C16.578 5.162 17 6.307 17 7.5c0 1.193-.421 2.338-1.172 3.182C15.078 11.526 14.061 12 13 12H5zm0 9h10.039a4.44 4.44 0 0 1 3.154 1.318A4.52 4.52 0 0 1 19.5 16.5a4.52 4.52 0 0 1-1.307 3.182A4.442 4.442 0 0 1 15.038 21H5z"/></svg>
                    </button>
                    <button @click="format('italic')" class="hover:bg-slate-100 rounded p-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M10 4.75a.75.75 0 0 1 .75-.75h8.5a.75.75 0 0 1 0 1.5h-3.514l-5.828 13h3.342a.75.75 0 0 1 0 1.5h-8.5a.75.75 0 0 1 0-1.5h3.514l5.828-13H20.75a.75.75 0 0 1-.75-.75Z"/></svg>
                    </button>
                    <button @click="format('strike-through')" class="hover:bg-slate-100 rounded p-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m16.533 12.5l.054.043c.93.75 1.538 1.77 1.538 3.066a4.13 4.13 0 0 1-1.479 3.177c-1.058.904-2.679 1.464-4.974 1.464c-2.35 0-4.252-.837-5.318-1.865a.75.75 0 1 1 1.042-1.08c.747.722 2.258 1.445 4.276 1.445c2.065 0 3.296-.504 3.999-1.105a2.63 2.63 0 0 0 .954-2.036c0-.764-.337-1.38-.979-1.898c-.649-.523-1.598-.931-2.76-1.211H3.75a.75.75 0 0 1 0-1.5h26.5a.75.75 0 0 1 0 1.5ZM12.36 5C9.37 5 8.105 6.613 8.105 7.848c0 .411.072.744.193 1.02a.75.75 0 0 1-1.373.603a3.988 3.988 0 0 1-.32-1.623c0-2.363 2.271-4.348 5.755-4.348c1.931 0 3.722.794 4.814 1.5a.75.75 0 1 1-.814 1.26c-.94-.607-2.448-1.26-4-1.26Z"/></svg>
                    </button>
                    <button @click="format('monospace')" class="hover:bg-slate-100 rounded p-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 6L10 18.5m-3.5-10L3 12l3.5 3.5m11-7L21 12l-3.5 3.5"/></svg>
                    </button>
                    <button type="button" @click="addVariable" class="hover:bg-slate-100 rounded p-1 text-sm">{{ $t('Add variable') }}</button>
                </div>
            </div>
        </div>
        <div v-if="placeholders.length > 0" class="bg-gray-100 p-2 rounded-md">
            <div class="mb-4">
                <h2 class="text-slate-600 mb-2">{{ $t('Samples for body content') }}</h2>
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