<template>
    <div class="flex h-screen justify-center">
        <div class="flex justify-center">
            <div class="mt-20">
                <div class="flex justify-center mb-5">
                    <div>
                        <h4 class="text-2xl mb-2 text-center">Swiftchats</h4>
                        <h1 class="text-2xl text-center">{{ $t('Installation Wizard') }}</h1>
                    </div>
                </div>
                <div v-if="props.step === null" class="md:max-w-[40em] p-2">
                    <p class="text-center text-xl mb-4">{{ $t('Welcome to the Swiftchats installation wizard') }}</p>
                    <div class="bg-slate-100 rounded-md p-4">
                        <p class="mb-2">{{ $t('Step 1: You will need the following information before proceeding:') }}</p>
                        <ol class="list-decimal pl-5 text-sm">
                            <li>{{ $t('Database host') }}</li>
                            <li>{{ $t('Database port') }}</li>
                            <li>{{ $t('Database name') }}</li>
                            <li>{{ $t('Database username') }}</li>
                            <li>{{ $t('Database password') }}</li>
                        </ol>
                    </div>
                    <div class="bg-slate-100 rounded-md p-4 mt-2">
                        <p class="mt-4 mb-2">{{ $t('Step 2: You will need to provide the following information to setup your admin account:') }}</p>
                        <ol class="list-decimal pl-5 text-sm">
                            <li>{{ $t('Admin First Name') }}</li>
                            <li>{{ $t('Admin Last Name') }}</li>
                            <li>{{ $t('Admin Email') }}</li>
                        </ol>
                        <div class="mt-4 p-2 bg-slate-200 border-l-[2px] border-red-500">{{ $t('If you need more help you can consult the installation guide') }}</div>
                    </div>
                    <div class="flex justify-center">
                        <Link href="/install/server" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">{{ $t('Next Step') }}</Link>
                    </div>
                </div>
                <div v-if="props.step === 'server'" class="w-[25em] md:w-[30em] p-2">
                    <p class="text-center text-xl mb-4">{{ $t('Server Requirements') }}</p>
                    <div class="bg-slate-100 rounded-md p-4">
                        <p class="mb-2">{{ $t('Checking the server requirements') }}</p>
                        <div class="bg-slate-200 px-2 rounded-md text-sm">
                            <div v-for="(item, index) in props.system.requirements" class="row border-b border-white py-2">
                                <div class="flex items-center w-3/4 gap-x-2">
                                    <span>
                                        <svg v-if="!item" class="text-red-700" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 36 36"><path fill="currentColor" d="M18 2a16 16 0 1 0 16 16A16 16 0 0 0 18 2m8 22.1a1.4 1.4 0 0 1-2 2l-6-6l-6 6.02a1.4 1.4 0 1 1-2-2l6-6.04l-6.17-6.22a1.4 1.4 0 1 1 2-2L18 16.1l6.17-6.17a1.4 1.4 0 1 1 2 2L20 18.08Z" class="clr-i-solid clr-i-solid-path-1"/><path fill="none" d="M0 0h36v36H0z"/></svg>
                                        <svg v-else class="text-green-700" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                    </span>
                                    {{ index }}
                                </div>
                                <div class="w-1/4"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between gap-x-2">
                        <Link href="/install" class="mt-4 rounded-md bg-slate-400 px-3 py-2 text-sm text-white shadow-sm flex items-center gap-x-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" d="M16.62 2.99a1.25 1.25 0 0 0-1.77 0L6.54 11.3a.996.996 0 0 0 0 1.41l8.31 8.31c.49.49 1.28.49 1.77 0s.49-1.28 0-1.77L9.38 12l7.25-7.25c.48-.48.48-1.28-.01-1.76"/></svg>
                            <span>{{ $t('Back') }}</span>
                        </Link>
                        <Link href="/install/folders" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">
                            <span v-if="props.system.status">{{ $t('Next Step') }}</span>
                            <span v-else>{{ $t('Reload') }}</span>
                        </Link>
                    </div>
                </div>
                <div v-if="props.step === 'folders'" class="md:w-[30em] p-2">
                    <p class="text-center text-xl mb-4">{{ $t('Folder Permissions') }}</p>
                    <div class="bg-slate-100 rounded-md p-4">
                        <p class="mb-2">{{ $t('Verifying write and read permissions on folders') }}</p>
                        <div class="bg-slate-200 px-2 rounded-md text-sm">
                            <div v-for="(item, index) in props.folders.permissions" class="row border-b border-white py-2">
                                <div class="flex items-center w-3/4 gap-x-2">
                                    <span>
                                        <svg v-if="!item" class="text-red-700" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 36 36"><path fill="currentColor" d="M18 2a16 16 0 1 0 16 16A16 16 0 0 0 18 2m8 22.1a1.4 1.4 0 0 1-2 2l-6-6l-6 6.02a1.4 1.4 0 1 1-2-2l6-6.04l-6.17-6.22a1.4 1.4 0 1 1 2-2L18 16.1l6.17-6.17a1.4 1.4 0 1 1 2 2L20 18.08Z" class="clr-i-solid clr-i-solid-path-1"/><path fill="none" d="M0 0h36v36H0z"/></svg>
                                        <svg v-else class="text-green-700" xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                    </span>
                                    {{ index }}
                                </div>
                                <div class="w-1/4"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between gap-x-2">
                        <Link href="/install/server" class="mt-4 rounded-md bg-slate-400 px-3 py-2 text-sm text-white shadow-sm flex items-center gap-x-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" d="M16.62 2.99a1.25 1.25 0 0 0-1.77 0L6.54 11.3a.996.996 0 0 0 0 1.41l8.31 8.31c.49.49 1.28.49 1.77 0s.49-1.28 0-1.77L9.38 12l7.25-7.25c.48-.48.48-1.28-.01-1.76"/></svg>
                            <span>{{ $t('Back') }}</span>
                        </Link>
                        <Link href="/install/database" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">
                            <span v-if="props.folders.status">{{ $t('Next Step') }}</span>
                            <span v-else>{{ $t('Reload') }}</span>
                        </Link>
                    </div>
                </div>
                <div v-else-if="props.step === 'database'" class="mt-5 px-4">
                    <form v-if="!isValidPurchaseCode" class="w-80" @submit.prevent="validateCode()">
                        <h4 class="text-center text-xl mb-4">{{ $t('Enter Purchase Code') }}</h4>
                        <div v-if="purchaseCodeError != null" class="mt-4 p-2 bg-slate-200 border-l-[2px] border-red-500 my-4 max-w-[28em] text-sm">{{ purchaseCodeError }}</div>
                        <FormInput v-model="form4.purchase_code" :name="$t('Purchase Code')" :type="'text'" :placeholder="'Purchase code'" :class="'sm:col-span-6 mb-2'"/>
                        <div class="flex justify-between gap-x-2 mt-2">
                            <Link href="/install/folders" class="mt-4 rounded-md bg-slate-400 px-3 py-2 text-sm text-white shadow-sm flex items-center gap-x-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" d="M16.62 2.99a1.25 1.25 0 0 0-1.77 0L6.54 11.3a.996.996 0 0 0 0 1.41l8.31 8.31c.49.49 1.28.49 1.77 0s.49-1.28 0-1.77L9.38 12l7.25-7.25c.48-.48.48-1.28-.01-1.76"/></svg>
                                <span>{{ $t('Back') }}</span>
                            </Link>
                            <button v-if="!isLoading" href="/install/database" type="submit" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">{{ $t('Next Step') }}</button>
                            <button v-else type="button" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            </button>
                        </div>
                    </form>
                    <form v-else @submit.prevent="checkDb()">
                        <h4 class="text-center text-xl mb-4">{{ $t('Setup Database') }}</h4>
                        <div v-if="props.flash?.status?.message" class="mt-4 p-2 bg-slate-200 border-l-[2px] border-red-500 my-4 max-w-[28em] text-sm">{{ props.flash.status.message }}</div>
                        <div class="flex gap-x-4 mb-4">
                            <FormInput v-model="form.host" :name="$t('Host Name')" :error="form.errors.host"  :type="'text'" :placeholder="'localhost'" :class="'w-3/4'"/>
                            <FormInput v-model="form.port" :name="$t('Port')" :error="form.errors.port"  :type="'text'" :placeholder="'3306'" :class="'w-1/4'"/>
                        </div>
                        <FormInput v-model="form.dbname" :name="$t('DB Name')" :error="form.errors.dbname"  :type="'text'" :placeholder="'Database name'" :class="'sm:col-span-6 mb-4'"/>
                        <FormInput v-model="form.dbprefix" :name="$t('DB Prefix')" :error="form.errors.dbprefix"  :type="'text'" :placeholder="'Database prefix'" :class="'sm:col-span-6 mb-4'"/>
                        <FormInput v-model="form.dbuser" :name="$t('DB Username')" :error="form.errors.dbuser"  :type="'text'" :placeholder="'Database username'" :class="'sm:col-span-6 mb-4'"/>
                        <FormInput v-model="form.dbpass" :name="$t('DB Password')" :error="form.errors.dbpass"  :type="'password'" :placeholder="'Database password'" :class="'sm:col-span-6 mb-4'"/>
                        <div class="flex justify-between gap-x-2 mt-6">
                            <Link href="/install/folders" class="mt-4 rounded-md bg-slate-400 px-3 py-2 text-sm text-white shadow-sm flex items-center gap-x-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" d="M16.62 2.99a1.25 1.25 0 0 0-1.77 0L6.54 11.3a.996.996 0 0 0 0 1.41l8.31 8.31c.49.49 1.28.49 1.77 0s.49-1.28 0-1.77L9.38 12l7.25-7.25c.48-.48.48-1.28-.01-1.76"/></svg>
                                <span>{{ $t('Back') }}</span>
                            </Link>
                            <button v-if="!isLoading" href="/install/database" type="submit" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">{{ $t('Next Step') }}</button>
                            <button v-else type="button" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            </button>
                        </div>
                    </form>
                </div>
                <div v-else-if="props.step == 'app'" class="md:w-[30em] p-2">
                    <p class="text-center text-xl mb-4">{{ $t('Project & User Details') }}</p>
                    <form @submit.prevent="setCompanyDetails()">
                        <FormInput v-model="form2.company_name" :name="$t('App Name')" :type="'text'" :error="form2.errors.company_name"  :placeholder="'App name'" :class="'sm:col-span-6 mb-4'"/>
                        <FormInput v-model="form2.url" :name="$t('Project Url')" :type="'url'" :error="form2.errors.url" :placeholder="'https://mytestapp.com'" :class="'sm:col-span-6 mb-8'"/>
                        <hr class="my-4"/>
                        <div class="flex gap-x-4 mb-4">
                            <FormInput v-model="form2.first_name" :name="$t('Admin First Name')" :error="form2.errors.first_name" :type="'text'" :placeholder="'First Name'" :class="'w-1/2'"/>
                            <FormInput v-model="form2.last_name" :name="$t('Admin Last Name')" :error="form2.errors.last_name" :type="'text'" :placeholder="'Last Name'" :class="'w-1/2'"/>
                        </div>
                        <FormInput v-model="form2.email" :name="$t('Admin Email')" :type="'email'" :error="form2.errors.email" :placeholder="'Email'" :class="'sm:col-span-6 mb-4'"/>
                        <FormInput v-model="form2.password" :name="$t('Admin Password')" :error="form2.errors.password" :type="'password'" :placeholder="'Password'" :class="'sm:col-span-6 mb-4'"/>
                        <div class="flex justify-between gap-x-2 mt-6">
                            <Link href="/install/database" class="mt-4 rounded-md bg-slate-400 px-3 py-2 text-sm text-white shadow-sm flex items-center gap-x-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" d="M16.62 2.99a1.25 1.25 0 0 0-1.77 0L6.54 11.3a.996.996 0 0 0 0 1.41l8.31 8.31c.49.49 1.28.49 1.77 0s.49-1.28 0-1.77L9.38 12l7.25-7.25c.48-.48.48-1.28-.01-1.76"/></svg>
                                <span>{{ $t('Back') }}</span>
                            </Link>
                            <button v-if="!isLoading" type="submit" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">{{ $t('Next Step') }}</button>
                            <button v-else type="button" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            </button>
                        </div>
                    </form>
                </div>
                <div v-else-if="props.step == 'migrations'" class="md:w-[30em] p-2">
                    <p class="text-center text-xl mb-4">{{ $t('Run Migrations') }}</p>
                    <div class="bg-slate-200 p-2 border-l-[3px] border-red-800 text-sm">
                        <p>{{ $t('When you click proceed, the database installation process will begin. This might take some time, so please hang tight and avoid closing the page') }}.</p>
                    </div>
                    <form @submit.prevent="runMigration()">
                        <div class="flex justify-between gap-x-2">
                            <Link href="/install/app" class="mt-4 rounded-md bg-slate-400 px-3 py-2 text-sm text-white shadow-sm flex items-center gap-x-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path fill="currentColor" d="M16.62 2.99a1.25 1.25 0 0 0-1.77 0L6.54 11.3a.996.996 0 0 0 0 1.41l8.31 8.31c.49.49 1.28.49 1.77 0s.49-1.28 0-1.77L9.38 12l7.25-7.25c.48-.48.48-1.28-.01-1.76"/></svg>
                                <span>{{ $t('Back') }}</span>
                            </Link>
                            <button v-if="!isLoading" type="submit" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm">{{ $t('Proceed') }}</button>
                            <button v-else type="button" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm flex justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            </button>
                        </div>
                    </form>
                </div>
                <div v-else-if="props.step == 'finish'" class="md:w-[30em] p-2">
                    <p class="text-center text-xl mb-4">{{ $t('Installation Successfull!') }}</p>
                    <div class="bg-green-50 p-2 border-l-[3px] border-green-800 mb-2">
                        <p>{{ $t('The application has been installed successfully!') }}</p>
                    </div>
                    <div class="bg-slate-100 rounded p-4">
                        <div class="bg-slate-200 p-2 rounded-md mt-2">
                            <p class="mb-2">{{ $t('Website Url') }}</p>
                            <div class="text-sm">
                                <div class="flex gap-x-1">
                                    <Link href="/" class="underline">{{ props.path }}</Link>
                                </div>
                            </div>
                        </div>
                        <div class="bg-slate-200 p-2 rounded-md mt-2">
                            <p class="mb-2">{{ $t('Admin Login Details') }}</p>
                            <div class="text-sm">
                                <div class="flex gap-x-1">
                                    <span>{{ $t('Url') }}:</span>
                                    <Link href="/login" class="underline">{{ props.path }}/login</Link>
                                </div>
                                <p>{{ $t('') }}Email: {{ form2.email }}</p>
                                <p class="mb-2">{{ $t('Password') }}: {{ form2.password }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between gap-x-2">
                        <Link href="/" class="mt-4 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm w-full text-center">{{ $t('Proceed To Main Site') }}</Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script setup>
    import { Link, useForm } from "@inertiajs/vue3";
    import { defineProps, ref } from 'vue';
    import FormInput from '@/Components/FormInput.vue';
    import axios from 'axios';

    const props = defineProps(['flash', 'step', 'system', 'folders', 'database', 'user', 'path']);
    const isLoading = ref(false);
    const isValidPurchaseCode = ref(false);
    const purchaseCodeError = ref(null);

    const form = useForm({
        host: props.database?.host,
        port: props.database?.port,
        dbname: props.database?.database,
        dbuser: props.database?.username,
        dbpass: props.database?.password,
        dbprefix: props.database?.prefix,
    })

    const form2 = useForm({
        company_name: props.user?.project_name,
        url: props.user?.project_url,
        first_name: props.user?.first_name,
        last_name: props.user?.last_name,
        email: props.user?.email,
        password: props.user?.password,
    })

    const form3 = useForm({
        migrate: true,
        purchase_code: null
    })

    const form4 = ref({
        purchase_code: null,
    })

    const checkDb = async () => {
        isLoading.value = true;

        form.post('/install/configure-database', {
            preserveScroll: true,
            onFinish: () => {
                isLoading.value = false;
            }
        });
    };  

    const validateCode = async () => {
        isLoading.value = true;
        try {
            const response = await axios.post('https://axis96.com/api/install/51790966/item', {
                purchase_code: form4.value.purchase_code
            });
            
            if (response.status === 200) {
                isValidPurchaseCode.value = true; // Set isValidPurchaseCode to true on success
                purchaseCodeError.value = null;
                form3.purchase_code = form4.value.purchase_code;
            }
        } catch (error) {
            if (error.response) {
                if (error.response.status === 404) {
                    purchaseCodeError.value = error.response.data.message;
                } else {
                    purchaseCodeError.value = error.response.data.message || 'An error occurred';
                }
            } else if (error.request) {
                purchaseCodeError.value = 'Error: No response received';
            } else {
                purchaseCodeError.value = error.message;
            }
            isValidPurchaseCode.value = false; // Optionally handle error case
        } finally {
            isLoading.value = false;
        }
    };

    const setCompanyDetails = async () => {
        isLoading.value = true;
        form2.post('/install/configure-company', {
            preserveScroll: true,
            onFinish: () => {
                isLoading.value = false;
            }
        });
    };

    const runMigration = async () => {
        isLoading.value = true;

        form3.post('/install/migrate', {
            preserveScroll: true,
            onFinish: () => {
                isLoading.value = false;
            }
        });
    };
</script>