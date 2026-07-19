<script setup>
    const props = defineProps({
        position: {
            type: String,
            default: null
        },
    })

    const getRowClass = () => {
        if (props.position === 'first') {
            return 'pl-4 py-4';
        } else if (props.position === 'last') {
            return 'pr-4 float-right';
        }
    };
</script>
<template>
    <td :class="getRowClass()">
        <div class="text-left w-full h-full"><slot /></div>
    </td>
</template>