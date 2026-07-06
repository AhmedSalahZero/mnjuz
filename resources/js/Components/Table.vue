<script setup>
    import Pagination from '@/Components/Pagination.vue';

    const props = defineProps({
        rows: Object,
        className: String,
    })
</script>
<template>
    <div class="bg-slate-100 md:bg-white rounded-[0.5rem]" :class="className">
        <table class="w-full">
            <slot />
        </table>
    </div>

    <Pagination class="mt-3" :pagination="rows.meta"/>
</template>