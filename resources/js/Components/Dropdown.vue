<script setup>
    import { ref } from 'vue';
    import { Menu, MenuButton, MenuItems } from '@headlessui/vue';
    
    const props = defineProps({
        align: {
            type: String,
            default: "right"
        }
    })
</script>
<template>
    <Menu as="div" class="relative inline-block text-left">
        <div>
            <MenuButton as="template">
                <slot />
            </MenuButton>
        </div>

        <transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="transform scale-95 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-95 opacity-0"
        >
            <MenuItems 
                :class="{
                    'right-0 origin-top-right' : props.align === 'right',
                    'left-0 origin-top-left' : props.align === 'left',
                    'top-[-135px] left-0 origin-top-left' : props.align === 'top-left',
                }"
                class="z-10 absolute mt-2 w-32 divide-y divide-gray-100 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                
                <slot name="items" />
            </MenuItems>
        </transition>
    </Menu>
</template>