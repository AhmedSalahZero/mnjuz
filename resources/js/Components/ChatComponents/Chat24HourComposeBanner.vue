<script setup>
defineProps({
	sendingAuthTemplate: {
		type: Boolean,
		default: false,
	},
})

defineEmits(['viewTemplate', 'sendAuthTemplate'])
</script>

<template>
	<!-- flex-wrap لا sm:flex: عرض اللافتة عرض لوحة المحادثة لا الشاشة، فعلى شاشة لابتوب
	     كان النص يُعصر في عمود ضيّق بجانب الأزرار. هنا تنزل الأزرار تحته حين يضيق المكان. -->
	<div class="flex flex-wrap items-center gap-x-6 gap-y-3 bg-red-50/70 px-4 py-4 sm:px-5">
		<div class="flex min-w-[15rem] flex-1 items-start gap-3">
			<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600"
				aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-5 w-5" fill="none"
					stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<rect x="5" y="11" width="14" height="10" rx="2" />
					<path d="M8 11V7a4 4 0 0 1 8 0v4" />
				</svg>
			</span>
			<div class="min-w-0">
				<p class="text-sm font-semibold text-gray-900">{{ $t('24-hour window closed') }}</p>
				<p class="mt-0.5 text-sm leading-relaxed text-gray-600">
					{{ $t('The customer has not messaged in over 24 hours. WhatsApp only allows template messages until they reply.') }}
				</p>
			</div>
		</div>
		<div class="ms-auto flex shrink-0 flex-wrap items-center gap-2">
			<button type="button"
				class="flex items-center justify-center gap-2 rounded-md border border-[#16332E]/35 bg-white px-4 py-2 text-sm font-medium text-[#16332E] shadow-sm transition-colors hover:bg-white/90"
				@click="$emit('viewTemplate')">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 256 256" class="shrink-0">
					<path fill="currentColor"
						d="M216 80h-32V48a16 16 0 0 0-16-16H40a16 16 0 0 0-16 16v128a8 8 0 0 0 13 6.22L72 154v30a16 16 0 0 0 16 16h93.59L219 230.22a8 8 0 0 0 5 1.78a8 8 0 0 0 8-8V96a16 16 0 0 0-16-16M66.55 137.78L40 159.25V48h128v88H71.58a8 8 0 0 0-5.03 1.78M216 207.25l-26.55-21.47a8 8 0 0 0-5-1.78H88v-32h80a16 16 0 0 0 16-16V96h32Z" />
				</svg>
				<span>{{ $t('Templates') }}</span>
			</button>
			<button type="button" :disabled="sendingAuthTemplate"
				class="flex items-center justify-center gap-2 rounded-md bg-[#16332E] px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-[#122a26] disabled:opacity-70"
				@click="$emit('sendAuthTemplate')">
				<span>{{ $t('Send Template') }}</span>
				<svg v-if="sendingAuthTemplate" xmlns="http://www.w3.org/2000/svg" width="22" height="22"
					viewBox="0 0 24 24">
					<path fill="currentColor"
						d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z"
						opacity=".5" />
					<path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
						<animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite"
							to="360 12 12" type="rotate" />
					</path>
				</svg>
			</button>
		</div>
	</div>
</template>
