<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg pt-2 text-sm mb-4 px-4 mb-20">
                        <!--<div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="text-md">
                                    <div class="text-[16px]">{{ $t('Multiple phone numbers') }}</div>
                                    <div class="mb-1 text-slate-500">{{ $t('Allow multiple phone numbers per contact') }}</div>
                                </div>
                                <div class="ml-auto">
                                    <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1" :class="{ 'bg-primary': form2.allow_multiple_phone_numbers != 0}" @click="toggleMultiplePhoneInput()">
                                        <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out" :class="{ 'translate-x-6': form2.allow_multiple_phone_numbers != 0}"></div>
                                    </div>
                                </div>
                            </div>
                        </div>-->
                        <div class="w-full py-2 mb-4 mt-2">
                            <div class="flex w-full">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Contact fields location') }}</h4>
                                    <div class="mb-1 text-slate-500">{{ $t('Place custom fields before or after the address section') }}</div>
                                </div>
                                <div class="ml-auto">
                                    <FormSelect v-model="location" :options="locationOptions" :name="''" :error="form2.errors.location" :class="'col-span-6'" :placeholder="'Select Here'"/>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-4 px-4 mb-20">
                        <div class="w-full py-2 mb-2 mt-2">
                            <div class="flex w-full mb-4">
                                <div class="text-md">
                                    <h4 class="text-[16px]">{{ $t('Custom contact fields') }}</h4>
                                    <span class="flex items-center mt-1 text-slate-500">
                                        {{ $t('Create custom input fields for the contacts section') }}
                                    </span> 
                                </div>
                                <div class="ml-auto">
                                    <button @click="openModal()" class="float-right rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Add fields') }}</button>
                                </div>
                            </div>
                            <div class="w-5/5">
                                <!-- Table Component-->
                                <ContactFieldTable :rows="props.rows" @edit="openModal" @delete="openAlert"/>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <Modal :label="label" :isOpen=isOpenFormModal>
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <form @submit.prevent="submitForm()" class="gap-y-4">
                    <div class="grid grid-cols gap-y-4">
                        <FormInput v-model="form.name" :name="$t('Label')" :error="form.errors.name" :type="'text'" :class="'col-span-6'"/>
                        <FormSelect v-model="form.component" :options="componentOptions" :name="$t('Form component')" :error="form.errors.component" :class="'col-span-6'" :optionClassName="'h-[8em]'" :placeholder="'Select Here'"/>
                        <FormSelect v-if="form.component === 'input'" v-model="form.type" :options="inputTypeOptions" :name="$t('Input type')" :error="form.errors.type" :class="'col-span-6'" :optionClassName="'h-[8em]'" :placeholder="'Select Here'"/>
                        <div v-if="form.component === 'select'" :class="'col-span-6 mt-2'">
                            <div class="flex pb-2">
                                <span class="text-sm">{{ $t('Select options') }}</span>
                                <div class="col-1 ml-auto">
                                    <button class="btn bg-primary text-xs text-white px-2 rounded-md py-1" @click="add">{{ $t('Add option') }}</button>
                                </div>
                            </div>
                            <div class="bg-slate-100 rounded-lg p-2">
                                <draggable tag="div" :list="form.options" class="mt-2 w-full" handle=".handle" item-key="id">
                                    <template #item="{ element, index }">
                                        <div class="flex items-center w-full space-x-4">
                                            <span class="handle">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M9 19.23q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m-6-6q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m-6-6q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36m6 0q-.508 0-.87-.36q-.36-.362-.36-.87t.36-.87t.87-.36t.87.36q.36.362.36.87t-.36.87t-.87.36"/></svg>
                                            </span>

                                            <FormInput v-model="element.value" :name="''" :type="'text'" :class="'w-full py-2'" :required="true"/>

                                            <span v-if="index != 0" @click="removeAt(index)" class="cursor-pointer hover:bg-red-600 hover:text-white p-1 rounded-full">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m20 9l-1.995 11.346A2 2 0 0 1 16.035 22h-8.07a2 2 0 0 1-1.97-1.654L4 9m17-3h-5.625M3 6h5.625m0 0V4a2 2 0 0 1 2-2h2.75a2 2 0 0 1 2 2v2m-6.75 0h6.75"/></svg>
                                            </span>
                                            <span v-else @click="add" class="cursor-pointer hover:bg-primary hover:text-white p-1 rounded-full">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 17h7m5-1h3m0 0h3m-3 0v3m0-3v-3M3 12h11M3 7h11"/></svg>
                                            </span>
                                        </div>
                                    </template>
                                </draggable>
                            </div>
                        </div>
                        <div class="flex space-x-4 py-3" :class="'col-span-6'">
                            <span class="text-sm">{{ $t('Is required') }}</span>
                            <div class="w-12 h-6 flex items-center bg-gray-300 rounded-full p-1" :class="{ 'bg-primary': form.required != 0}" @click="toggleRequiredInput()">
                                <div class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out" :class="{ 'translate-x-6': form.required != 0}"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4 flex">
                        <button type="button" @click.self="isOpenFormModal = false" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                        <button 
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]"
                            :disabled="isLoading"
                        >
                            <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </SettingLayout>
