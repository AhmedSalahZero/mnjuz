<script setup>
import { computed, ref } from 'vue'
import {
    Combobox,
    ComboboxButton,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
} from '@headlessui/vue'
import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid'
// الـsprite الذي تأتي منه الأعلام — حزمة مثبّتة أصلاً، فلا أصول جديدة.
import 'vue-tel-input/vue-tel-input.css'

const props = defineProps({
    modelValue: [String, Number],
    // [{ value, label, flag }]
    options: { type: Array, default: () => [] },
    name: String,
    placeholder: { type: String, default: 'Select option' },
    searchPlaceholder: { type: String, default: 'Search' },
    emptyText: { type: String, default: 'No results' },
    error: String,
    className: String,
})

const emit = defineEmits(['update:modelValue'])

const query = ref('')
const buttonRef = ref(null)

/**
 * فتح القائمة كاملة بمجرّد التركيز على الحقل.
 *
 * Combobox لا يفتح إلا عند الكتابة أو الضغط على السهم، فيبدو الحقل نصّياً
 * عادياً ولا يعرف المستخدم أن خلفه قائمة. نفتحها بالنيابة عنه، ونظلّل النص
 * الظاهر ليستبدله مباشرةً بالكتابة.
 */
const openOnFocus = (event, isOpen) => {
    event.target.select()
    if (!isOpen) {
        buttonRef.value?.$el?.click()
    }
}

const selected = computed({
    get: () => props.options.find(o => String(o.value) === String(props.modelValue)) ?? null,
    set: (option) => {
        // نُفرّغ نص البحث بعد الاختيار وإلا بقيت القائمة مُرشَّحة عند فتحها ثانيةً.
        query.value = ''
        emit('update:modelValue', option ? option.value : null)
    },
})

/**
 * البحث يتجاهل حالة الأحرف والمسافات، ويرتّب ما يبدأ بالنص قبل ما يحتويه —
 * فكتابة "sa" تُظهر Saudi Arabia قبل Western Sahara.
 */
const filtered = computed(() => {
    const q = query.value.trim().toLowerCase()
    if (!q) return props.options

    const starts = []
    const contains = []
    for (const option of props.options) {
        const label = String(option.label).toLowerCase()
        if (label.startsWith(q)) starts.push(option)
        else if (label.includes(q)) contains.push(option)
    }
    return [...starts, ...contains]
})
</script>

<template>
    <div :class="className">
        <label v-if="name" class="block text-sm leading-6 text-gray-900">{{ name }}</label>

        <Combobox v-model="selected" by="value" v-slot="{ open }">
            <div class="relative">
                <div class="relative w-full">
                    <!-- علم الدولة المختارة داخل الحقل -->
                    <span v-if="selected?.flag"
                        class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                        <span class="vti__flag" :class="selected.flag"></span>
                    </span>

                    <ComboboxInput
                        class="block w-full rounded-md border-0 py-1.5 pe-10 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
                        :class="[
                            error ? 'ring-[#b91c1c]' : 'ring-gray-300',
                            selected?.flag ? 'ps-10' : 'ps-4',
                        ]"
                        :displayValue="(option) => option?.label ?? ''"
                        :placeholder="placeholder"
                        autocomplete="off"
                        @focus="openOnFocus($event, open)"
                        @change="query = $event.target.value" />

                    <ComboboxButton ref="buttonRef" class="absolute inset-y-0 end-0 flex items-center pe-2">
                        <ChevronUpDownIcon class="h-5 w-5 text-gray-400" aria-hidden="true" />
                    </ComboboxButton>
                </div>

                <ComboboxOptions
                    class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm">
                    <div v-if="filtered.length === 0" class="relative cursor-default select-none px-4 py-2 text-gray-500">
                        {{ emptyText }}
                    </div>

                    <ComboboxOption v-for="option in filtered" :key="option.value" :value="option" v-slot="{ active, selected: isSelected }"
                        as="template">
                        <li class="relative cursor-default select-none py-2 ps-10 pe-4"
                            :class="active ? 'bg-primary text-white' : 'text-gray-900'">
                            <span class="absolute inset-y-0 start-0 flex items-center ps-3">
                                <CheckIcon v-if="isSelected" class="h-4 w-4" aria-hidden="true" />
                                <span v-else-if="option.flag" class="vti__flag" :class="option.flag"></span>
                            </span>
                            <span class="block truncate" :class="isSelected ? 'font-medium' : 'font-normal'">
                                {{ option.label }}
                            </span>
                        </li>
                    </ComboboxOption>
                </ComboboxOptions>
            </div>
        </Combobox>

        <div v-if="error" class="form-error text-[#b91c1c] text-xs mt-1">{{ error }}</div>
    </div>
</template>
