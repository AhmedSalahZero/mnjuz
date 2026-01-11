<script setup>
    import { computed, ref } from 'vue';
    import {
      Listbox,
      ListboxLabel,
      ListboxButton,
      ListboxOptions,
      ListboxOption,
    } from '@headlessui/vue'
    import { CheckIcon, ChevronUpDownIcon } from '@heroicons/vue/20/solid';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps({
      options: Array,
      modelValue: [String, Number, Array],
      name: String,
      className: String,
      optionClassName: String,
      placeholder: {
        type: String, 
        default: 'Select option'
      },
      multiple: Boolean,
      required: Boolean,
      error: String,
      disabled: Boolean,
      dropdownUp: {
        type: Boolean,
        default: false
      }
    })

    const emit = defineEmits(['update:modelValue'])

    const label = computed(() => {
      return props.options.filter(option => {
        if(Array.isArray(props.modelValue)){
          return props.modelValue.includes(option.value);
        }

        return props.modelValue === option.value;
      }).map(option => option.label).join(', ');
    })
</script>
<template>
    <div :class="className">
        <label for="name" class="block text-sm leading-6 text-gray-900">{{ name }}</label>
        <div v-if="disabled == true">
            <input
            class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
            :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
            :type="'text'"
            :value="label"
            :step="'any'"
            :placeholder="placeholder"
            :disabled="true"
            />
        </div>
        <div v-else>
            <Listbox 
                :multiple="props.multiple"
                @update:modelValue="value => emit('update:modelValue', value)"
                :model-value="props.modelValue">
                <div class="relative">
                    <ListboxButton
                    class="relative w-full cursor-default rounded-lg bg-white py-2 px-5 pr-10 shadow-sm text-left ring-1 ring-inset focus:outline-none focus-visible:border-indigo-500 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75 focus-visible:ring-offset-2 focus-visible:ring-offset-orange-300 sm:text-sm"
                    :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"
                    >
                    <span class="block truncate" v-if="label">{{ label }}</span>
                    <span v-else class="text-gray-500">{{ props.placeholder }}</span>
                    <span
                        class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2"
                    >
                        <ChevronUpDownIcon
                        class="h-5 w-5 text-gray-400"
                        aria-hidden="true"
                        />
                    </span>
                    </ListboxButton>

                    <transition
                    leave-active-class="transition duration-100 ease-in"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                    >
                    <ListboxOptions
                        :class="[
                            'z-10 absolute max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm',
                            props.dropdownUp ? 'bottom-full mb-1' : 'mt-1',
                            optionClassName
                        ]"
                    >
                        <ListboxOption
                        v-slot="{ active, selected }"
                        v-for="option in props.options"
                        :key="option.label"
                        :value="option.value"
                        as="template"
                        >
                        <li :class="[active ? 'bg-amber-100 text-amber-900' : 'text-gray-900','relative cursor-default select-none py-2 pl-10 pr-4']">
                            <span :class="[selected ? 'font-medium' : 'font-normal', 'block truncate']">{{ option.label }}</span>
                            <span v-if="selected" class="absolute inset-y-0 left-0 flex items-center pl-3 text-amber-600">
                                <CheckIcon class="h-5 w-5" aria-hidden="true" />
                            </span>
                        </li>
                        </ListboxOption>
                    </ListboxOptions>
                    </transition>
                </div>
            </Listbox>
        </div>
        <div v-if="error" class="form-error text-[#b91c1c] text-xs">{{ error }}</div>
    </div>
</template>