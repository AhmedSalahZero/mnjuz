<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:py-8 md:pl-8 md:pr-0 rounded-[5px] text-[#000] overflow-y-hidden">
            <div class="flex justify-between">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Automation') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Respond automatically to messages based on your own criteria') }}</span>
                    </p>
                </div>
            </div>
        
            <div class="flex mt-4">
                <div class="md:w-1/5 bg-white border border-slate-200 h-[fit-content] rounded-lg pb-10 md:block hidden">
                    <ul class="pt-2 space-y-1 text-sm mb-2 px-2">
                        <li class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url === '/automation/basic' ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/basic" class="flex items-center p-2 space-x-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M12 15a3 3 0 1 0 0-6a3 3 0 0 0 0 6"/><path d="m19.622 10.395l-1.097-2.65L20 6l-2-2l-1.735 1.483l-2.707-1.113L12.935 2h-1.954l-.632 2.401l-2.645 1.115L6 4L4 6l1.453 1.789l-1.08 2.657L2 11v2l2.401.656L5.516 16.3L4 18l2 2l1.791-1.46l2.606 1.072L11 22h2l.604-2.387l2.651-1.098C16.697 18.832 18 20 18 20l2-2l-1.484-1.75l1.098-2.652l2.386-.62V11z"/></g></svg>
                                <span>{{ $t('Basic Replies') }}</span>
                            </Link>
                        </li>
                        <li v-if="fbmodule" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/flows') ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/flows" class="flex items-center p-2 space-x-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5c0-1.414 0-2.121.44-2.56C3.878 2 4.585 2 6 2s2.121 0 2.56.44C9 2.878 9 3.585 9 5s0 2.121-.44 2.56C8.122 8 7.415 8 6 8s-2.121 0-2.56-.44C3 7.122 3 6.415 3 5m0 14c0-1.414 0-2.121.44-2.56C3.878 16 4.585 16 6 16s2.121 0 2.56.44C9 16.878 9 17.585 9 19s0 2.121-.44 2.56C8.122 22 7.415 22 6 22s-2.121 0-2.56-.44C3 21.122 3 20.415 3 19M15 7c0-1.414 0-2.121.44-2.56C15.878 4 16.585 4 18 4s2.121 0 2.56.44C21 4.878 21 5.585 21 7s0 2.121-.44 2.56c-.439.44-1.146.44-2.56.44s-2.121 0-2.56-.44C15 9.122 15 8.415 15 7m-9 9V8m12 2a3 3 0 0 1-3 3H9a3 3 0 0 0-3 3" color="currentColor"/></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('Automations') }}</div>
                            </Link>
                        </li>
                        <li v-if="aimodule" class="hover:bg-slate-50 hover:text-black rounded-[5px] px-1 truncate cursor-pointer" :class="$page.url.startsWith('/automation/ai') ? 'bg-slate-50 text-black' : ''">
                            <Link rel="noopener noreferrer" href="/automation/ai" class="flex items-center p-2 space-x-3 rounded-md">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 14 14"><g fill="none" stroke="black" stroke-linecap="round" stroke-linejoin="round"><path d="M6.022 4.347a18.452 18.452 0 0 0-1.93 1.686C1.248 8.877-.192 12.046.874 13.113c1.066 1.066 4.236-.375 7.079-3.218a18.452 18.452 0 0 0 1.686-1.931"/><path d="M9.639 7.964c1.677 2.226 2.36 4.32 1.532 5.148c-1.067 1.067-4.236-.374-7.08-3.217C1.249 7.05-.191 3.882.875 2.815c.828-.827 2.922-.144 5.148 1.532"/><path d="M5.522 7.964a.5.5 0 1 0 1 0a.5.5 0 0 0-1 0m2.51-4.354c-.315-.055-.315-.506 0-.56a2.843 2.843 0 0 0 2.29-2.193L10.34.77c.068-.31.51-.312.58-.003l.024.101a2.858 2.858 0 0 0 2.296 2.18c.316.055.316.509 0 .563a2.858 2.858 0 0 0-2.296 2.18l-.024.101c-.07.31-.512.308-.58-.002l-.02-.087A2.843 2.843 0 0 0 8.03 3.61Z"/></g></svg>
                                <div class="overflow-hidden text-ellipsis">{{ $t('AI Reply Assistant') }}</div>
                            </Link>
                        </li>
                    </ul>
                </div>

                <div class="md:w-4/5 bg-white md:bg-inherit md:px-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
                    <slot/>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { Link } from "@inertiajs/vue3";
    import { ref } from 'vue';
    import FormModal from '@/Components/FormModal.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps(['aimodule', 'fbmodule']);
</script>