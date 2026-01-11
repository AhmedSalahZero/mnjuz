<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit md:flex md:flex-grow md:overflow-y-hidden">
            <div class="md:w-[60%] m-8">
                <Menu v-if="webhookModule" />
                <div class="flex justify-between mt-8">
                    <div>
                        <h3 class="text-md mb-1">{{ $t('Access Tokens') }}</h3>
                        <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2"
                                    d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                            </svg>
                            <span class="ml-1 mt-1">{{ $t('Create and manage your API keys') }}</span>
                        </p>
                    </div>
                    <div>
                        <button @click="generateToken()" type="button" :disabled="loadIcon"
                            class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                            <span v-if="loadIcon == false">{{ $t('Generate API key') }}</span>
                            <span v-else>
                                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em"
                                    viewBox="0 0 24 24">
                                    <path fill="none" stroke="currentColor" stroke-dasharray="15"
                                        stroke-dashoffset="15" stroke-linecap="round" stroke-width="2"
                                        d="M12 3C16.9706 3 21 7.02944 21 12">
                                        <animate fill="freeze" attributeName="stroke-dashoffset" dur="0.3s"
                                            values="15;0" />
                                        <animateTransform attributeName="transform" dur="1.5s"
                                            repeatCount="indefinite" type="rotate" values="0 12 12;360 12 12" />
                                    </path>
                                </svg>
                            </span>
                        </button>
                    </div>
                </div>
                <!-- Table Component-->
                <TokenTable :rows="props.rows" />
            </div>
            <div class="md:w-[40%] border-l bg-black h-screen hidden md:block">
                <Documentation :apirequests="apirequests"/>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
import AppLayout from "./../Layout/App.vue";
import Documentation from "./Documentation.vue";
import Menu from "./Menu.vue";
import { Link, useForm } from "@inertiajs/vue3";
import TokenTable from '@/Components/Tables/TokenTable.vue';
import { ref } from 'vue';
import { TabGroup, TabList, Tab, TabPanels, TabPanel } from '@headlessui/vue';
import VCodeBlock from '@wdns/vue-code-block';

const props = defineProps(['rows', 'url', 'apirequests', 'webhookModule']);
const tab = ref(null);
const code = ref(`const foo = 'bar';`);

const loadIcon = ref(false);

const form = useForm({
    'name': null,
});

const changeTab = (id) => {
    tab.value = id;
}

const generateToken = () => {
    loadIcon.value = true;

    form.post('/developer-tools/access-tokens', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onFinish: () => {
            loadIcon.value = false;
        }
    })
}
</script>