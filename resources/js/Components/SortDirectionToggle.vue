<script setup>
    import { ref, onMounted, onUnmounted } from 'vue';
    import { router, Link } from "@inertiajs/vue3";

    const props = defineProps({
        direction: String,
        url: String,
    })

    const isOpen = ref(false);

    const toggleDropdown = () => {
        isOpen.value = !isOpen.value;
    }

    const handleClickOutside = (event) => {
        if (isOpen.value && !event.target.closest('.sort-dd')) {
            isOpen.value = false;
        }
    }

    onMounted(() => {
        document.body.addEventListener('click', handleClickOutside);
    });

    onUnmounted(() => {
        document.body.removeEventListener('click', handleClickOutside);
    });

    const sort = (value) => {
        router.post(props.url, {
            'sort' : value
        }, {
            preserveState: false,
        })
    }
</script>
<template>
    <div class="text-sm">
        <span @click="toggleDropdown()" class="cursor-pointer hover:bg-slate-50 p-1 rounded-full sort-dd block">
            <svg v-if="direction == 'desc'" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 16 16"><path fill="currentColor" d="M3.5 13.5a.5.5 0 0 1-1 0V4.707L1.354 5.854a.5.5 0 1 1-.708-.708l2-1.999l.007-.007a.5.5 0 0 1 .7.006l2 2a.5.5 0 1 1-.707.708L3.5 4.707zm4-9.5a.5.5 0 0 1 0-1h1a.5.5 0 0 1 0 1zm0 3a.5.5 0 0 1 0-1h3a.5.5 0 0 1 0 1zm0 3a.5.5 0 0 1 0-1h5a.5.5 0 0 1 0 1zM7 12.5a.5.5 0 0 0 .5.5h7a.5.5 0 0 0 0-1h-7a.5.5 0 0 0-.5.5"/></svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 16 16"><path fill="currentColor" d="M3.5 2.5a.5.5 0 0 0-1 0v8.793l-1.146-1.147a.5.5 0 0 0-.708.708l2 1.999l.007.007a.497.497 0 0 0 .7-.006l2-2a.5.5 0 0 0-.707-.708L3.5 11.293zm3.5 1a.5.5 0 0 1 .5-.5h7a.5.5 0 0 1 0 1h-7a.5.5 0 0 1-.5-.5M7.5 6a.5.5 0 0 0 0 1h5a.5.5 0 0 0 0-1zm0 3a.5.5 0 0 0 0 1h3a.5.5 0 0 0 0-1zm0 3a.5.5 0 0 0 0 1h1a.5.5 0 0 0 0-1z"/></svg>
        </span>
        <div v-if="isOpen" class="absolute bg-white z-10 px-1 py-2 mt-2 shadow w-[7em] rounded-md text-black">
            <div>
                <div @click="sort('desc')" class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
                    Newest
                </div>
                <div @click="sort('asc')" class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
                    Oldest
                </div>
            </div>
        </div>
    </div>
</template>