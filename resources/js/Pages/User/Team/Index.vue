<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Team') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Add edit and delete accounts in your team') }}</span>
                    </p>
                </div>
                <div>
                    <button @click="openModal()" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Invite user') }}</button>
                </div>
            </div>
            <!-- Table Component-->
            <TeamTable :rows="props.rows" @edit="openModal"/>
        </div>

        <Modal :label="label" :isOpen=isOpenFormModal>
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <form @submit.prevent="submitForm()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">
                    <FormInput v-model="form.email" :error="form.errors.email" :name="$t('Email')" :type="'email'" :class="'sm:col-span-6'" :disabled="formMethod === 'put' ? true : false"/>
                    <FormSelect v-model="form.role" :error="form.errors.role" :options="roleOptions" :name="$t('Role')" :class="'sm:col-span-6'" :placeholder="$t('Select Role')"/>
                    <div class="mt-4 flex">
                        <button type="button" @click.self="isOpenFormModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                        <button :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]" :disabled="form.processing">
                            <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { ref } from 'vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import TeamTable from '@/Components/Tables/TeamTable.vue';
    import Modal from '@/Components/Modal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps({ rows: Object, filters: Object });
    const isOpenFormModal = ref(false);
    const formUrl = ref('/team/invite');
    const formMethod = ref('post');
    const label = ref('Invite user');

    const form = useForm({
        email: null,
        role: null,
    });

    const roleOptions = [
        { value: 'manager', label: trans('Manager') },
        { value: 'agent', label: trans('Agent') },
    ]

    const openModal = (key, formData = {}) => {
        label.value = trans('Invite user');
        formUrl.value = '/team/invite';
        formMethod.value = 'post';

        if (key) {
            label.value = trans('Update user');
            formUrl.value = '/team/' + key.id;
            formMethod.value = 'put';
            form.email = key.email;
            form.role = key.role
            isOpenFormModal.value = true;
        } else {
            form.email = null;
            form.role = null;
            isOpenFormModal.value = true;
        }
    }

    const submitForm = () => {
        if(formMethod.value === 'post'){
            form.post(formUrl.value, {
                onFinish: () => {
                    isOpenFormModal.value = false;
                }
            });
        } else {
            form.put(formUrl.value, {
                onFinish: () => {
                    isOpenFormModal.value = false;
                }
            });
        }
    }
</script>