<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('Mailer settings') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure email accounts') }}</span>
            </p>
        </div>
        <form @submit.prevent="submitForm()">
            <div class="space-y-12">
                <div class="pb-12">
                    <div class="pb-10">
                        <div class="grid gap-6 grid-cols-2 pb-6 md:w-2/3">
                            <FormSelect v-model="form.mail_config.driver" :name="$t('Method')" :type="'text'"  :options="methods" :class="'col-span-2'"/>
                        </div>

                        <div v-if="form.mail_config.driver === 'mailgun'" class="grid gap-6 grid-cols-2 pb-10 md:w-2/3">
                            <FormInput v-model="form.mail_config.mg_domain" :error="form.errors['mail_config.mg_domain']" :name="$t('Mailgun domain')" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.mg_secret" :error="form.errors['mail_config.mg_secret']" :name="$t('Mailgun secret')" :type="'text'" :class="'col-span-1'"/>
                        </div>

                        <div v-else-if="form.mail_config.driver === 'ses'" class="grid gap-6 grid-cols-2 pb-6 md:w-2/3">
                            <FormInput v-model="form.mail_config.ses_key" :error="form.errors['mail_config.ses_key']" :name="$t('AWS access key id')" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.ses_secret" :error="form.errors['mail_config.ses_secret']" :name="$t('AWS secret access key')" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.ses_region" :error="form.errors['mail_config.ses_region']" :name="$t('AWS default region')" :type="'text'" :class="'col-span-2'"/>
                        </div>

                        <div v-else-if="form.mail_config.driver === 'smtp'" class="grid gap-6 grid-cols-2 pb-6 md:w-2/3">
                            <FormInput v-model="form.mail_config.host" :error="form.errors['mail_config.host']" :name="$t('Host')" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.port" :error="form.errors['mail_config.port']" :name="$t('Port')" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.username" :error="form.errors['mail_config.username']" :name="$t('Username')" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.password" :error="form.errors['mail_config.password']" :name="$t('Password')" :type="'password'" :class="'col-span-1'"/>
                        </div>

                        <div class="grid gap-6 grid-cols-2 pb-3 md:w-2/3">
                            <FormInput v-model="form.mail_config.from_name" :name="$t('Mail from name')" :error="form.errors['mail_config.from_name']" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.from_address" :name="$t('Mail from address')" :error="form.errors['mail_config.from_address']" :type="'email'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.reply_to_name" :name="$t('Reply to name')" :error="form.errors['mail_config.reply_to_name']" :type="'text'" :class="'col-span-1'"/>
                            <FormInput v-model="form.mail_config.reply_to_address" :name="$t('Reply to address')" :error="form.errors['mail_config.reply_to_address']" :type="'email'" :class="'col-span-1'"/>
                        </div>

                        <div class="grid grid-cols-2 pb-6 border-b md:w-2/3">
                            <div class="relative flex gap-x-3 mt-4 col-span-2">
                                <div class="flex items-center">
                                    <label @click="toggleSmtp()" for="myCheckbox" class="cursor-pointer">
                                        <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center" :class="form.smtp_email_active ? 'bg-[#000]' : ''">
                                            <svg v-if="form.smtp_email_active" class="w-4 h-4" :class="form.smtp_email_active ? 'text-white' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                    </label>
                                    <span @click="toggleSmtp()" class="ml-2 text-[14px] cursor-pointer">{{ $t('Activate email') }}</span>
                                </div>
                            </div>
                            <div class="relative flex gap-x-3 mt-4 col-span-2">
                                <div class="flex items-center">
                                    <label @click="toggleVerification()" for="myCheckbox" class="cursor-pointer">
                                        <div class="w-4 h-4 border border-gray-400 rounded-md flex items-center justify-center" :class="{ 'bg-[#000]': form.verify_email }">
                                            <svg v-if="form.verify_email" class="w-4 h-4" :class="form.verify_email ? 'text-white' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                    </label>
                                    <span @click="toggleVerification()" class="ml-2 text-[14px] cursor-pointer">{{ $t('Require email verification for new accounts') }}</span>
                                </div>
                            </div>
                            <!--<div class="mt-6">
                                <button :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]">
                                    <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                    <span v-else>{{ $t('Send test email') }}</span>
                                </button>
                            </div>-->
                        </div>

                        <div class="mt-1 flex items-center justify-end gap-x-6 md:w-2/3 pt-2">
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

    const getMailboxSettings = (key) => {
        const mailConfig = JSON.parse(getValueByKey('mail_config'));
        
        // Check if mailConfig is not null, is an object, and not an array
        if (mailConfig !== null && typeof mailConfig === 'object' && !Array.isArray(mailConfig)) {
            return mailConfig[key] ?? null;
        }

        return null;
    }

    const isLoading = ref(false);
    const form = useForm({
        mail_config: {
            driver: getMailboxSettings('driver') ?? undefined,
            from_address: getMailboxSettings('from_address') ?? undefined,
            from_name: getMailboxSettings('from_name') ?? undefined,
            reply_to_name: getMailboxSettings('reply_to_name') ?? undefined,
            reply_to_address: getMailboxSettings('reply_to_address') ?? undefined,
            mg_domain: getMailboxSettings('mg_domain') ?? undefined,
            mg_secret: getMailboxSettings('mg_secret') ?? undefined,
            ses_key: getMailboxSettings('ses_key') ?? undefined,
            ses_secret: getMailboxSettings('ses_secret') ?? undefined,
            ses_region: getMailboxSettings('ses_region') ?? undefined,
            port: getMailboxSettings('port') ?? undefined,
            host: getMailboxSettings('host') ?? undefined,
            username: getMailboxSettings('username') ?? undefined,
            password: getMailboxSettings('password') ?? undefined,
            mail_config: getMailboxSettings('password') ?? undefined,
        },
        smtp_email_active: getValueByKey('smtp_email_active') === '1' ? true : false,
        verify_email: getValueByKey('verify_email') === '1' ? true : false,
    })

    const methods = [
        { label: 'SMTP', value: 'smtp' },
        { label: 'Mailgun', value: 'mailgun' },
        { label: 'Amazon SES', value: 'ses' },
    ]

    const toggleSmtp = () => {
        form.smtp_email_active = !form.smtp_email_active;
    };

    const toggleVerification = () => {
        form.verify_email = !form.verify_email;
    };

    const submitForm = async () => {
        form.put('/admin/settings?type=email', {
            preserveScroll: true,
        })
    };
</script>