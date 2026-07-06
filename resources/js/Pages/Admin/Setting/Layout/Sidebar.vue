<template>
    <aside class="md:flex flex-col w-[30%] h-full bg-white border-r border-l hidden">
        <div class="flex-grow space-y-3 px-2 overflow-y-auto">
            <div class="flex-1">
                <h2 class="px-4 pt-10 text-xl">{{ $t('Settings') }}</h2>
                <ul class="pt-5 pb-4 space-y-1 text-sm text-gray-700">
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/general') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/general" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="m13.6 21.076l5.46-3.152c.584-.337.875-.505 1.087-.74a2 2 0 0 0 .416-.72c.097-.301.097-.637.097-1.307V8.843c0-.67 0-1.006-.098-1.307a2 2 0 0 0-.416-.72c-.21-.234-.5-.402-1.079-.736L13.6 2.924c-.583-.337-.874-.505-1.184-.57a2 2 0 0 0-.832 0c-.31.065-.601.233-1.184.57L4.938 6.077c-.582.336-.873.504-1.084.739a2 2 0 0 0-.416.72c-.098.302-.098.638-.098 1.311v6.305c0 .673 0 1.01.098 1.311a2 2 0 0 0 .416.72c.211.236.503.404 1.085.74l5.46 3.153c.584.337.875.505 1.185.57c.274.059.558.059.832 0c.31-.065.602-.233 1.185-.57Z"/><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0-6 0Z"/></g></svg>
                            <span>{{ $t('General settings') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/timezone') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/timezone" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 13V9m9-3l-2-2m-9-2h4m-2 19a8 8 0 1 1 0-16a8 8 0 0 1 0 16Z"/></svg>
                            <span>{{ $t('Timezone and currency') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/broadcast-drivers') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/broadcast-drivers" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path d="M18.364 19.364a9 9 0 1 0-12.728 0"/><path d="M15.536 16.536a5 5 0 1 0-7.072 0"/><path d="M11 13a1 1 0 1 0 2 0a1 1 0 1 0-2 0"/></g></svg>
                            <span>{{ $t('Broadcast driver') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/socials') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/socials" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" d="M8 16c0 2.828 0 4.243.879 5.121C9.757 22 11.172 22 14 22h1c2.828 0 4.243 0 5.121-.879C21 20.243 21 18.828 21 16V8c0-2.828 0-4.243-.879-5.121C19.243 2 17.828 2 15 2h-1c-2.828 0-4.243 0-5.121.879C8 3.757 8 5.172 8 8"/><path d="M8 19.5c-2.357 0-3.536 0-4.268-.732C3 18.035 3 16.857 3 14.5v-5c0-2.357 0-3.536.732-4.268C4.464 4.5 5.643 4.5 8 4.5"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 12h9m0 0l-2.5 2.5M15 12l-2.5-2.5"/></g></svg>
                            <span>{{ $t('Social login') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/storage') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/storage" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M6.7 21q-.725 0-1.287-.475t-.688-1.2L3.2 10.175q-.075-.45.213-.812T4.175 9h15.65q.475 0 .763.363t.212.812l-1.525 9.15q-.125.725-.687 1.2T17.3 21H6.7ZM5.4 11l1.275 8h10.65l1.275-8H5.4Zm4.6 4h4q.425 0 .713-.288T15 14q0-.425-.288-.712T14 13h-4q-.425 0-.712.288T9 14q0 .425.288.713T10 15ZM6 8q-.425 0-.712-.288T5 7q0-.425.288-.712T6 6h12q.425 0 .713.288T19 7q0 .425-.288.713T18 8H6Zm2-3q-.425 0-.712-.288T7 4q0-.425.288-.712T8 3h8q.425 0 .713.288T17 4q0 .425-.288.713T16 5H8ZM6.675 19h10.65h-10.65Z"/></svg>
                            <span>{{ $t('Storage settings') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/payment-gateways') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/payment-gateways" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 11v4.8c0 1.12 0 1.68.218 2.108a2 2 0 0 0 .874.874c.427.218.987.218 2.105.218h11.606c1.118 0 1.677 0 2.104-.218c.377-.192.683-.498.875-.874c.218-.428.218-.986.218-2.104V11M3 11V9m0 2h18M3 9v-.8c0-1.12 0-1.68.218-2.108c.192-.377.497-.682.874-.874C4.52 5 5.08 5 6.2 5h11.6c1.12 0 1.68 0 2.107.218c.377.192.683.497.875.874c.218.427.218.987.218 2.105V9M3 9h18M7 15h4m10-4V9"/></svg>
                            <span>{{ $t('Payment gateways') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/smtp') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/smtp" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 18h11M3 18h3m0 0v2m0-2v-2m14-4h1M3 12h13m0 0v2m0-2v-2m-2-4h7M3 6h7m0 0v2m0-2V4"/></svg>
                            <span>{{ $t('SMTP settings') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/email-templates') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/email-templates" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m4 6l6.108 4.612l.002.002c.678.497 1.017.746 1.389.842a2 2 0 0 0 1.002 0c.372-.096.712-.345 1.392-.844c0 0 3.917-3.006 6.107-4.612M3 15.8V8.2c0-1.12 0-1.68.218-2.108c.192-.377.497-.682.874-.874C4.52 5 5.08 5 6.2 5h11.6c1.12 0 1.68 0 2.107.218c.377.192.683.497.875.874c.218.427.218.987.218 2.105v7.607c0 1.118 0 1.676-.218 2.104a2.002 2.002 0 0 1-.875.874c-.427.218-.986.218-2.104.218H6.197c-1.118 0-1.678 0-2.105-.218a2 2 0 0 1-.874-.874C3 17.48 3 16.92 3 15.8Z"/></svg>
                            <span>{{ $t('Email templates') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/pages') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/pages" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48"><g fill="none" stroke="currentColor" stroke-linejoin="round" stroke-width="4"><rect width="36" height="36" x="6" y="6" rx="3"/><path stroke-linecap="round" d="M6 17h36M17 42V17"/></g></svg>
                            <span>{{ $t('Frontend Pages') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/subscription') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/subscription" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 13V9m9-3l-2-2m-9-2h4m-2 19a8 8 0 1 1 0-16a8 8 0 0 1 0 16Z"/></svg>
                            <span>{{ $t('Subscription settings') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/settings/billing') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/settings/billing" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 11v4.8c0 1.12 0 1.68.218 2.108a2 2 0 0 0 .874.874c.427.218.987.218 2.105.218h11.606c1.118 0 1.677 0 2.104-.218c.377-.192.683-.498.875-.874c.218-.428.218-.986.218-2.104V11M3 11V9m0 2h18M3 9v-.8c0-1.12 0-1.68.218-2.108c.192-.377.497-.682.874-.874C4.52 5 5.08 5 6.2 5h11.6c1.12 0 1.68 0 2.107.218c.377.192.683.497.875.874c.218.427.218.987.218 2.105V9M3 9h18M7 15h4m10-4V9"/></svg>
                            <span>{{ $t('Billing info') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/tax-rates') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/tax-rates" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16l3.5-2l3.5 2l3.5-2l3.5 2ZM10 8.5a.5.5 0 1 1-1 0a.5.5 0 0 1 1 0Zm5 5a.5.5 0 1 1-1 0a.5.5 0 0 1 1 0Z"/></svg>
                            <span>{{ $t('Tax rates') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/coupons') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/coupons" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 6H6c-.932 0-1.398 0-1.766.152a2 2 0 0 0-1.082 1.083C3 7.602 3 8.068 3 9a3 3 0 1 1 0 6c0 .932 0 1.398.152 1.765a2 2 0 0 0 1.082 1.083C4.602 18 5.068 18 6 18h8m0-12h4c.932 0 1.398 0 1.765.152a2 2 0 0 1 1.083 1.083C21 7.602 21 8.068 21 9a3 3 0 1 0 0 6c0 .932 0 1.398-.152 1.765a2 2 0 0 1-1.083 1.083C19.398 18 18.932 18 18 18h-4m0-12v12"/></svg>
                            <span>{{ $t('Coupons') }}</span>
                        </Link>
                    </li>
                    <li class="hover:bg-primary hover:text-white rounded-[5px] px-2 truncate" :class="$page.url.startsWith('/admin/languages') ? 'bg-primary text-white' : ''">
                        <Link rel="noopener noreferrer" href="/admin/languages" class="flex items-center p-2 space-x-3 rounded-md">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M2 12c0 5.523 4.477 10 10 10s10-4.477 10-10S17.523 2 12 2S2 6.477 2 12"/><path d="M13 2.05S16 6 16 12s-3 9.95-3 9.95m-2 0S8 18 8 12s3-9.95 3-9.95M2.63 15.5h18.74m-18.74-7h18.74"/></g></svg>
                            <span>{{ $t('Languages & translations') }}</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </div>
        <div class="flex items-center justify-between mx-2 my-2 pl-4 px-2 py-1 border rounded-md">
            <span class="text-sm font-light text-gray-500">Version: v{{ version }}</span>
            <Link href="/admin/updates" class="border text-sm px-4 py-1 rounded-md">{{ $t('Updates') }}</Link>
        </div>
    </aside>
</template>
<script setup>
    import { Link, usePage } from "@inertiajs/vue3";
    import { computed, ref } from "vue";

    const { props } = usePage();

    const showDropdown1 = ref(false);
    const toggleDropdown = (type) => {
        if(type === 'dropdown1'){
            showDropdown1.value = !showDropdown1.value;
        }
    }
    
    const version = computed(() => usePage().props.applicationVersion);
    const releaseDate = computed(() => usePage().props.applicationReleaseDate);
</script>