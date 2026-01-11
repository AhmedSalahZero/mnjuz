<template>
    <AppLayout>
        <div class="fixed top-0 z-10 w-full bg-white border-b px-4 py-4 flex md:hidden">
            <div class="w-1/3">
                <Link href="/admin/settings">
                    <svg xmlns="http://www.w3.org/2000/svg" width="2.5em" height="2.5em" viewBox="0 0 256 256"><path fill="currentColor" d="M168.49 199.51a12 12 0 0 1-17 17l-80-80a12 12 0 0 1 0-17l80-80a12 12 0 0 1 17 17L97 128Z"/></svg>
                </Link>
            </div>
            <h3 class="w-1/3 text-3xl">{{ $t('Settings') }}</h3>
            <div class="w-1/3"></div>
        </div>
        <div class="flex flex-grow">
            <Sidebar></Sidebar>
            <div class="bg-white md:bg-inherit pt-16 md:pt-8 md:px-16 px-4 w-full py-8 md:overflow-y-scroll h-screen">
                <slot></slot>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../../Layout/App.vue";
    import Sidebar from "./Sidebar.vue";
    import { Link } from "@inertiajs/vue3";
</script>