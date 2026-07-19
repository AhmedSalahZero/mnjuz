<script setup>
const props = defineProps({
	items: { type: Array, default: () => [] },
	activeIndex: { type: Number, default: 0 },
})

const emit = defineEmits(['select', 'hover'])
</script>

<template>
	<div v-if="items.length"
		class="absolute left-2 right-2 md:left-10 md:right-10 bottom-full mb-2 z-30 max-h-60 overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-lg">
		<div v-for="(item, index) in items" :key="index"
			class="flex flex-col px-3 py-2 cursor-pointer border-b last:border-b-0"
			:class="index === activeIndex ? 'bg-slate-100' : 'hover:bg-slate-50'"
			@mouseenter="emit('hover', index)"
			@mousedown.prevent="emit('select', index)">
			<div class="flex items-center gap-2">
				<span class="text-xs font-semibold text-green-600">/{{ item.command }}</span>
				<span v-if="item.scope === 'company'"
					class="text-[9px] uppercase bg-slate-200 text-slate-600 rounded px-1">{{ $t('Company') }}</span>
			</div>
			<span class="truncate text-xs text-slate-500">{{ item.message }}</span>
		</div>
	</div>
</template>
