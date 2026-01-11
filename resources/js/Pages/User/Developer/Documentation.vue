<template>
    <TabGroup>
        <TabList class="flex space-x-1 backdrop-blur-xl bg-white/20 p-1">
            <Tab as="template" v-slot="{ selected }">
                <button :class="[
                    'w-full py-2.5 text-sm leading-5 text-[#ffffffcc]',
                    'ring-white focus:outline-none',
                    selected
                        ? 'text-white shadow border-b-2 border-white'
                        : 'hover:bg-slate-100 hover:text-black',
                ]">
                    {{ $t('CURL') }}
                </button>
            </Tab>
            <Tab as="template" v-slot="{ selected }">
                <button :class="[
                    'w-full py-2.5 text-sm leading-5 text-[#ffffffcc]',
                    'ring-white focus:outline-none',
                    selected
                        ? 'text-white shadow border-b-2 border-white'
                        : 'hover:bg-slate-100 hover:text-black',
                ]">
                    {{ $t('PHP') }}
                </button>
            </Tab>
            <Tab as="template" v-slot="{ selected }">
                <button :class="[
                    'w-full py-2.5 text-sm leading-5 text-[#ffffffcc]',
                    'ring-white focus:outline-none',
                    selected
                        ? 'text-white shadow border-b-2 border-white'
                        : 'hover:bg-slate-100 hover:text-black',
                ]">
                    {{ $t('NODEJS') }}
                </button>
            </Tab>
            <Tab as="template" v-slot="{ selected }">
                <button :class="[
                    'w-full py-2.5 text-sm leading-5 text-[#ffffffcc]',
                    'ring-white focus:outline-none',
                    selected
                        ? 'text-white shadow border-b-2 border-white'
                        : 'hover:bg-slate-100 hover:text-black',
                ]">
                    {{ $t('PYTHON') }}
                </button>
            </Tab>
            <Tab as="template" v-slot="{ selected }">
                <button :class="[
                    'w-full py-2.5 text-sm leading-5 text-[#ffffffcc]',
                    'ring-white focus:outline-none',
                    selected
                        ? 'text-white shadow border-b-2 border-white'
                        : 'hover:bg-slate-100 hover:text-black',
                ]">
                    {{ $t('JAVA') }}
                </button>
            </Tab>
            <Tab as="template" v-slot="{ selected }">
                <button :class="[
                    'w-full py-2.5 text-sm leading-5 text-[#ffffffcc]',
                    'ring-white focus:outline-none',
                    selected
                        ? 'text-white shadow border-b-2 border-white'
                        : 'hover:bg-slate-100 hover:text-black',
                ]">
                    {{ $t('RUBY') }}
                </button>
            </Tab>
        </TabList>

        <TabPanels class="mt-2 text-white text-sm h-[90vh] overflow-y-scroll">
            <TabPanel>
                <div class="code-block">
                    <div v-for="(item1, index1) in props.apirequests">
                        <h3 class="text-white mb-2 capitalize" :class="index1 != 0 ? 'mt-4' : ''">{{ $t(item1.title) }}</h3>
                        <div v-for="(item, index) in item1.value" @click="changeTab('curl' + item1.title + index + 1)" class="mb-2 bg-white cursor-pointer py-1 px-2 rounded">
                            <div class="flex items-center gap-x-2">
                                <span class="rounded-md bg-primary px-2 text-[10px] uppercase">{{ item.method }}</span>
                                <span class="text-black">{{ $t(item.title) }}</span>
                            </div>
                            <div class="text-black mt-2 mb-2" :class="tab === 'curl' + item1.title + index + 1 ? '' : 'hidden'">
                                <div class="flex items-center gap-x-2 mb-2">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                            viewBox="0 0 24 24">
                                            <path fill="currentColor"
                                                d="m7.05 11.293l-2.12 2.121a4 4 0 0 0 5.657 5.657l2.828-2.828a4 4 0 0 0 0-5.657l-1.06 1.06a2.5 2.5 0 0 1 0 3.536l-2.83 2.828a2.5 2.5 0 0 1-3.535-3.535l2.12-2.121z" />
                                            <path fill="currentColor"
                                                d="m15.889 11.646l2.121-2.12a2.5 2.5 0 0 0-3.535-3.536l-2.829 2.828a2.5 2.5 0 0 0 0 3.536l-1.06 1.06a4 4 0 0 1 0-5.657l2.828-2.828a4 4 0 0 1 5.657 5.657l-2.121 2.121z" />
                                        </svg>
                                    </span>
                                    <span>{{ item.route }}</span>
                                </div>
                                <hr />
                                <h4 class="mt-2 uppercase">{{ $t('Request') }}</h4>
                                <div class="text-[10px] mt-2">
                                    <VCodeBlock 
                                        :code=item.request.curl 
                                        highlightjs lang="bash" 
                                        theme="github-dark"/>
                                </div>
                                <h4 class="mt-4 uppercase">{{ $t('Response') }}</h4>
                                <div class="border p-2 rounded-md mt-2">
                                    <div class="flex items-center gap-x-2">
                                        <div class="bg-green-500 h-2 w-2 rounded-full"></div>
                                        <span>200</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </TabPanel>
            <TabPanel>
                <div class="code-block">
                    <div v-for="(item1, index1) in props.apirequests">
                        <h3 class="text-white mb-2 capitalize" :class="index1 != 0 ? 'mt-4' : ''">{{ $t(item1.title) }}</h3>
                        <div v-for="(item, index) in item1.value" @click="changeTab('php' + item1.title + index + 1)" class="mb-2 bg-white cursor-pointer py-1 px-2 rounded">
                            <div class="flex items-center gap-x-2">
                                <span class="rounded-md bg-primary px-2 text-[10px] uppercase">{{ item.method }}</span>
                                <span class="text-black">{{ $t(item.title) }}</span>
                            </div>
                            <div class="text-black mt-2 mb-2" :class="tab === 'php' + item1.title + index + 1 ? '' : 'hidden'">
                                <div class="flex items-center gap-x-2 mb-2">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                            viewBox="0 0 24 24">
                                            <path fill="currentColor"
                                                d="m7.05 11.293l-2.12 2.121a4 4 0 0 0 5.657 5.657l2.828-2.828a4 4 0 0 0 0-5.657l-1.06 1.06a2.5 2.5 0 0 1 0 3.536l-2.83 2.828a2.5 2.5 0 0 1-3.535-3.535l2.12-2.121z" />
                                            <path fill="currentColor"
                                                d="m15.889 11.646l2.121-2.12a2.5 2.5 0 0 0-3.535-3.536l-2.829 2.828a2.5 2.5 0 0 0 0 3.536l-1.06 1.06a4 4 0 0 1 0-5.657l2.828-2.828a4 4 0 0 1 5.657 5.657l-2.121 2.121z" />
                                        </svg>
                                    </span>
                                    <span>{{ item.route }}</span>
                                </div>
                                <hr />
                                <h4 class="mt-2 uppercase">{{ $t('Request') }}</h4>
                                <div class="text-[10px] mt-2">
                                    <VCodeBlock 
                                        :code=item.request.php 
                                        highlightjs lang="bash" 
                                        theme="github-dark"/>
                                </div>
                                <h4 class="mt-4 uppercase">{{ $t('Response') }}</h4>
                                <div class="border p-2 rounded-md mt-2">
                                    <div class="flex items-center gap-x-2">
                                        <div class="bg-green-500 h-2 w-2 rounded-full"></div>
                                        <span>200</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </TabPanel>
            <TabPanel>
                <div class="code-block">
                    <div v-for="(item1, index1) in props.apirequests">
                        <h3 class="text-white mb-2 capitalize" :class="index1 != 0 ? 'mt-4' : ''">{{ $t(item1.title) }}</h3>
                        <div v-for="(item, index) in item1.value" @click="changeTab('nodejs' + item1.title + index + 1)" class="mb-2 bg-white cursor-pointer py-1 px-2 rounded">
                            <div class="flex items-center gap-x-2">
                                <span class="rounded-md bg-primary px-2 text-[10px] uppercase">{{ item.method }}</span>
                                <span class="text-black">{{ $t(item.title) }}</span>
                            </div>
                            <div class="text-black mt-2 mb-2" :class="tab === 'nodejs' + item1.title + index + 1 ? '' : 'hidden'">
                                <div class="flex items-center gap-x-2 mb-2">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                            viewBox="0 0 24 24">
                                            <path fill="currentColor"
                                                d="m7.05 11.293l-2.12 2.121a4 4 0 0 0 5.657 5.657l2.828-2.828a4 4 0 0 0 0-5.657l-1.06 1.06a2.5 2.5 0 0 1 0 3.536l-2.83 2.828a2.5 2.5 0 0 1-3.535-3.535l2.12-2.121z" />
                                            <path fill="currentColor"
                                                d="m15.889 11.646l2.121-2.12a2.5 2.5 0 0 0-3.535-3.536l-2.829 2.828a2.5 2.5 0 0 0 0 3.536l-1.06 1.06a4 4 0 0 1 0-5.657l2.828-2.828a4 4 0 0 1 5.657 5.657l-2.121 2.121z" />
                                        </svg>
                                    </span>
                                    <span>{{ item.route }}</span>
                                </div>
                                <hr />
                                <h4 class="mt-2 uppercase">{{ $t('Request') }}</h4>
                                <div class="text-[10px] mt-2">
                                    <VCodeBlock 
                                        :code=item.request.nodejs 
                                        highlightjs lang="bash" 
                                        theme="github-dark"/>
                                </div>
                                <h4 class="mt-4 uppercase">{{ $t('Response') }}</h4>
                                <div class="border p-2 rounded-md mt-2">
                                    <div class="flex items-center gap-x-2">
                                        <div class="bg-green-500 h-2 w-2 rounded-full"></div>
                                        <span>200</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </TabPanel>
            <TabPanel>
                <div class="code-block">
                    <div v-for="(item1, index1) in props.apirequests">
                        <h3 class="text-white mb-2 capitalize" :class="index1 != 0 ? 'mt-4' : ''">{{ $t(item1.title) }}</h3>
                        <div v-for="(item, index) in item1.value" @click="changeTab('python' + item1.title + index + 1)" class="mb-2 bg-white cursor-pointer py-1 px-2 rounded">
                            <div class="flex items-center gap-x-2">
                                <span class="rounded-md bg-primary px-2 text-[10px] uppercase">{{ item.method }}</span>
                                <span class="text-black">{{ $t(item.title) }}</span>
                            </div>
                            <div class="text-black mt-2 mb-2" :class="tab === 'python' + item1.title + index + 1 ? '' : 'hidden'">
                                <div class="flex items-center gap-x-2 mb-2">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                            viewBox="0 0 24 24">
                                            <path fill="currentColor"
                                                d="m7.05 11.293l-2.12 2.121a4 4 0 0 0 5.657 5.657l2.828-2.828a4 4 0 0 0 0-5.657l-1.06 1.06a2.5 2.5 0 0 1 0 3.536l-2.83 2.828a2.5 2.5 0 0 1-3.535-3.535l2.12-2.121z" />
                                            <path fill="currentColor"
                                                d="m15.889 11.646l2.121-2.12a2.5 2.5 0 0 0-3.535-3.536l-2.829 2.828a2.5 2.5 0 0 0 0 3.536l-1.06 1.06a4 4 0 0 1 0-5.657l2.828-2.828a4 4 0 0 1 5.657 5.657l-2.121 2.121z" />
                                        </svg>
                                    </span>
                                    <span>{{ item.route }}</span>
                                </div>
                                <hr />
                                <h4 class="mt-2 uppercase">{{ $t('Request') }}</h4>
                                <div class="text-[10px] mt-2">
                                    <VCodeBlock 
                                        :code=item.request.python 
                                        highlightjs lang="bash" 
                                        theme="github-dark"/>
                                </div>
                                <h4 class="mt-4 uppercase">{{ $t('Response') }}</h4>
                                <div class="border p-2 rounded-md mt-2">
                                    <div class="flex items-center gap-x-2">
                                        <div class="bg-green-500 h-2 w-2 rounded-full"></div>
                                        <span>200</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </TabPanel>
            <TabPanel>
                <div class="code-block">
                    <div v-for="(item1, index1) in props.apirequests">
                        <h3 class="text-white mb-2 capitalize" :class="index1 != 0 ? 'mt-4' : ''">{{ $t(item1.title) }}</h3>
                        <div v-for="(item, index) in item1.value" @click="changeTab('java' + item1.title + index + 1)" class="mb-2 bg-white cursor-pointer py-1 px-2 rounded">
                            <div class="flex items-center gap-x-2">
                                <span class="rounded-md bg-primary px-2 text-[10px] uppercase">{{ item.method }}</span>
                                <span class="text-black">{{ $t(item.title) }}</span>
                            </div>
                            <div class="text-black mt-2 mb-2" :class="tab === 'java' + item1.title + index + 1 ? '' : 'hidden'">
                                <div class="flex items-center gap-x-2 mb-2">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                            viewBox="0 0 24 24">
                                            <path fill="currentColor"
                                                d="m7.05 11.293l-2.12 2.121a4 4 0 0 0 5.657 5.657l2.828-2.828a4 4 0 0 0 0-5.657l-1.06 1.06a2.5 2.5 0 0 1 0 3.536l-2.83 2.828a2.5 2.5 0 0 1-3.535-3.535l2.12-2.121z" />
                                            <path fill="currentColor"
                                                d="m15.889 11.646l2.121-2.12a2.5 2.5 0 0 0-3.535-3.536l-2.829 2.828a2.5 2.5 0 0 0 0 3.536l-1.06 1.06a4 4 0 0 1 0-5.657l2.828-2.828a4 4 0 0 1 5.657 5.657l-2.121 2.121z" />
                                        </svg>
                                    </span>
                                    <span>{{ item.route }}</span>
                                </div>
                                <hr />
                                <h4 class="mt-2 uppercase">{{ $t('Request') }}</h4>
                                <div class="text-[10px] mt-2">
                                    <VCodeBlock 
                                        :code=item.request.java 
                                        highlightjs lang="bash" 
                                        theme="github-dark"/>
                                </div>
                                <h4 class="mt-4 uppercase">{{ $t('Response') }}</h4>
                                <div class="border p-2 rounded-md mt-2">
                                    <div class="flex items-center gap-x-2">
                                        <div class="bg-green-500 h-2 w-2 rounded-full"></div>
                                        <span>200</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </TabPanel>
            <TabPanel>
                <div class="code-block">
                    <div v-for="(item1, index1) in props.apirequests">
                        <h3 class="text-white mb-2 capitalize" :class="index1 != 0 ? 'mt-4' : ''">{{ $t(item1.title) }}</h3>
                        <div v-for="(item, index) in item1.value" @click="changeTab('ruby' + item1.title + index + 1)" class="mb-2 bg-white cursor-pointer py-1 px-2 rounded">
                            <div class="flex items-center gap-x-2">
                                <span class="rounded-md bg-primary px-2 text-[10px] uppercase">{{ item.method }}</span>
                                <span class="text-black">{{ $t(item.title) }}</span>
                            </div>
                            <div class="text-black mt-2 mb-2" :class="tab === 'ruby' + item1.title + index + 1 ? '' : 'hidden'">
                                <div class="flex items-center gap-x-2 mb-2">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"
                                            viewBox="0 0 24 24">
                                            <path fill="currentColor"
                                                d="m7.05 11.293l-2.12 2.121a4 4 0 0 0 5.657 5.657l2.828-2.828a4 4 0 0 0 0-5.657l-1.06 1.06a2.5 2.5 0 0 1 0 3.536l-2.83 2.828a2.5 2.5 0 0 1-3.535-3.535l2.12-2.121z" />
                                            <path fill="currentColor"
                                                d="m15.889 11.646l2.121-2.12a2.5 2.5 0 0 0-3.535-3.536l-2.829 2.828a2.5 2.5 0 0 0 0 3.536l-1.06 1.06a4 4 0 0 1 0-5.657l2.828-2.828a4 4 0 0 1 5.657 5.657l-2.121 2.121z" />
                                        </svg>
                                    </span>
                                    <span>{{ item.route }}</span>
                                </div>
                                <hr />
                                <h4 class="mt-2 uppercase">{{ $t('Request') }}</h4>
                                <div class="text-[10px] mt-2">
                                    <VCodeBlock 
                                        :code=item.request.ruby 
                                        highlightjs lang="bash" 
                                        theme="github-dark"/>
                                </div>
                                <h4 class="mt-4 uppercase">{{ $t('Response') }}</h4>
                                <div class="border p-2 rounded-md mt-2">
                                    <div class="flex items-center gap-x-2">
                                        <div class="bg-green-500 h-2 w-2 rounded-full"></div>
                                        <span>200</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </TabPanel>
        </TabPanels>
    </TabGroup>
</template>
<script setup>
import AppLayout from "./../Layout/App.vue";
import { Link, useForm } from "@inertiajs/vue3";
import TokenTable from '@/Components/Tables/TokenTable.vue';
import { ref } from 'vue';
import { TabGroup, TabList, Tab, TabPanels, TabPanel } from '@headlessui/vue';
import VCodeBlock from '@wdns/vue-code-block';

const props = defineProps({ rows: Object, url: String, apirequests: Object });
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

    form.post('/developer', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onFinish: () => {
            loadIcon.value = false;
        }
    })
}
</script>
<style scoped>
.code-block {
    white-space: pre-wrap;
    /* Preserve line breaks */
    padding: 1rem;
    /* Optional: Add padding */
}
</style>