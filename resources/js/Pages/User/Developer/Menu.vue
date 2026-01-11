<template>
    <div class="rounded-[5px] text-[#000]">
        <div>
            <h2 class="text-xl mb-1">{{ $t('Developer Tools') }}</h2>
        </div>
        <div class="flex gap-x-2 mt-8 border-b-2">
            <Link rel="noopener noreferrer" href="/developer-tools/access-tokens" class="hover:bg-white rounded-tl-md rounded-tr-md px-2 py-2 text-sm cursor-pointer hover:border-b hover:border-black" :class="$page.url === '/developer-tools/access-tokens' ? 'bg-white border-b border-black' : ''">
                <span>Access Token</span>
            </Link>
            <Link rel="noopener noreferrer" href="/developer-tools/webhooks" class="hover:bg-white rounded-tl-md rounded-tr-md px-2 py-2 text-sm cursor-pointer hover:border-b hover:border-black" :class="$page.url === '/developer-tools/webhooks' ? 'bg-white border-b border-black' : ''">
                <span>Webhooks</span>
            </Link>
        </div>
    </div>
</template>
<script setup>
    import { Link, useForm } from "@inertiajs/vue3";
</script>