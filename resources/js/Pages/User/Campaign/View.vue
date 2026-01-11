<template>
    <AppLayout>
        <div class="p-4 md:p-8 rounded-[5px] h-full overflow-y-auto">
            <div class="flex justify-between capitalize">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Campaign details') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6">
                        <span class="ml-1 mt-1">{{ $t('Ref') }}: {{ props.campaign.uuid }}</span>
                    </p>
                </div>
                <div class="space-x-2">
                    <a :href="'/campaigns/export/' + props.campaign.uuid" class="rounded-md bg-secondary px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Export as CSV') }}
                    </a>

                    <Link href="/campaigns" class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Back') }}
                    </Link>
                </div>
            </div>

            <div class="md:flex md:space-x-4">
                <div class="md:w-[70%] capitalize">
                    <div class="flex w-[100%] mb-8 rounded-lg">
                        <div class="w-full rounded-tl-lg rounded-bl-lg text-center bg-white py-8 border">
                            <h2 class="text-xl">{{ props.campaign.total_message_count }}</h2>
                            <h4 class="text-sm">{{ $t('Messages') }}</h4>
                        </div>
                        <div class="w-full text-center bg-white py-8 border">
                            <h2 class="text-xl">{{ props.campaign.total_sent_count }}</h2>
                            <h4 class="text-sm">{{ $t('Sent') }}</h4>
                        </div>
                        <div class="w-full text-center bg-white py-8 border">
                            <h2 class="text-xl">{{ props.campaign.total_delivered_count }}</h2>
                            <h4 class="text-sm">{{ $t('Delivered') }}</h4>
                        </div>
                        <div class="w-full bg-white text-center py-8 border">
                            <h2 class="text-xl">{{ props.campaign.total_read_count }}</h2>
                            <h4 class="text-sm">{{ $t('Read') }}</h4>
                        </div>
                        <div class="w-full rounded-tr-lg rounded-br-lg bg-white text-center py-8 border">
                            <h2 class="text-xl">{{ props.campaign.total_failed_count }}</h2>
                            <h4 class="text-sm">{{ $t('Failed') }}</h4>
                        </div>
                    </div>
                    <!-- Table Component-->
                    <CampaignLogTable :rows="props.rows" :filters="props.filters" :uuid="props.campaign.uuid"/>
                </div>
                <div class="md:w-[30%]">
                    <div class="w-full rounded-lg bg-white pt-4 pb-8 border px-4 space-y-1 capitalize">
                        <h2 class="mb-2">{{ $t('Campaign details') }}</h2>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Campaign name') }}</h3>
                            <p>{{ props.campaign?.name }}</p>
                        </div>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Template') }}</h3>
                            <p>{{ props.campaign?.template?.name }}</p>
                        </div>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Recipients') }}</h3>
                            <p>{{ props.campaign.contact_group_id === '0' ? 'All Contacts' : props.campaign?.contact_group?.name }}</p>
                        </div>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Time scheduled') }}</h3>
                            <p>{{ props.campaign.scheduled_at }}</p>
                        </div>
                    </div>

                    <div class="w-full rounded-lg p-5 mt-5 border chat-bg">
                        <WhatsappTemplate :parameters="JSON.parse(props.campaign.metadata)" :placeholder="false" :visible="true"/>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import CampaignLogTable from '@/Components/Tables/CampaignLogTable.vue';
    import WhatsappTemplate from '@/Components/WhatsappTemplate.vue';
    import { Link } from "@inertiajs/vue3";

    const props = defineProps(['campaign', 'rows', 'filters']);
</script>