<script setup lang="ts">
import { TabsTrigger, type TabsTriggerProps } from 'radix-vue'

const props = defineProps<TabsTriggerProps & { class?: string }>()
</script>

<template>
  <TabsTrigger
    v-bind="props"
    class="flex-1 pt-3 pb-2 text-center tracking-[0.3px] text-sm ring-offset-background transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 data-[state=active]:bg-slate-50 data-[state=active]:border-b-2 data-[state=active]:border-slate-700 data-[state=inactive]:text-gray-600 hover:bg-slate-50"
  >
    <slot />
  </TabsTrigger>
</template>
