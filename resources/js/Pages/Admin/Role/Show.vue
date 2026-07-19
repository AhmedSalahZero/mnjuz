<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="md:flex justify-between hidden">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Create role') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Create roles for administrative users') }}</span>
                    </p>
                </div>
                <div>
                    <Link href="/admin/team/roles" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                </div>
            </div>
            <form @submit.prevent="submitForm()" class="bg-white md:border py-5 px-5 rounded-[0.5rem]">
                <div class="sm:flex border-b py-5">
                    <div class="sm:w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Name') }}</h1>
                    </div>
                    <div class="sm:w-[60%] sm:flex space-x-6">
                        <div class="sm:w-[80%]">
                            <FormInput v-model="form.name" :type="'text'" :error="form.errors.name" :class="'w-full'" :labelClass="'mb-0'"/>
                        </div>
                    </div>
                </div>
                <div class="flex py-5">
                    <div class="hidden sm:block w-[40%] mb-1">
                        <h1 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Permissions') }}</h1>
                        <span class="flex text-xs mt-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                            {{ $t('Choose the appropriate permissions for this role') }}
                        </span>
                    </div>
                    <div class="sm:w-[60%] flex space-x-6">
                        <div class="sm:w-[80%]">
                            <div class="mb-4" v-for="(item, index) in props.modules" :key="index">
                                <label class="text-sm">{{ $t(removeUnderscoreAndCapitalize(item.name)) }}</label>
                                <div class="grid grid-cols-2 sm:grid-cols-3 mt-2">
                                    <div v-for="value in separateValues(item.actions)" :key="value" class="relative flex mb-2">
                                        <div class="flex h-6 items-center mr-2">
                                            <input 
                                                v-model="form.permissions[item.name][value]" 
                                                :id="'permission['+ item.name + '|' + value + ']'" 
                                                :name="'permission['+ item.name + '|' + value + ']'"
                                                type="checkbox" 
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                            >
                                        </div>
                                        <div class="text-sm leading-6">
                                            <label :for="'permission['+ item.name + '|' + value + ']'" class="font-medium text-gray-900">{{ $t(removeUnderscoreAndCapitalize(value)) }}</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
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
    import { onMounted } from 'vue';
    import { Link, useForm, router } from "@inertiajs/vue3";
    import FormInput from '@/Components/FormInput.vue';

    const props = defineProps({ title: String, role: Object, modules: Object, permissions: Object });

    const form = useForm({
        name: props.role?.name,
        permissions: Object.fromEntries(props.modules.map(item => [item.name, {}]))
    })

    const separateValues = (value) => {
        return value.split(',');
    };

    const removeUnderscoreAndCapitalize = (inputString) => {
        const words = inputString.split('_');
        const capitalizedWords = words.map(word => word.charAt(0).toUpperCase() + word.slice(1));

        return capitalizedWords.join(' ');
    };

    const isPermissionChecked = (moduleName, actionName) => {
        const value = props.permissions.some(
            (permission) =>
                permission.module === moduleName &&
                permission.action === actionName &&
                permission.role_id === props.role?.id
        );

        form.permissions[moduleName][actionName] = value;
    };

    const initializeCheckboxValues = () => {
        props.modules.forEach(item => {
            const moduleName = item.name;
            const modulePermissions = props.permissions.filter(permission => permission.module === moduleName);

            modulePermissions.forEach(permission => {
                const actionName = permission.action;
                form.permissions[moduleName][actionName] = true;
            });
        });
    };

    const submitForm = async () => {
        const url = props.role ? window.location.pathname : '/admin/team/roles';

        form[props.role ? 'put' : 'post'](url, {
            preserveScroll: true,
        });
    };

    onMounted(() => {
        initializeCheckboxValues();
    });
</script>