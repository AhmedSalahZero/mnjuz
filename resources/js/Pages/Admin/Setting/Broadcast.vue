<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('Broadcast drivers') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure your broadcast drivers for realtime chat notifications') }}</span>
            </p>
        </div>
        <form @submit.prevent="submitForm()">
            <div class="space-y-12">
                <div class="pb-12">
                    <div class="grid gap-6 grid-cols-2 pb-6 md:w-2/3">
                        <FormSelect v-model="form.broadcast_driver" :name="$t('Broadcast driver')" :type="'text'"  :options="methods" :error="form.errors.broadcast_driver" :class="'col-span-2'"/>
                    </div>
                    <!-- المزوّد الافتراضي: تتبعه كل منشأة لم يُختَر لها مزوّد في صفحتها. -->
                    <div v-if="form.broadcast_driver === 'pusher'" class="grid gap-6 grid-cols-2 pb-10 border-b md:w-2/3">
                        <div class="col-span-2">
                            <FormSelect v-model="form.broadcast_provider" :name="$t('Default realtime provider')" :options="providers" :error="form.errors.broadcast_provider"/>
                            <p class="mt-1 text-xs text-slate-500">{{ $t('Organizations set to “Platform default” use this provider. You can choose a provider for each organization on its page.') }}</p>
                        </div>
                    </div>
                    <div v-if="form.broadcast_driver === 'pusher'" class="grid gap-6 grid-cols-2 pt-6 pb-10 border-b md:w-2/3">
                        <h3 class="col-span-2 text-sm font-medium">Pusher</h3>
                        <FormInput v-model="form.pusher_app_id" :name="$t('Pusher app id')" :type="'text'" :placeholder="getValueByKey('pusher_app_id_is_set') ? '•••••••••• ' + $t('Leave blank to keep current value') : ''" :error="form.errors.pusher_app_id" :class="'col-span-1'"/>
                        <FormInput v-model="form.pusher_app_key" :name="$t('Pusher app key')" :type="'text'" :error="form.errors.pusher_app_key" :class="'col-span-1'"/>
                        <FormInput v-model="form.pusher_app_secret" :name="$t('Pusher app secret')" :type="'password'" :placeholder="getValueByKey('pusher_app_secret_is_set') ? '•••••••••• ' + $t('Leave blank to keep current value') : ''" :error="form.errors.pusher_app_secret" :class="'col-span-1'"/>
                        <FormInput v-model="form.pusher_app_cluster" :name="$t('Pusher app cluster')" :type="'text'" :error="form.errors.pusher_app_cluster" :class="'col-span-1'"/>
                    </div>
                    <!-- Reverb: خادمنا. يتكلّم بروتوكول Pusher، فيحتاج عنواناً صريحاً بدل التجميعة. -->
                    <div v-if="form.broadcast_driver === 'pusher'" class="grid gap-6 grid-cols-2 pt-6 pb-10 border-b md:w-2/3">
                        <h3 class="col-span-2 text-sm font-medium">Reverb</h3>
                        <FormInput v-model="form.reverb_app_id" :name="$t('Reverb app id')" :type="'text'" :placeholder="getValueByKey('reverb_app_id_is_set') ? '•••••••••• ' + $t('Leave blank to keep current value') : ''" :error="form.errors.reverb_app_id" :class="'col-span-1'"/>
                        <FormInput v-model="form.reverb_app_key" :name="$t('Reverb app key')" :type="'text'" :error="form.errors.reverb_app_key" :class="'col-span-1'"/>
                        <FormInput v-model="form.reverb_app_secret" :name="$t('Reverb app secret')" :type="'password'" :placeholder="getValueByKey('reverb_app_secret_is_set') ? '•••••••••• ' + $t('Leave blank to keep current value') : ''" :error="form.errors.reverb_app_secret" :class="'col-span-1'"/>
                        <FormInput v-model="form.reverb_host" :name="$t('Reverb host')" :type="'text'" placeholder="reverb.example.com" :error="form.errors.reverb_host" :class="'col-span-1'"/>
                        <FormInput v-model="form.reverb_port" :name="$t('Reverb port')" :type="'number'" :min="1" :max="65535" :error="form.errors.reverb_port" :class="'col-span-1'"/>
                        <FormSelect v-model="form.reverb_scheme" :name="$t('Reverb scheme')" :options="schemes" :error="form.errors.reverb_scheme" :class="'col-span-1'"/>
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

    const isLoading = ref(false);
    const form = useForm({
        broadcast_driver: getValueByKey('broadcast_driver'),
        pusher_app_key: getValueByKey('pusher_app_key'),
        pusher_app_id: getValueByKey('pusher_app_id'),
        pusher_app_secret: getValueByKey('pusher_app_secret'),
        pusher_app_cluster: getValueByKey('pusher_app_cluster'),
        broadcast_provider: getValueByKey('broadcast_provider') || 'pusher',
        reverb_app_id: getValueByKey('reverb_app_id'),
        reverb_app_key: getValueByKey('reverb_app_key'),
        reverb_app_secret: getValueByKey('reverb_app_secret'),
        reverb_host: getValueByKey('reverb_host'),
        reverb_port: getValueByKey('reverb_port') || '443',
        reverb_scheme: getValueByKey('reverb_scheme') || 'https',
    })

    const methods = [
        { label: 'Pusher', value: 'pusher' },
    ]

    const providers = [
        { label: 'Pusher', value: 'pusher' },
        { label: 'Reverb', value: 'reverb' },
    ]

    const schemes = [
        { label: 'https', value: 'https' },
        { label: 'http', value: 'http' },
    ]

    const submitForm = async () => {
        form.put('/admin/settings?type=broadcast', {
            preserveScroll: true,
        });
    };
</script>