</template>
<script setup>
    import SettingLayout from "./Layout.vue";
    import axios from "axios";
    import { ref, watch } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import { trans } from 'laravel-vue-i18n';
    import draggable from 'vuedraggable';
    import FormModal from '@/Components/FormModalModified.vue';
    import Modal from '@/Components/Modal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import ContactFieldTable from '@/Components/Tables/ContactFieldTable.vue';

    const props = defineProps(['rows', 'filters', 'settings', 'modules']);
    const isOpenFormModal = ref(false);
    const label = ref('Add Contact Field');
    const formUrl = ref('/contact-fields');
    const formMethod = ref('post');
    const config = ref(props.settings.metadata);
    const settings = ref(config.value ? JSON.parse(config.value) : null);
    const isLoading = ref(false);
    const location = ref(settings?.value?.contacts?.location ? settings?.value?.contacts?.location : null);
    let id = 0;

    const form = useForm({
        name: null,
        component: null,
        type: null,
        required: 0,
        options: [
            { value: "", id: 0 },
        ],
    });

    const form2 = useForm({
        location: null,
    });

    const openModal = (key, formData = {}) => {
        label.value = trans('Add contact field');
        formUrl.value = '/contact-fields';
        formMethod.value = 'post';
        
        if (key != null) {
            label.value = trans('Edit contact field');
            formUrl.value = '/contact-fields/' + key;
            formMethod.value = 'put';
            getRow();
        } else {
            id = 0;
            form.name = null;
            form.type = null;
            form.options = [
                { value: "", id: 0 },
            ];
            isOpenFormModal.value = true;
        }
    }

    function getRow() {
        axios.get(formUrl.value).then((response) => {
            const { data } = response;
            console.log(data.item);

            if(data.item.type === 'select'){
                form['name'] = data.item.name,
                form['component'] = data.item.type;
                form['required'] = data.item.required;

                const inputString = data.item.value;
                const transformedArray = inputString.split(', ').map((value, index) => ({
                    id: index,
                    value: value
                }));
                id = transformedArray.length - 1;
                form['options'] = transformedArray;
            } else if(data.item.type === 'input'){
                form['name'] = data.item.name,
                form['component'] = data.item.type;
                form['type'] = data.item.value;
                form['required'] = data.item.required;
            } else {
                form['name'] = data.item.name,
                form['component'] = data.item.type;
                form['required'] = data.item.required;
            }

            isOpenFormModal.value = true;
        })
        .catch((error) => {
            // console.error('Error:', error);
        });
    }

    const formInputs = [
        {
            inputType: 'FormInput',
            name: 'name',
            label: trans('Name'),
            type: 'text',
            className: 'sm:col-span-6',
        },
        {
            inputType: 'FormSelect',
            name: 'type',
            label: trans('Type'),
            options: [
                { value: 'text', label: trans('Text') },
                { value: 'number', label: trans('Number') },
                { value: 'email', label: trans('Email') },
                { value: 'url', label: trans('URL') },
                { value: 'date', label: trans('Date') },
                { value: 'time', label: trans('Time') },
                { value: 'datetime-local', label: trans('Date & Time Local') },
            ],
            className: 'sm:col-span-6',
        },
    ];

    const inputTypeOptions = [
        { label: trans('Text'), value: 'text' },
        { label: trans('Number'), value: 'number' },
        { label: trans('Email'), value: 'email' },
        { label: trans('URL'), value: 'url' },
        { label: trans('Date'), value: 'date' },
        { label: trans('Time'), value: 'time' },
        { label: trans('Date & time'), value: 'datetime-local' },
    ];

    const componentOptions = [
        { label: trans('Input'), value: 'input' },
        { label: trans('Select box'), value: 'select' },
        { label: trans('Text area'), value: 'textarea' },
        { label: trans('Checkbox'), value: 'checkbox' },
    ];

    const locationOptions = [
        { label: trans('Before address'), value: 'before' },
        { label: trans('After address'), value: 'after' },
    ];

    const toggleRequiredInput = () => {
        form.required = form.required === 0 ? 1 : 0;
    };

    const dragging = ref(false);

    const removeAt = (idx) => {
        form.options.splice(idx, 1);
    };

    const add = () => {
        id++;
        form.options.push({ id, value: "" });
    };

    const draggingInfo = () => {
        return dragging.value ? "under drag" : "";
    };

    const submitForm = async () => {
        isLoading.value = true;

        if(formMethod.value == 'post'){
            form.post(formUrl.value, {
                preserveScroll: true,
                onFinish: () => {
                    isLoading.value = false;
                },
                onSuccess: () => {
                    isOpenFormModal.value = false;
                }
            })
        } else {
            form.put(formUrl.value, {
                preserveScroll: true,
                onFinish: () => {
                    isLoading.value = false;
                },
                onSuccess: () => {
                    isOpenFormModal.value = false;
                }
            })
        }
    };

    watch(location, (newValue, oldValue) => {  
        if (newValue !== oldValue) {
            form2.location = location;

            submitForm2();
        }
    });

    const submitForm2 = async () => {
        form2.post('/settings/contacts', {
            preserveScroll: true,
        })
    };
</script>