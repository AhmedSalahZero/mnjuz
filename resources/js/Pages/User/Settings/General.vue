<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center mb-8">
                <form @submit.prevent="submitForm2()" class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-2">
                        <div class="flex px-4 pt-2 pb-4">
                            <div>
                                <h2 class="text-[17px]">{{ $t('Organization details') }}</h2>
                                <span class="flex items-center mt-1">
                                    <svg class="mr-2" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    {{ $t('Update your organization settings') }}
                                </span>
                            </div>
                        </div>
                        <div class="flex space-x-10 border-b w-full px-4 py-6">
                            <div class="w-[40%]">
                                <span class="text-slate-600">{{ $t('Organization name') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Specify the name of your business/organization') }}</span>
                                </div>
                            </div>
                            <div class="w-[60%]">
                                <FormInput v-model="form2.organization_name" :error="form2.errors.organization_name" :name="''" :type="'text'" :class="'col-span-4'"/>
                            </div>
                        </div>
                        <div class="flex space-x-10 w-full px-4 py-6">
                            <div class="w-[40%]">
                                <span class="text-slate-600">{{ $t('Address details') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Specify your physical business address') }}</span>
                                </div>
                            </div>
                            <div class="w-[60%] grid grid-cols-4 gap-x-4 gap-y-4">
                                <FormInput v-model="form2.address" :error="form2.errors.address" :name="$t('Physical address')" :type="'text'" :class="'col-span-4'"/>
                                <FormInput v-model="form2.city" :error="form2.errors.city" :name="$t('City')" :type="'text'" :class="'col-span-2'"/>
                                <FormInput v-model="form2.state" :error="form2.errors.state" :name="$t('State')" :type="'text'" :class="'col-span-2'"/>
                                <FormInput v-model="form2.zip" :error="form2.errors.zip" :name="$t('Zip code')" :type="'text'" :class="'col-span-2'"/>
                                <FormSelect v-model="form2.country" :name="$t('Country')" :type="'text'" :optionClassName="'h-40'" :options="props.countries" :error="form2.errors.country" :class="'col-span-2'"/>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4">
                        <div class="flex items-center px-4 pt-2 pb-4">
                            <div class="w-[60%]">
                                <h2 class="text-[17px]">{{ $t('Timezone') }}</h2>
                                <span class="flex items-center mt-1">
                                    {{ $t('All your data will be processed according to this timezone') }}
                                </span>
                            </div>
                            <div class="w-[40%] ml-auto">
                                <FormSelect v-model="form2.timezone" :name="''" :type="'text'"  :options="props.timezones" :error="form2.errors.timezone" :class="'col-span-2'"/>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm pb-4 mb-4">
                        <div class="px-4 pt-2 pb-4">
                            <h2 class="text-[17px]">{{ $t('Notifications') }}</h2>
                            <span class="flex items-center mt-1">
                                <svg class="mr-2" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                {{ $t('Configure notification settings for new message alerts') }}
                            </span>
                        </div>
                        <div class="flex space-x-10 w-full px-4 py-6" :class="form2.enable_sound_notification === false ? '' : 'border-b'">
                            <div class="w-[40%]">
                                <span class="text-slate-600">{{ $t('Enable notification sound') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Specify the name of your business/organization') }}</span>
                                </div>
                            </div>
                            <div class="w-[60%] flex justify-end">
                                <FormToggleSwitch v-model="form2.enable_sound_notification" />
                            </div>
                        </div>
                        <div v-if="form2.enable_sound_notification === true" class="flex space-x-10 w-full px-4 py-6">
                            <div class="w-[60%]">
                                <span class="text-slate-600">{{ $t('New chat sound') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Notification sound for every new message') }}</span>
                                </div>
                            </div>
                            <div class="w-[40%] grid grid-cols-4 items-center gap-x-4 gap-y-4">
                                <FormSelect v-model="form2.tone" :name="''" :type="'text'" :optionClassName="'h-32'"  :options="props.sounds" @update:modelValue="handleSoundChange" :error="form2.errors.tone" :class="'col-span-4'"/>
                                <div class="col-span-4 flex gap-x-2">
                                    <svg v-if="form2.volume > 0" @click="reduceVolume()" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3.5 13.857v-3.714a2 2 0 0 1 2-2h2.9a1 1 0 0 0 .55-.165l6-3.956a1 1 0 0 1 1.55.835v14.286a1 1 0 0 1-1.55.835l-6-3.956a1 1 0 0 0-.55-.165H5.5a2 2 0 0 1-2-2Z"/><path stroke-linecap="round" d="M20.5 15V9"/></g></svg>
                                    <svg v-else @click="reduceVolume()" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m18 14l2-2m2-2l-2 2m0 0l-2-2m2 2l2 2"/><path d="M2 13.857v-3.714a2 2 0 0 1 2-2h2.9a1 1 0 0 0 .55-.165l6-3.956a1 1 0 0 1 1.55.835v14.286a1 1 0 0 1-1.55.835l-6-3.956a1 1 0 0 0-.55-.165H4a2 2 0 0 1-2-2Z"/></g></svg>
                                    <input type="range" class="w-full" v-model="form2.volume" @change="playSound(form2.tone)" id="volume-slider" min="0" max="1" step="0.01" />
                                    <svg @click="increaseVolume()" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 13.857v-3.714a2 2 0 0 1 2-2h2.9a1 1 0 0 0 .55-.165l6-3.956a1 1 0 0 1 1.55.835v14.286a1 1 0 0 1-1.55.835l-6-3.956a1 1 0 0 0-.55-.165H3a2 2 0 0 1-2-2Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M17.5 7.5S19 9 19 11.5s-1.5 4-1.5 4m3-11S23 7 23 11.5s-2.5 7-2.5 7"/></g></svg>
                                </div>
                                <audio ref="audioPlayer"></audio>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4">
                        <div class="px-4 pt-2 pb-4">
                            <h2 class="text-[17px]">{{ $t('Failed Message Retry Settings (Campaigns)') }}</h2>
                            <span class="flex items-center mt-1">
                                <svg class="mr-2" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                {{ $t('Configure the intervals at which failed campaign messages will be retried.') }}
                            </span>
                        </div>
                        <div class="flex space-x-10 w-full px-4 py-6" :class="form2.enable_campaign_resend === false ? '' : 'border-b'">
                            <div class="w-[80%]">
                                <span class="text-slate-600">{{ $t('Enable campaign resend feature') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('If active, failed messages in campaigns will be retried according to your set intervals') }}</span>
                                </div>
                            </div>
                            <div class="w-[20%] flex justify-end">
                                <FormToggleSwitch v-model="form2.enable_campaign_resend" />
                            </div>
                        </div>
                        <div v-if="form2.enable_campaign_resend === true" class="flex space-x-10 w-full px-4 py-6 border-b">
                            <div class="w-[60%]">
                                <span class="text-slate-600">{{ $t('Retry Intervals (in hours)') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Set retry intervals in hours') }}</span>
                                </div>
                            </div>
                            <div class="w-[40%] justify-end">
                                <div class="flex space-x-2">
                                    <FormInput v-for="(interval, index) in form2.resend_intervals" v-model.number="form2.resend_intervals[index]" :error="''" :name="''" :type="'number'" :min="'1'" :class="'w-24'"/>
                                </div>
                            </div>
                        </div>
                        <div v-if="form2.enable_campaign_resend === true" class="flex space-x-10 w-full px-4 py-6" :class="form2.move_failed_contacts_to_group === false ? '' : 'border-b'">
                            <div class="w-[80%]">
                                <span class="text-slate-600">{{ $t('Move failed contacts to a group') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('If enabled, failed contacts will be moved to a selected group after all retry attempts.') }}</span>
                                </div>
                            </div>
                            <div class="w-[20%] flex justify-end">
                                <FormToggleSwitch v-model="form2.move_failed_contacts_to_group" />
                            </div>
                        </div>
                        <div v-if="form2.enable_campaign_resend === true && form2.move_failed_contacts_to_group === true" class="flex space-x-10 w-full px-4 py-6">
                            <div class="w-[60%]">
                                <span class="text-slate-600">{{ $t('Failed contacts groups') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('After the three retry attempts, contacts will be moved to this group.') }}</span>
                                </div>
                            </div>
                            <div class="w-[40%] ml-auto">
                                <FormSelect v-model="form2.failed_campaign_group" :name="''" :error="form2.errors.failed_campaign_group" :options="contactGroupOptions()" :type="'text'" :class="'sm:col-span-6'"/>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm pb-4 mb-20">
                        <div class="flex px-4 pt-1 pb-2">
                            <div class="ml-auto">
                                <button type="submit" class="float-right rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:shadow-md hover:bg-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" :disabled="form2.processing">
                                    <svg v-if="form2.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                    <span v-else>{{ $t('Save') }}</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </SettingLayout>
</template>
<script setup>
    import SettingLayout from "./Layout.vue";
    import { ref } from 'vue';
    import EmbeddedSignupBtn from '@/Components/EmbeddedSignupBtn.vue';
    import FormModal from '@/Components/FormModal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';
    import Modal from '@/Components/Modal.vue';
    import { trans } from 'laravel-vue-i18n';
    import { router, useForm } from "@inertiajs/vue3";

    const props = defineProps(['contactGroups', 'settings', 'timezones', 'modules', 'organization', 'countries', 'sounds']);
    const statusView = ref(false);
    const config = ref(props.settings.metadata);
    const settings = ref(config.value ? JSON.parse(config.value) : null);
    const audioPlayer = ref(null);

    const reduceVolume = () => {
        if (form2.volume > 0) {
            form2.volume = Math.max(0, parseFloat(form2.volume) - 0.1);
            playSound(form2.tone);
        }
    };

    const increaseVolume = () => {
        if (form2.volume < 1) {
            form2.volume = Math.min(1, parseFloat(form2.volume) + 0.1);
            playSound(form2.tone);
        }
    };

    const handleSoundChange = (newValue) => {
        playSound(newValue);
    };

    const playSound = (fileUrl) => {
        audioPlayer.value.src = fileUrl;
        audioPlayer.value.volume = form2.volume;
        audioPlayer.value.play();
    };

    const contactGroupOptions = () => {
        return props.contactGroups.map((option) => ({
            value: option.uuid,
            label: option.name,
        }));
    };

    const getAddressDetail = (value, key) => {
        if(value){
            const address = JSON.parse(value);
            return address?.[key] ?? null;
        } else {
            return null;
        }
    };

    const form2 = useForm({
        organization_name: props.settings?.name,
        address: getAddressDetail(props.settings?.address, 'street'),
        city: getAddressDetail(props.settings?.address, 'city'),
        state: getAddressDetail(props.settings?.address, 'state'),
        zip: getAddressDetail(props.settings?.address, 'zip'),
        country: getAddressDetail(props.settings?.address, 'country'),
        timezone:  settings.value && settings.value.timezone ? settings.value.timezone : 'UTC',
        enable_sound_notification: settings.value && settings.value.notifications ? settings.value.notifications.enable_sound : false,
        volume: settings.value && settings.value.notifications ? settings.value.notifications.volume : 1,
        tone: settings.value && settings.value.notifications ? settings.value.notifications.tone : null,
        enable_campaign_resend: settings.value && settings.value.campaigns?.enable_resend ? settings.value.campaigns?.enable_resend : false,
        move_failed_contacts_to_group: settings.value && settings.value?.campaigns?.move_failed_contacts_to_group ? settings.value?.campaigns?.move_failed_contacts_to_group : false,
        resend_intervals: settings?.value?.campaigns?.resend_intervals ?? [1, 3, 6],
        failed_campaign_group: settings.value && settings.value?.campaigns?.failed_campaign_group ? settings.value.campaigns?.failed_campaign_group : null,
    });

    const capitalizeString = (string) => {
        return string.charAt(0).toUpperCase() + string.slice(1).toLowerCase();
    };

    const submitForm = () => {
        form.post('/settings/whatsapp', {
            preserveScroll: true,
            preserveState: false,
        })
    }

    const submitForm2 = () => {
        form2.put('./profile/organization', {
            preserveScroll: true
        });
    }
</script>