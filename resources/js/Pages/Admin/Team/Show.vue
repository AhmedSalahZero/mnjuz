<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="md:flex justify-between hidden">
                <div>
                    <h1 v-if="props.user === null" class="text-xl mb-1">{{ $t('Create user') }}</h1>
                    <h1 v-else class="text-xl mb-1">{{ $t('Update user') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span v-if="props.user === null" class="ml-1 mt-1">{{ $t('Create administrative user and assign role') }}</span>
                        <span v-else class="ml-1 mt-1">{{ $t('Update administrative user and assign role') }}</span>
                    </p>
                </div>
                <div>
                    <Link href="/admin/team/users" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            <form @submit.prevent="submitForm()" class="bg-white md:border py-5 px-5 rounded-[0.5rem]">
                <div class="sm:flex border-b py-5">
                    <div class="hidden sm:block sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Personally identifiable information') }}</h1>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%] grid gap-x-6 gap-y-4 sm:grid-cols-6">
                            <FormImage v-model="form.avatar" :name="'Avatar'" :error="form.errors.avatar" :label="$t('Upload image')" :imageUrl="props.user?.avatar ? '/media/' + props.user?.avatar : null" :class="'sm:col-span-6'"/>
                            <FormInput v-model="form.first_name" :name="$t('First name')" :error="form.errors.first_name" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.last_name" :name="$t('Last name')" :error="form.errors.last_name" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormInput v-model="form.email" :name="$t('Email')" :error="form.errors.email" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormPhoneInput v-model="form.phone" :name="$t('Phone')" :error="form.errors.phone" :type="'text'" :class="'sm:col-span-3'"/>
                            <FormSelect v-model="form.role" :name="$t('Role')" :error="form.errors.role" :options="roleOptions()" :type="'text'" :class="'sm:col-span-6'"/>
                            <FormInput v-if="!props.user" v-model="form.password" :name="$t('Password')" :error="form.errors.password" :type="'password'" :class="'sm:col-span-3'"/>
                            <FormInput v-if="!props.user" v-model="form.password_confirmation" :name="$t('Confirm password')" :error="form.errors.password_confirmation" :type="'password'" :class="'sm:col-span-3'"/>
                        </div>
                    </div>
                </div>
                <div class="sm:flex py-5">
                    <div class="hidden sm:block w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Address details') }}</h1>
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
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import{ ref } from 'vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import FormImage from '@/Components/FormImage.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormPhoneInput from '@/Components/FormPhoneInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';

    const props = defineProps({ title: String, user: Object, roles: Object });
    const fileUrl = ref(null);

    const getAddressDetail = (value, key) => {
        if(value){
            const address = JSON.parse(value);
            return address?.[key] ?? null;
        } else {
            return null;
        }
    }

    const form = useForm({
        first_name: props.user?.first_name,
        last_name: props.user?.last_name,
        email: props.user?.email,
        phone: props.user?.phone,
        role: props.user?.role?.uuid,
        password: null,
        password_confirmation: null,
        avatar: null,
        street: getAddressDetail(props.user?.address, 'street'),
        city: getAddressDetail(props.user?.address, 'city'),
        state: getAddressDetail(props.user?.address, 'state'),
        zip: getAddressDetail(props.user?.address, 'zip'),
        country: getAddressDetail(props.user?.address, 'country')
    })

    const roleOptions = () => {
        return props.roles.map((option) => ({
            value: option.uuid,
            label: option.name,
        }));
    };

    const submitForm = async () => {
        const url = props.user ? window.location.pathname : '/admin/team/users';

        form[props.user ? 'put' : 'post'](url, {
            preserveScroll: true,
        });
    };
</script>