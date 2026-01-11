<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm pb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-2 mt-2">
                            <div class="flex w-full mb-4">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Basic Replies') }}</h4>
                                    <span class="flex items-center mt-1 text-slate-500">
                                        {{ $t('Respond automatically to messages based on your own criteria') }}
                                    </span> 
                                </div>
                                <div class="ml-auto">
                                    <Link href="/automation/basic/create" class="float-right rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Create') }}</Link>
                                </div>
                            </div>
                            <div class="w-5/5">
                                <!-- Table Component-->
                                <AutoReplyTable :rows="props.rows" :filters="props.filters"/>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SettingLayout>
</template>
<script setup>
    import SettingLayout from "./../Layout.vue";
    import { Link } from "@inertiajs/vue3";
    import AutoReplyTable from '@/Components/Tables/AutoReplyTable.vue';

    const props = defineProps(['rows', 'filters', 'aimodule', 'fbmodule']);
</script>