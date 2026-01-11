<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full overflow-y-scroll">
            <div v-if="props.organization === null" class="md:flex justify-between hidden">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Create organization') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Create organization') }}</span>
                    </p>
                </div>
                <div>
                    <Link href="/admin/organizations" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            <div v-if="props.organization" class="flex justify-between">
                <div class="flex items-center space-x-2 mb-8 mt-8 md:mt-0">
                    <div class="rounded-full p-1">
                        <div class="rounded-full w-32 h-32 bg-slate-200 flex justify-center items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24"><g fill="none" fill-rule="evenodd"><path d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z"/><path fill="currentColor" d="M17 3.722v5.497l2.864.716A1.5 1.5 0 0 1 21 11.39V19a1 1 0 1 1 0 2H3a1 1 0 1 1 0-2v-7.69a1.5 1.5 0 0 1 .83-1.343L7 8.382V6.347a1.5 1.5 0 0 1 .973-1.405l7-2.625A1.5 1.5 0 0 1 17 3.722Zm-2 .721l-6 2.25V19h6V4.443Zm2 6.838V19h2v-7.22l-2-.5Zm-10-.663l-2 1V19h2v-8.382Z"/></g></svg>
                        </div>
                    </div>
                    <div>
                        <h1 class="text-lg">{{ props.organization.name }}</h1>
                        <h2 class="text-sm">{{ $t('Subscription plan') }}: {{ props.organization?.subscription?.plan?.name ?? 'Not set' }} <span v-if="props.organization?.subscription?.status === 'trial'" class="bg-[#000] text-white text-xs py-1 px-2 rounded-md">{{ $t('Trial period') }}</span></h2>
                        <h2 class="text-sm capitalize">{{ $t('Valid until') }}: {{ props.organization?.subscription?.valid_until ?? 'Not set' }}</h2>
                    </div>
                </div>
                <div class="flex space-x-4">
                    <div>
                        <button type="button" @click="toggleFormModal()" class="rounded-md bg-indigo-600 px-3 h-9 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Create transaction') }}</button>
                    </div>
                    <Link href="/admin/organizations" class="rounded-md h-9 bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            <div v-if="props.organization" class="flex border-b space-x-4 text-sm">
                <div @click="changeTab('organization')" class="cursor-pointer px-2 py-2" :class="tab === 'organization' ? 'bg-white' : ''">{{ $t('User details') }}</div>
                <div @click="changeTab('team')" class="cursor-pointer px-2 py-2" :class="tab === 'team' ? 'bg-white' : ''">{{ $t('Team') }}</div>
                <div @click="changeTab('billing')" class="cursor-pointer px-2 py-2" :class="tab === 'billing' ? 'bg-white' : ''">{{ $t('Billing history') }}</div>
            </div>
            <div v-if="props.organization && tab === 'team'" class="pt-5">
                <UserTable :rows="props.users" :filters="props.filters" :type="'user'" :showRole="true" :showDeleteBtn="false"/>
            </div>
            <div v-if="props.organization && tab === 'billing'" class="pt-5">
                <BillingTable :rows="props.invoices" :filters="props.filters" :uuid="props.organization.uuid"/>
            </div>
            <form v-if="tab === 'organization'" @submit.prevent="submitForm()" class="bg-white py-5 px-5 rounded-bl-[0.5rem] rounded-br-[0.5rem]">
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h3 class="text-sm tracking-[0px]">{{ $t('Organization details') }}</h3>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormInput v-model="form.name" :name="$t('Name')" :error="form.errors.name" :type="'text'" :class="'sm:col-span-6'"/>
                            <FormSelect v-model="form.plan" :name="$t('Subscription plan')" :error="form.errors.plan" :options="roleOptions()" :type="'text'" :class="'sm:col-span-6'"/>
                        </div>
                    </div>
                </div>
                <div v-if="props.organization === null" class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h3 class="text-sm tracking-[0px]">{{ $t('User details') }}</h3>
                        <p class="text-sm text-gray-500">{{ $t('Enter the details of the main administrative user of this organization') }}</p>
                    </div>
                    <div class="sm:w-[60%]">
                        <div class="sm:w-[80%] flex justify-between bg-primary rounded-[5px] p-1 space-x-2 mb-4 text-white">
                            <button type="button" class="w-[50%] rounded-[5px] px-1 py-2 text-sm" :class="{ 'bg-white text-black': form.create_user === 1 }" @click="switchUserType(1)">{{ $t('Add user') }}</button>
                            <button type="button" class="w-[50%] rounded-[5px] px-1 py-2 text-sm" :class="{ 'bg-white text-black': form.create_user === 0 }" @click="switchUserType(0)">{{ $t('Select existing user') }}</button>
                        </div>
                        <div v-if="form.create_user === 1" class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormInput v-model="form.first_name" :name="$t('First name')" :error="form.errors.first_name" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.last_name" :name="$t('Last name')" :error="form.errors.last_name" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.email" :name="$t('Email')" :error="form.errors.email" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormPhoneInput v-model="form.phone" :name="$t('Phone')" :error="form.errors.phone" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.password" :name="$t('Password')" :error="form.errors.password" :type="'password'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.password_confirmation" :name="$t('Confirm password')" :error="form.errors.password_confirmation" :type="'password'" :class="'sm:col-span-3'"/>
                        </div>
                        <div v-else class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormInput v-model="form.email" :name="$t('Email')" :error="form.errors.email" :type="'text'" :class="'sm:col-span-6'"/>
                        </div>
                    </div>
                </div>
                <div class="sm:flex py-5">
                    <div class="hidden sm:block w-[40%] mb-1">
                        <h3 class="text-sm tracking-[0px]">{{ $t('Address details') }}</h3>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormInput v-model="form.street" :name="$t('Street')" :error="form.errors.street" :type="'text'" :class="'sm:col-span-6'"/>
                            <FormInput v-model="form.city" :name="$t('City')" :error="form.errors.city" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.state" :name="$t('State')" :error="form.errors.state" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.zip" :name="$t('Zip code')" :error="form.errors.zip" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.country" :name="$t('Country')" :error="form.errors.country" :type="'text'" :class="'sm:col-span-3'"/>
                        </div>
                    </div>
                </div>
                <div class="py-6">
                    <button type="submit" class="float-right flex items-center space-x-4 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-slate-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
    <Modal :label="$t('Create transaction')" :isOpen=isOpenFormModal>
        <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
            <form @submit.prevent="submitForm1()" class="">

                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-6 sm:col-span-4">
                    <FormSelect v-model="form1.type" :name="$t('Transaction type')" :error="form1.errors.type" :options="typeOptions" :class="'sm:col-span-3'"/>
                    <FormInput v-model="form1.amount" :name="$t('Amount')" :error="form1.errors.amount" :type="'number'" :class="'sm:col-span-3'"/>
                    <FormSelect v-if="form1.type === 'payment'" v-model="form1.method" :name="$t('Payment method')" :error="form1.errors.method" :options="paymentOptions" :class="'sm:col-span-6'"/>
                    <FormInput v-else v-model="form1.description" :name="$t('Description')" :error="form1.errors.description" :type="'text'" :class="'sm:col-span-6'"/>
                </div>

                <div class="bg-red-800 py-1 px-2 rounded-[5px] mt-6">
                    <p class="text-[12px] flex items-center space-x-2 text-white">
                        <svg class="text-white" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path fill="currentColor" fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625zM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5m0 9a1 1 0 1 0 0-2a1 1 0 0 0 0 2" clip-rule="evenodd"/></svg>
                        <span>{{ $t('You can\'t undo this transaction once you save it') }}</span>
                    </p>
                </div>

                <div class="mt-6 flex">
                    <button type="button" @click="toggleFormModal()" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                    <button 
                        :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]"
                        :disabled="isLoading">
                        <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import{ ref } from 'vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';
    import FormPhoneInput from '@/Components/FormPhoneInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import BillingTable from '@/Components/Tables/BillingTable.vue';
    import Modal from '@/Components/Modal.vue';
    import UserTable from '@/Components/Tables/UserTable.vue';
    import { trans } from 'laravel-vue-i18n';

    const props = defineProps({ 
        showAddBtn: {
            type: Boolean,
            default: true
        }, 
        title: String, 
        organization: Object, 
        users: Object, 
        invoices: Object, 
        plans: Object, 
        filters: Object, 
        mode: String 
    });

    const tab = ref('organization');

    const getAddressDetail = (value, key) => {
        if(value){
            const address = JSON.parse(value);
            return address?.[key] ?? null;
        } else {
            return null;
        }
    };

    const form = useForm({
        name: props.organization?.name,
        plan: props.organization?.subscription?.plan?.uuid,
        create_user: 1,
        first_name: null,
        last_name: null,
        email: null,
        phone: null,
        password: null, 
        password_confirmation: null,
        street: getAddressDetail(props.organization?.address, 'street'),
        city: getAddressDetail(props.organization?.address, 'city'),
        state: getAddressDetail(props.organization?.address, 'state'),
        zip: getAddressDetail(props.organization?.address, 'zip'),
        country: getAddressDetail(props.organization?.address, 'country')
    });

    const typeOptions = ref([
        { value: 'credit', label: trans('Credit') },
        { value: 'debit', label: trans('Debit') },
        { value: 'payment', label: trans('Payment') },
    ])

    const paymentOptions = ref([
        { value: 'manual', label: trans('Manual') },
        { value: 'bank', label: trans('Bank') },
    ])

    const form1 = useForm({
        uuid: props.organization?.uuid,
        type: null,
        amount: null,
        method: null,
        description: null,
    })

    const roleOptions = () => {
        return props.plans.map((option) => ({
            value: option.uuid,
            label: option.name,
        }));
    };

    const isOpenFormModal = ref(false);
    
    const isLoading = ref(false);

    const changeTab = (value) => {
        tab.value = value;
    }

    const switchUserType = (value) => {
        form.create_user = value;
        if(value === 0){
            form.first_name = null;
            form.last_name = null;
            form.email = null;
            form.phone = null;
            form.password = null;
            form.password_confirmation = null;
        } else {
            form.email = null;
        }
    }

    const submitForm = async () => {
        const url = props.organization ? window.location.pathname : '/admin/organizations';

        form[props.organization ? 'put' : 'post'](url, {
            preserveScroll: true,
        });
    };

    const toggleFormModal = () => {
        isOpenFormModal.value = !isOpenFormModal.value;
    }

    const submitForm1 = async () => {
        form1.post('/admin/billing', {
            preserveScroll: true,
            onSuccess: () => {
                toggleFormModal()
                changeTab('billing')
            },
        })
    };
</script>