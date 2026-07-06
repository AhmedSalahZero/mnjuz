<template>
    <aside class="md:flex flex-col h-full bg-white hidden relative border-r" :class="menuIconsOnly ? 'w-20' : 'w-80'">
        <Menu ref="menuRef" :config="props.config" :user="props.user" :unreadMessages="unreadMessages" :organization="props.organization" :organizations="props.organizations"></Menu>
    </aside>
</template>
<script setup>
    import { Link, useForm } from "@inertiajs/vue3";
    import { defineProps, ref, computed } from "vue";
    import Menu from "./Menu.vue";
    
    const props = defineProps(['user', 'organization', 'organizations', 'config', 'unreadMessages']);
    const menuRef = ref(null);

    const menuIconsOnly = computed(() => {
        return menuRef.value?.menuIconsOnly ?? false;
    });
</script>
