<template>
    <div v-if="props.displayTopBar === true" class="sticky top-0 z-10 w-full bg-white border-b px-4 py-4 flex items-center justify-between md:hidden">
        <div>
            <span @click="isSidebarOpen = true">
                <svg xmlns="http://www.w3.org/2000/svg" width="2em" height="2em" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 17h8m-8-5h14M5 7h8"/></svg>
            </span>
        </div>
        <h3 class="text-xl font-semibold">{{ props.title }}</h3>
        <div>
            <Link v-if="props.displayCreateBtn" :href="$page.url + '/create'">
                <svg xmlns="http://www.w3.org/2000/svg" width="2em" height="2em" viewBox="0 0 20 20"><path fill="currentColor" d="M10 6.5a4.5 4.5 0 1 1-9 0a4.5 4.5 0 0 1 9 0m-4-2a.5.5 0 0 0-1 0V6H3.5a.5.5 0 0 0 0 1H5v1.5a.5.5 0 0 0 1 0V7h1.5a.5.5 0 0 0 0-1H6zm6 .5h-1.207a5.466 5.466 0 0 0-.393-1H12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1.257c.307.253.642.474 1 .657v.6a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1M6 16a2 2 0 0 1-1.732-1H12a3 3 0 0 0 3-3V6.268A2 2 0 0 1 16 8v4a4 4 0 0 1-4 4zm2 2a2 2 0 0 1-1.732-1H12a5 5 0 0 0 5-5V8.268A2 2 0 0 1 18 10v2a6 6 0 0 1-6 6z"/></svg>
            </Link>
            <span v-else @click="openModal">
                <svg xmlns="http://www.w3.org/2000/svg" width="2em" height="2em" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10s10-4.477 10-10S17.523 2 12 2"/><path d="M4.271 18.346S6.5 15.5 12 15.5s7.73 2.846 7.73 2.846M12 12a3 3 0 1 0 0-6a3 3 0 0 0 0 6"/></g></svg>
            </span>
        </div>
    </div>
    <aside
        class="transform top-0 left-0 w-full bg-white fixed h-full overflow-auto ease-in-out transition-all duration-300 z-30"
        :class="isSidebarOpen ? 'translate-x-0' : '-translate-x-full'">
        <aside class="flex flex-col h-full w-full bg-white">
            <Menu :isSidebarOpen="isSidebarOpen" :config="props.config" :user="props.user" :organization="props.organization" :organizations="props.organizations" @closeSidebar="closeSidebar()"></Menu>
        </aside>
    </aside>

    <ProfileModal :user="props.user" :organization="props.organization" :isOpen="isProfileOpen" role="user" :languages="languages" @close="closeModal()"/>
</template>
<script setup>
    import axios from "axios"; 
    import { Link, useForm, usePage } from "@inertiajs/vue3";
    import { defineProps, ref, computed, onMounted } from "vue";
    import Menu from "./Menu.vue";
    import ProfileModal from '@/Components/ProfileModal.vue';

    const props = defineProps({
        title: {
            type: String,
        },
        displayTopBar: {
            type: Boolean,
            default: true,
        },
        displayCreateBtn: {
            type: Boolean,
        },
        user: {
            type: Object,
            required: true,
        },
        config: {
            type: Array,
            required: true
        },
        organization: {
            type: Object,
            required: true,
        },
        organizations: {
            type: Object,
            required: true,
        },
    });

    const languages = computed(() => usePage().props.languages);

    const isSidebarOpen = ref(false);
    const isProfileOpen = ref(false);

    const closeSidebar = () => {
        isSidebarOpen.value = false;
    }

    function openModal() {
        isProfileOpen.value = true;
        isSidebarOpen.value = false;
    }

    const closeModal = () => {
        isProfileOpen.value = false;
    }

    // Check for language refresh parameter and refresh page if needed
    onMounted(() => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('refresh_lang') === '1') {
            // Remove the parameter and refresh to apply user's language
            urlParams.delete('refresh_lang');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            window.history.replaceState({}, '', newUrl);
            window.location.reload();
        }
    });
</script>