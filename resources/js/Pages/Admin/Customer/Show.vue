<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-scroll">
            <div v-if="props.user === null" class="md:flex justify-between hidden">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Create user') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Create user') }}</span>
                    </p>
                </div>
                <div>
                    <Link href="/admin/users" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            <div v-if="props.user" class="flex justify-between">
                <div class="flex items-center space-x-2 mb-8 mt-8 md:mt-0">
                    <div class="rounded-full p-1">
                        <img v-if="user.avatar" class="rounded-full w-40 h-40" :src="user.avatar">
                        <div v-else class="rounded-full w-32 h-32 bg-slate-200 flex justify-center items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24"><g fill="none"><path d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z"/><path fill="currentColor" d="M12 13c2.396 0 4.575.694 6.178 1.672c.8.488 1.484 1.064 1.978 1.69c.486.615.844 1.351.844 2.138c0 .845-.411 1.511-1.003 1.986c-.56.45-1.299.748-2.084.956c-1.578.417-3.684.558-5.913.558s-4.335-.14-5.913-.558c-.785-.208-1.524-.506-2.084-.956C3.41 20.01 3 19.345 3 18.5c0-.787.358-1.523.844-2.139c.494-.625 1.177-1.2 1.978-1.69C7.425 13.695 9.605 13 12 13Zm0-11a5 5 0 1 1 0 10a5 5 0 0 1 0-10Z"/></g></svg>
                        </div>
                    </div>
                    <div>
                        <h1 class="text-lg">{{ props.user.full_name }}</h1>
                        <h2 class="text-normal">{{ props.user.email }}</h2>
                    </div>
                </div>
                <div>
                    <Link href="/admin/users" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            <div v-if="props.user" class="flex border-b-2 space-x-4 text-sm text-slate-700">
                <div @click="changeTab('user')" class="cursor-pointer px-2 py-2 rounded-tl-lg rounded-tr-lg" :class="tab === 'user' ? 'bg-white border' : ''">{{ $t('User details') }}</div>
                <div @click="changeTab('organization')" class="cursor-pointer px-2 py-2 rounded-tl-lg rounded-tr-lg" :class="tab === 'organization' ? 'bg-white border' : ''">{{ $t('Organization details') }}</div>
            </div>
            <div v-if="props.user && tab === 'organization'" class="pt-5">
                <OrganizationTable :rows="props.organizations" :filters="props.filters"/>
            </div>
            <form v-if="tab === 'user'" @submit.prevent="submitForm()" class="bg-white py-5 px-5 rounded-bl-[0.5rem] rounded-br-[0.5rem]">
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h3 class="text-sm tracking-[0px]">{{ $t('User details') }}</h3>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormImage v-model="form.avatar" :name="$t('Avatar')" :error="form.errors.avatar" :label="$t('Upload image')" :imageUrl="props.user?.avatar ? '/media/' + props.user?.avatar : null" :class="'sm:col-span-6'"/>
                            <FormInput v-model="form.first_name" :name="$t('First name')" :error="form.errors.first_name" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.last_name" :name="$t('Last name')" :error="form.errors.last_name" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.email" :name="$t('Email')" :error="form.errors.email" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormPhoneInput v-model="form.phone" :name="$t('Phone')" :error="form.errors.phone" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-if="!props.user" v-model="form.password" :name="$t('Password')" :error="form.errors.password" :type="'password'" :class="'sm:col-span-3'"/>
                            <FormInput v-if="!props.user" v-model="form.password_confirmation" :name="$t('Confirm password')" :error="form.errors.password_confirmation" :type="'password'" :class="'sm:col-span-3'"/>
                        </div>
                    </div>
                </div>
                <div v-if="!props.user" class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h3 class="text-sm tracking-[0px]">{{ $t('Organization') }}</h3>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormCheckbox  @input="toggleCreateOrganization" v-model="create_organization" :name="$t('Create organization')" :label="$t('Create organization')" :value="'organization'" :type="'checkbox'" :class="'sm:col-span-3'"/>
                            <FormInput v-if="create_organization" v-model="form.organization_name" :name="$t('Organization name')" :error="form.errors.organization_name" :type="'text'" :class="'sm:col-span-6'"/>
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
                    <button type="submit" :disabled="form.processing" class="float-right flex items-center space-x-4 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-slate-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import{ ref } from 'vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import FormCheckbox from '@/Components/FormCheckbox.vue';
    import FormImage from '@/Components/FormImage.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormPhoneInput from '@/Components/FormPhoneInput.vue';
    import OrganizationTable from '@/Components/Tables/OrganizationTable.vue';

    const props = defineProps({ title: String, user: Object, roles: Object, organizations: Object, filters: Object });
    const create_organization = ref(false);
    const tab = ref('user');

    const getAddressDetail = (value, key) => {
        if(value){
            const address = JSON.parse(value);
            return address?.[key] ?? null;
        } else {
            return null;
        }
    };

    const toggleCreateOrganization = () => {
        if(!create_organization.value){
            form.organization_name = null;
        } else {
            form.organization_name = undefined;
        }
    }

    const form = useForm({
        first_name: props.user?.first_name,
        last_name: props.user?.last_name,
        email: props.user?.email,
        phone: props.user?.phone,
        role: props.user?.role?.uuid,
        avatar: undefined,
        street: getAddressDetail(props.user?.address, 'street'),
        city: getAddressDetail(props.user?.address, 'city'),
        state: getAddressDetail(props.user?.address, 'state'),
        zip: getAddressDetail(props.user?.address, 'zip'),
        country: getAddressDetail(props.user?.address, 'country'),
        ...(props.user ? {} : { password: null, password_confirmation: null }),
        organization_name: undefined,
    });

    const changeTab = (value) => {
        tab.value = value;
    }

    const submitForm = async () => {
        const url = props.user ? window.location.pathname : '/admin/users';

        form[props.user ? 'put' : 'post'](url, {
            preserveScroll: true,
        });
    };
</script>