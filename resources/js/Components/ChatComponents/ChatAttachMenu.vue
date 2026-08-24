<script setup>
import { ref } from 'vue'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

defineProps({
	showAiAssist: { type: Boolean, default: false },
	requestingLocation: { type: Boolean, default: false },
	sendingLocation: { type: Boolean, default: false },
	disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['action'])

const open = ref(false)

const select = (action) => {
	open.value = false
	emit('action', action)
}

const close = () => {
	open.value = false
}
</script>

<template>
	<div>
		<button
			type="button"
			class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-600 hover:bg-slate-100 disabled:opacity-40"
			:disabled="disabled"
			:aria-label="trans('Attach')"
			:title="trans('Attach')"
			@click="open = true"
		>
			<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" aria-hidden="true">
				<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M12 5v14M5 12h14" />
			</svg>
		</button>

		<Teleport to="body">
			<div
				v-if="open"
				class="fixed inset-0 z-[9990] md:hidden"
				role="dialog"
				aria-modal="true"
				:aria-label="trans('Attach')"
			>
				<div class="absolute inset-0 bg-black/40" @click="close" />

				<div class="absolute inset-x-0 bottom-0 rounded-t-2xl bg-white pb-[env(safe-area-inset-bottom)] shadow-2xl">
					<div class="flex justify-center py-2">
						<div class="h-1 w-10 rounded-full bg-slate-200" />
					</div>

					<div class="grid grid-cols-4 gap-1 px-3 pb-4">
						<button
							type="button"
							class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-slate-700 hover:bg-slate-50 active:bg-slate-100"
							@click="select('image')"
						>
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="text-slate-600">
								<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5">
									<path d="M6.5 8a2 2 0 1 0 4 0a2 2 0 0 0-4 0Zm14.427 1.99c-6.61-.908-12.31 4-11.927 10.51" />
									<path d="M3 13.066c2.78-.385 5.275.958 6.624 3.1" />
									<path d="M3 12c0-4.243 0-6.364 1.318-7.682C5.636 3 7.758 3 12 3c4.243 0 6.364 0 7.682 1.318C21 5.636 21 7.758 21 12c0 4.243 0 6.364-1.318 7.682C18.364 21 16.242 21 12 21c-4.243 0-6.364 0-7.682-1.318C3 18.364 3 16.242 3 12Z" />
								</g>
							</svg>
							<span class="text-center text-[11px] leading-tight">{{ trans('Image') }}</span>
						</button>

						<button
							type="button"
							class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-slate-700 hover:bg-slate-50 active:bg-slate-100"
							@click="select('document')"
						>
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" class="text-slate-600">
								<g fill="none" stroke="currentColor" stroke-width="1.5">
									<path d="M3 10c0-3.771 0-5.657 1.172-6.828C5.343 2 7.229 2 11 2h2c3.771 0 5.657 0 6.828 1.172C21 4.343 21 6.229 21 10v4c0 3.771 0 5.657-1.172 6.828C18.657 22 16.771 22 13 22h-2c-3.771 0-5.657 0-6.828-1.172C3 19.657 3 17.771 3 14v-4Z" />
									<path stroke-linecap="round" d="M8 10h8m-8 4h5" />
								</g>
							</svg>
							<span class="text-center text-[11px] leading-tight">{{ trans('Document') }}</span>
						</button>

						<button
							type="button"
							class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-slate-700 hover:bg-slate-50 active:bg-slate-100"
							@click="select('video')"
						>
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" class="text-slate-600">
								<path fill="currentColor" d="M6.5 5.5A4.5 4.5 0 0 0 2 10v12a4.5 4.5 0 0 0 4.5 4.5h12A4.5 4.5 0 0 0 23 22v-1.5l4.2 3.15c1.153.865 2.8.042 2.8-1.4V9.75c0-1.442-1.647-2.265-2.8-1.4L23 11.5V10a4.5 4.5 0 0 0-4.5-4.5zM23 14l5-3.75v11.5L23 18zm-2-4v12a2.5 2.5 0 0 1-2.5 2.5h-12A2.5 2.5 0 0 1 4 22V10a2.5 2.5 0 0 1 2.5-2.5h12A2.5 2.5 0 0 1 21 10" />
							</svg>
							<span class="text-center text-[11px] leading-tight">{{ trans('Video') }}</span>
						</button>

						<button
							type="button"
							class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-slate-700 hover:bg-slate-50 active:bg-slate-100"
							@click="select('audio')"
						>
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" class="text-slate-600">
								<g fill="none" stroke="currentColor" stroke-width="1.5">
									<path d="M9 19a3 3 0 1 1-6 0a3 3 0 0 1 6 0Zm12-2a3 3 0 1 1-6 0a3 3 0 0 1 6 0ZM9 19V8m12 9V6" />
									<path stroke-linecap="round" d="m15.735 3.755l-4 1.333c-1.32.44-1.98.66-2.357 1.184S9 7.492 9 8.882V12l12-4v-.45c0-2.533 0-3.8-.83-4.398c-.831-.599-2.032-.198-4.435.603Z" />
								</g>
							</svg>
							<span class="text-center text-[11px] leading-tight">{{ trans('Audio') }}</span>
						</button>

						<button
							type="button"
							class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-slate-700 hover:bg-slate-50 active:bg-slate-100 disabled:opacity-40"
							:disabled="requestingLocation"
							@click="select('request-location')"
						>
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" class="text-slate-600">
								<g fill="none" stroke="currentColor" stroke-width="1.5">
									<path d="M12 21c-4.418-4.03-7-7.4-7-10.5a7 7 0 1 1 14 0c0 3.1-2.582 6.47-7 10.5Z" />
									<circle cx="12" cy="10.5" r="2.5" />
								</g>
							</svg>
							<span class="text-center text-[11px] leading-tight">{{ trans('Request customer location') }}</span>
						</button>

						<button
							type="button"
							class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-slate-700 hover:bg-slate-50 active:bg-slate-100 disabled:opacity-40"
							:disabled="sendingLocation || disabled"
							@click="select('send-location')"
						>
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" class="text-slate-600">
								<g fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
									<path d="M12 21c-4.418-4.03-7-7.4-7-10.5a7 7 0 1 1 14 0c0 3.1-2.582 6.47-7 10.5Z" fill="currentColor" fill-opacity="0.15" />
									<path d="m14.5 8.5l-5 2.2l2.1 1l1 2.1z" fill="currentColor" />
								</g>
							</svg>
							<span class="text-center text-[11px] leading-tight">{{ trans('Send our location') }}</span>
						</button>

						<button
							type="button"
							class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-slate-700 hover:bg-slate-50 active:bg-slate-100"
							@click="select('templates')"
						>
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 256 256" class="text-slate-600">
								<path fill="currentColor" d="M216 80h-32V48a16 16 0 0 0-16-16H40a16 16 0 0 0-16 16v128a8 8 0 0 0 13 6.22L72 154v30a16 16 0 0 0 16 16h93.59L219 230.22a8 8 0 0 0 5 1.78a8 8 0 0 0 8-8V96a16 16 0 0 0-16-16M66.55 137.78L40 159.25V48h128v88H71.58a8 8 0 0 0-5.03 1.78M216 207.25l-26.55-21.47a8 8 0 0 0-5-1.78H88v-32h80a16 16 0 0 0 16-16V96h32Z" />
							</svg>
							<span class="text-center text-[11px] leading-tight">{{ trans('Templates') }}</span>
						</button>

						<button
							v-if="showAiAssist"
							type="button"
							class="flex min-h-[72px] flex-col items-center justify-center gap-1.5 rounded-xl px-1 py-2 text-slate-700 hover:bg-slate-50 active:bg-slate-100"
							@click="select('ai-assist')"
						>
							<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="text-slate-600">
								<g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5">
									<path d="m7 8l2.942 1.74c1.715 1.014 2.4 1.014 4.116 0L17 8" />
									<path d="M21.984 12.976c.021-.986.021-1.966 0-2.952c-.065-3.065-.098-4.598-1.229-5.733c-1.131-1.136-2.705-1.175-5.854-1.254a115 115 0 0 0-5.802 0c-3.149.079-4.723.118-5.854 1.254c-1.131 1.135-1.164 2.668-1.23 5.733a69 69 0 0 0 0 2.952c.066 3.065.099 4.598 1.23 5.733c1.131 1.136 2.705 1.175 5.854 1.254c1.305.033 2.601.044 3.901.033" />
									<path d="m18.5 14l.258.697c.338.914.507 1.371.84 1.704c.334.334.791.503 1.705.841L22 17.5l-.697.258c-.914.338-1.371.507-1.704.84c-.334.334-.503.791-.841 1.705L18.5 21l-.258-.697c-.338-.914-.507-1.371-.84-1.704c-.334-.334-.791-.503-1.705-.841L15 17.5l.697-.258c.914-.338 1.371-.507 1.704-.84c.334-.334.503-.791.841-1.705z" />
								</g>
							</svg>
							<span class="text-center text-[11px] leading-tight">{{ trans('AI Assist') }}</span>
						</button>
					</div>
				</div>
			</div>
		</Teleport>
	</div>
</template>
