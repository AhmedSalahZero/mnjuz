<script setup>
    const props = defineProps({
        position: {
            type: String,
            default: null
        },
    })

    const getRowClass = () => {
        if (props.position === 'first') {
            return 'font-normal pl-4 py-4 text-left';
        } else if (props.position === 'last') {
            return 'pr-4';
        } else {
            return 'font-normal py-4 text-left'
        }
    };
</script>
<template>
    <th :class="getRowClass()">
        <slot/>
    </th>
</template>