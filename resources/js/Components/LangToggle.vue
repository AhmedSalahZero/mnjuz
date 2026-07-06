<script setup>
    import { ref, onMounted, onUnmounted } from 'vue';

    const props = defineProps({
        languages: Object,
        currentLanguage: String,
    })

    const isOpen = ref(false);

    const toggleDropdown = () => {
        isOpen.value = !isOpen.value;
    }

    const handleClickOutside = (event) => {
        if (isOpen.value && !event.target.closest('.lang-dd')) {
            isOpen.value = false;
        }
    }

    onMounted(() => {
        document.body.addEventListener('click', handleClickOutside);
    });

    onUnmounted(() => {
        document.body.removeEventListener('click', handleClickOutside);
    });
</script>
<template>
    <div class="relative text-sm">
        <div @click="toggleDropdown()" class="lang-dd flex items-center space-x-2 rounded-xl cursor-pointer">
            <div>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M2 12c0 5.523 4.477 10 10 10s10-4.477 10-10S17.523 2 12 2S2 6.477 2 12"/><path d="M13 2.05S16 6 16 12s-3 9.95-3 9.95m-2 0S8 18 8 12s3-9.95 3-9.95M2.63 15.5h18.74m-18.74-7h18.74"/></g></svg>
            </div>
            <div class="uppercase">{{ props.currentLanguage }}</div>
            <div class="ml-auto">
                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M4.47 9.4a.75.75 0 0 1 1.06 0l6.364 6.364a.25.25 0 0 0 .354 0L18.612 9.4a.75.75 0 0 1 1.06 1.06l-6.364 6.364a1.75 1.75 0 0 1-2.475 0L4.47 10.46a.75.75 0 0 1 0-1.06" clip-rule="evenodd"/></svg>
            </div>
        </div>
        <div v-if="isOpen" class="absolute bg-white z-10 px-1 py-2 mt-2 shadow w-full rounded-md min-w-[8em] text-black">
            <div>
                <a v-for="(item, index) in props.languages" :href="'/language/' + item.code" class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
                    {{ item.name }}
                </a>
            </div>
        </div>
    </div>
</template>