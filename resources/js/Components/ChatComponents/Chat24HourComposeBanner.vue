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
	<div
		class="border-b border-gray-100 bg-[#FFF5EC] p-4 sm:flex sm:items-center sm:justify-between sm:gap-6 sm:p-5">
		<div class="flex min-w-0 flex-1 items-start gap-3">
			<span class="mt-0.5 shrink-0 text-red-600" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none"
					stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
					<path
						d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
				</svg>
			</span>
			<div class="min-w-0">
				<div class="text-base font-semibold text-gray-900">{{ $t('24 hour limit') }}</div>
				<p class="mt-1 text-sm leading-relaxed text-gray-600">
					{{
						$t(
							'Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message',
						)
					}}
				</p>
			</div>
		</div>
		<div class="mt-4 flex shrink-0 flex-wrap items-center justify-end gap-2 sm:mt-0 sm:justify-end">
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
