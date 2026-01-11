<script setup>
    import axios from 'axios';
    import { ref, watchEffect } from 'vue';
    import { Link, router, useForm, usePage } from '@inertiajs/vue3';
    import Dropdown from '@/Components/Dropdown.vue';
    import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
    import DropdownItem from '@/Components/DropdownItem.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import Modal from '@/Components/Modal.vue';
    import { trans } from 'laravel-vue-i18n';
    
    const props = defineProps(['contact', 'templates', 'contactGroups', 'settings']);
    const contact = ref(props.contact);
    const metadata = (props.contact.metadata !== null && props.contact.metadata !== '') ? JSON.parse(props.contact.metadata) : {};

    watchEffect(() => {
        contact.value = props.contact;
    });
</script>
<template>
    <div class="overflow-y-auto h-screen w-full">
        <div class="py-4 px-4 space-y-1 text-sm">
            <CampaignForm :templates="templates" :contactGroups="contactGroups" :settings="settings"/>
        </div>
    </div>
</template>