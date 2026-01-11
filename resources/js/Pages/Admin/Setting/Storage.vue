<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('Storage settings') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure your storage location') }}</span>
            </p>
        </div>
        <form @submit.prevent="submitForm()">
            <div class="space-y-12">
                <div class="pb-12">
                    <div class="grid gap-6 grid-cols-2 pb-10 md:w-2/3" :class="form.storage_system === 'aws' ? '' : 'border-b'">
                        <FormSelect v-model="form.storage_system" :name="$t('File system for storage')" :type="'text'"  :options="methods" :error="form.errors.storage_system" :class="'col-span-2'"/>
                    </div>

                    <div v-if="form.storage_system === 'aws'" class="grid gap-6 grid-cols-2 pb-10 border-b md:w-2/3">
                        <FormInput v-model="form.aws.access_key" :name="$t('S3 AWS access key')" :type="'text'" :error="form.errors['aws.access_key']" :class="'col-span-1'"/>
                        <FormInput v-model="form.aws.secret_key" :name="$t('S3 AWS secret access key')" :type="'password'" :error="form.errors['aws.secret_key']" :class="'col-span-1'"/>
                        <FormInput v-model="form.aws.default_region" :name="$t('S3 AWS default region')" :type="'text'" :error="form.errors['aws.default_region']" :class="'col-span-1'"/>
                        <FormInput v-model="form.aws.bucket" :name="$t('S3 AWS bucket')" :type="'text'" :error="form.errors['aws.bucket']" :class="'col-span-1'"/>
                    </div>

                    <div class="mt-6 flex items-center justify-end gap-x-6 md:w-2/3">
                        <button
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]"
                            :disabled="isLoading"
                        >
                            <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./Layout/App.vue";
    import { ref } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';

    const props = defineProps({
        config: {
            type: Object,
            required: true
        }
    });

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    const getAWSSettings = (key) => {
        if(getValueByKey('aws')){
            const AWSConfig = JSON.parse(getValueByKey('aws'));
            
            // Check if mailConfig is not null, is an object, and not an array
            if (AWSConfig !== null && typeof AWSConfig === 'object' && !Array.isArray(AWSConfig)) {
                return AWSConfig[key] ?? null;
            }
        }

        return null;
    }

    const isLoading = ref(false);
    const form = useForm({
        storage_system: getValueByKey('storage_system'),
        aws: {
            access_key: getAWSSettings('access_key') ?? undefined,
            secret_key: getAWSSettings('secret_key') ?? undefined,
            default_region: getAWSSettings('default_region') ?? undefined,
            bucket: getAWSSettings('bucket') ?? undefined,
        }
    })

    const methods = [
        { label: 'Local File Storage', value: 'local' },
        { label: 'AWS S3 Bucket', value: 'aws' },
    ]

    const submitForm = async () => {
        form.put('/admin/settings?type=storage', {
            preserveScroll: true,
        });
    };
</script>