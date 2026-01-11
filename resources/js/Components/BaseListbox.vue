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

    const props = defineProps({
      options: Array,
      modelValue: [String, Number, Array],
      placeholder: {
        type: String, 
        default: 'Select option'
      },
      multiple: Boolean
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
    <Listbox 
      :multiple="props.multiple"
      @update:modelValue="value => emit('update:modelValue', value)"
      :model-value="props.modelValue">
      <div class="relative mt-1">
        <ListboxButton
          class="relative w-full cursor-default rounded-lg bg-white py-2 px-5 pr-10 shadow-sm text-left ring-1 ring-inset ring-gray-300 focus:outline-none focus-visible:border-indigo-500 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-opacity-75 focus-visible:ring-offset-2 focus-visible:ring-offset-orange-300 sm:text-sm"
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
            class="z-10 absolute mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm"
          >
            <ListboxOption
              v-slot="{ active, selected }"
              v-for="option in props.options"
              :key="option.label"
              :value="option.value"
              as="template"
            >
              <li
                :class="[
                  active ? 'bg-amber-100 text-amber-900' : 'text-gray-900',
                  'relative cursor-default select-none py-2 pl-10 pr-4',
                ]"
              >
                <span
                  :class="[
                    selected ? 'font-medium' : 'font-normal',
                    'block truncate',
                  ]"
                  >{{ option.label }}</span
                >
                <span
                  v-if="selected"
                  class="absolute inset-y-0 left-0 flex items-center pl-3 text-amber-600"
                >
                  <CheckIcon class="h-5 w-5" aria-hidden="true" />
                </span>
              </li>
            </ListboxOption>
          </ListboxOptions>
        </transition>
      </div>
    </Listbox>
</template>