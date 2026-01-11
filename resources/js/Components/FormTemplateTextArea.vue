<script setup>
    import { ref } from 'vue';

    const props = defineProps({
        modelValue: [String, Number],
        showLabel: Boolean,
        name: String,
        type: String,
        className: String,
        placeholder: String,
        textAreaRows: Number,
        error: String,
        variables: Array,
    })

    const emit = defineEmits(['update:modelValue']);
    const text = ref(props.modelValue);
    const textareaRef = ref(null);

    const format = (type) => {
        const selection = window.getSelection();
        if (selection && selection.toString()) {
            let formattedText = null;

            if (type === 'bold') {
                formattedText = `*${selection.toString()}*`;
            } else if (type === 'italic') {
                formattedText = `_${selection.toString()}_`;
            } else if (type === 'strikethrough') {
                formattedText = `~${selection.toString()}~`;
            } else if (type === 'monospace') {
                formattedText = `\`\`\`${selection.toString()}\`\`\``;
            }
            
            const cursorPos = textareaRef.value.selectionStart;
            text.value = text.value.replace(selection.toString(), formattedText);
            emit('update:modelValue', text.value);

            // Optionally, you can focus on the textarea after adding bold
            textareaRef.value.setSelectionRange(cursorPos, cursorPos + formattedText.length);
            textareaRef.value.focus();
        }
    };

    let variableCount = 0;
    const addVariable = () => {
        const textarea = textareaRef.value;
        if (!textareaRef.value) {
            return;
        }

        const cursorPos = textareaRef.value.selectionStart;
        const variableText = `{{${++variableCount}}} `;

        emit('update:modelValue',
            props.modelValue.substring(0, cursorPos) +
            variableText +
            props.modelValue.substring(cursorPos)
        );

        textareaRef.value.setSelectionRange(cursorPos + variableText.length, cursorPos + variableText.length);
        textareaRef.value.focus();
    };

    const updateValue = (event) => {
        const currentVariable = (props.modelValue.match(/{{\d+}}/g) || [])
        .find(variable => {
            console.log(variable)
            //const start = props.modelValue.indexOf(variable);
            //const end = start + variable.length;
            //return start <= cursorPos && cursorPos <= end;
        });
        
        //console.log(currentVariable);

        emit('update:modelValue', event.target.value);
    };
</script>
<template>
    <div :class="className">
        <label v-if="showLabel" for="name" class="block text-sm leading-6 text-gray-900">{{ name }}</label>
        <div class="mt-2">
            <textarea 
                class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
                :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
                :value="props.modelValue"
                @input="updateValue"
                :placeholder="placeholder"
                :rows="textAreaRows"
                ref="textareaRef"
                >
                {{ props.modelValue }}
            </textarea>
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
    <div class="flex items-center justify-between mt-2">
        <span class="text-xs">{{ $t('Characters') }}: {{ 0 }}/{{ 0 }}</span>
        <div class="flex items-center space-x-3">
            <button class="hover:bg-slate-100 rounded p-1" @click="format('bold')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M6 4.75c0-.69.56-1.25 1.25-1.25h5a4.752 4.752 0 0 1 3.888 7.479A5 5 0 0 1 14 20.5H7.25c-.69 0-1.25-.56-1.25-1.25ZM8.5 13v5H14a2.5 2.5 0 1 0 0-5Zm0-2.5h3.751A2.25 2.25 0 0 0 12.25 6H8.5Z"/></svg>
            </button>
            <button class="hover:bg-slate-100 rounded p-1" @click="format('italic')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M10 4.75a.75.75 0 0 1 .75-.75h8.5a.75.75 0 0 1 0 1.5h-3.514l-5.828 13h3.342a.75.75 0 0 1 0 1.5h-8.5a.75.75 0 0 1 0-1.5h3.514l5.828-13H10.75a.75.75 0 0 1-.75-.75Z"/></svg>
            </button>
            <button class="hover:bg-slate-100 rounded p-1" @click="format('strikethrough')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m16.533 12.5l.054.043c.93.75 1.538 1.77 1.538 3.066a4.13 4.13 0 0 1-1.479 3.177c-1.058.904-2.679 1.464-4.974 1.464c-2.35 0-4.252-.837-5.318-1.865a.75.75 0 1 1 1.042-1.08c.747.722 2.258 1.445 4.276 1.445c2.065 0 3.296-.504 3.999-1.105a2.63 2.63 0 0 0 .954-2.036c0-.764-.337-1.38-.979-1.898c-.649-.523-1.598-.931-2.76-1.211H3.75a.75.75 0 0 1 0-1.5h16.5a.75.75 0 0 1 0 1.5ZM12.36 5C9.37 5 8.105 6.613 8.105 7.848c0 .411.072.744.193 1.02a.75.75 0 0 1-1.373.603a3.988 3.988 0 0 1-.32-1.623c0-2.363 2.271-4.348 5.755-4.348c1.931 0 3.722.794 4.814 1.5a.75.75 0 1 1-.814 1.26c-.94-.607-2.448-1.26-4-1.26Z"/></svg>
            </button>
            <button class="hover:bg-slate-100 rounded p-1" @click="format('monospace')">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 6L10 18.5m-3.5-10L3 12l3.5 3.5m11-7L21 12l-3.5 3.5"/></svg>
            </button>
            <button @click="addVariable()" class="hover:bg-slate-100 rounded p-1 text-sm">{{ $t('Add variable') }}</button>
        </div>
    </div>
    <div class="mt-3 bg-slate-100 p-3">
        <h1>{{ $t('Samples') }}</h1>
        <p class="text-xs">{{ $t('In order for your template to be reviewed properly, please add an example for each variable') }}</p>
        <div class="mt-4">
            <div v-for="(variable, index) in props.variables" :key="index" class="flex items-center space-x-2 w-full">
                <span>{{ 1 }}</span>
                <input type="text" class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6">
            </div>
        </div>
    </div>
</template>