<template>
	<AppLayout>
		<div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
			<div class="md:flex justify-between hidden">
				<div>
					<h2 class="text-xl mb-1">{{ $t('Create ticket') }}</h2>
					<p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
							<path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
								stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
						</svg>
						<span class="ml-1 mt-1">{{ $t('Have an issue? Describe it in detail below') }}</span>
					</p>
				</div>
				<div>
					<Link href="/support"
						class="flex items-center space-x-4 rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
							<path fill="currentColor" fill-rule="evenodd"
								d="M5.841 5.28a.75.75 0 0 0-1.06-1.06L1.53 7.47L1 8l.53.53l3.25 3.25a.75.75 0 0 0 1.061-1.06l-1.97-1.97H24.25a.75.75 0 0 0 0-1.5H3.871l1.97-1.97Z"
								clip-rule="evenodd" />
						</svg>
						{{ $t('Back') }}
					</Link>
				</div>
			</div>
			<div class="bg-white md:border py-5 md:px-5 rounded-[0.5rem] flex justify-center">
				<iframe class="w-full max-w-[600px] border-0" width="600" height="850"
					src="https://business.waz.com.sa/forms/ticket?col=col-md-8+col-md-offset-2" title="Create ticket"
					allowfullscreen />
			</div>
		</div>
	</AppLayout>
</template>
<script setup>
import AppLayout from './../Layout/App.vue'
import { Link } from "@inertiajs/vue3"
</script>
