<script setup>
    import { computed, ref } from 'vue';

    const props = defineProps({
        modelValue: [String, Number],
        label: String,
        name: String,
        placeholder: String,
        type: String,
        min: Number,
        max: Number,
        className: String,
        labelClass: String,
        required: Boolean,
        error: String,
        disabled: Boolean,
        // زرّ إظهار/إخفاء لحقول كلمة المرور. اختياري لا تلقائي: الحقول التي
        // تحمل أسراراً في لوحة الإدارة لا نريد كشفها بنقرة عابرة.
        toggleVisibility: Boolean
    })

    const emit = defineEmits(['update:modelValue']);
    const updateValue = (event) => {
        emit('update:modelValue', event.target.value);
    };

    // نبدّل نوع الحقل لا نبني حقلاً ثانياً: الحقل نفسه يبقى فيحتفظ بالتركيز
    // وبموضع المؤشّر، ويظلّ مدير كلمات المرور في المتصفّح يتعرّف عليه.
    const revealed = ref(false)
    const isPassword = computed(() => props.type === 'password')
    const canToggle = computed(() => isPassword.value && props.toggleVisibility)
    const resolvedType = computed(() => (canToggle.value && revealed.value) ? 'text' : props.type)

    const colorInputRef = ref(null)
    const currentColor = computed(() => props.modelValue ?? '#22c55e')
    const openColorPicker = () => {
        if (colorInputRef.value && typeof colorInputRef.value.click === 'function') {
            colorInputRef.value.click()
        }
    }
</script>
<template>
    <div :class="className">
        <label for="name" class="block text-sm leading-6 text-gray-900" :class="labelClass">{{ label ?? name }}</label>
        <div v-if="type === 'color'" class="flex items-center gap-3">
            <button
                type="button"
                class="w-10 h-10 rounded-md border border-gray-300 shadow-sm cursor-pointer"
                :style="{ backgroundColor: currentColor }"
                :aria-label="`Choose ${label ?? name}`"
                :disabled="disabled"
                @click="openColorPicker"
            />
            <input
                ref="colorInputRef"
                type="color"
                class="sr-only"
                :value="currentColor"
                @input="updateValue"
                :disabled="disabled"
            />
            <input
                class="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
                :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
                type="text"
                :value="currentColor"
                placeholder="#RRGGBB"
                @input="(e) => emit('update:modelValue', e.target.value)"
                :disabled="disabled"
                :required="required"
            />
        </div>
        <div v-else class="relative">
            <input
                class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
                :class="[error ? 'ring-[#b91c1c]' : 'ring-gray-300', canToggle ? 'pe-10' : '']"
                :type="resolvedType"
                :value="props.modelValue"
                @input="updateValue"
                :step="'any'"
                :min="min"
                :max="max"
                :placeholder="placeholder"
                :disabled="disabled"
                :required="required"
            />
            <button
                v-if="canToggle"
                type="button"
                tabindex="-1"
                class="absolute inset-y-0 end-0 flex items-center px-3 text-gray-400 hover:text-gray-600 focus:outline-none"
                :aria-label="revealed ? $t('Hide password') : $t('Show password')"
                :aria-pressed="revealed"
                :title="revealed ? $t('Hide password') : $t('Show password')"
                @click="revealed = !revealed"
            >
                <svg v-if="!revealed" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.4 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19m-2.72 2.42A9.14 9.14 0 0 1 12 18C5.6 18 2 11 2 11a18.45 18.45 0 0 1 5.06-5.94"/>
                    <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                    <path d="m2 2 20 20"/>
                </svg>
            </button>
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>