<script setup lang="ts">
import { TabsList, type TabsListProps } from 'radix-vue'

const props = defineProps<TabsListProps & { class?: string }>()
</script>

<template>
  <TabsList
    v-bind="props"
    class="flex w-full border-b"
  >
    <slot />
  </TabsList>
</template>
