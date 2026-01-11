<template>
    <div v-if="isPaymentLoading" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded-lg shadow-lg text-center text-sm">
            <div class="flex justify-center mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 24 24"><path fill="black" d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,20a9,9,0,1,1,9-9A9,9,0,0,1,12,21Z" transform="matrix(0 0 0 0 12 12)"><animateTransform id="svgSpinnersPulseRingsMultiple0" attributeName="transform" begin="0;svgSpinnersPulseRingsMultiple2.end" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="translate" values="12 12;0 0"/><animateTransform additive="sum" attributeName="transform" begin="0;svgSpinnersPulseRingsMultiple2.end" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="scale" values="0;1"/><animate attributeName="opacity" begin="0;svgSpinnersPulseRingsMultiple2.end" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" values="1;0"/></path><path fill="black" d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,20a9,9,0,1,1,9-9A9,9,0,0,1,12,21Z" transform="matrix(0 0 0 0 12 12)"><animateTransform id="svgSpinnersPulseRingsMultiple1" attributeName="transform" begin="svgSpinnersPulseRingsMultiple0.begin+0.2s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="translate" values="12 12;0 0"/><animateTransform additive="sum" attributeName="transform" begin="svgSpinnersPulseRingsMultiple0.begin+0.2s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="scale" values="0;1"/><animate attributeName="opacity" begin="svgSpinnersPulseRingsMultiple0.begin+0.2s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" values="1;0"/></path><path fill="black" d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,20a9,9,0,1,1,9-9A9,9,0,0,1,12,21Z" transform="matrix(0 0 0 0 12 12)"><animateTransform id="svgSpinnersPulseRingsMultiple2" attributeName="transform" begin="svgSpinnersPulseRingsMultiple0.begin+0.4s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="translate" values="12 12;0 0"/><animateTransform additive="sum" attributeName="transform" begin="svgSpinnersPulseRingsMultiple0.begin+0.4s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" type="scale" values="0;1"/><animate attributeName="opacity" begin="svgSpinnersPulseRingsMultiple0.begin+0.4s" calcMode="spline" dur="1.2s" keySplines=".52,.6,.25,.99" values="1;0"/></path></svg>
            </div>
            <p>{{ $t('Please wait as the payment is being processed!') }}</p>
            <Link href="billing" class="underline">{{ $t('Go to billing') }}</Link>
        </div>
    </div>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="flex justify-between mt-8 md:mt-0">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Billing and subscription') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('View and manage your billing history') }}</span>
                    </p>
                </div>
            </div>
            <div v-if="!subscriptionIsActive" class="bg-red-500 p-5 w-100 mb-4 rounded-[0.5rem] flex justify-between items-center space-x-4 text-white">
                <div class="flex items-center space-x-4">
                    <span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 56 56"><path fill="currentColor" d="M9.965 50.207h36.07c3.985 0 6.469-2.86 6.469-6.469c0-1.078-.281-2.18-.867-3.187L33.567 9.074c-1.22-2.133-3.352-3.281-5.555-3.281c-2.18 0-4.336 1.148-5.579 3.281l-18.07 31.5a6.253 6.253 0 0 0-.867 3.164c0 3.61 2.508 6.469 6.469 6.469m.047-3.68c-1.641 0-2.72-1.336-2.72-2.789c0-.422.071-.914.306-1.406l18.046-31.477c.516-.89 1.454-1.312 2.368-1.312c.914 0 1.804.398 2.32 1.312l18.047 31.5c.234.47.351.961.351 1.383c0 1.453-1.125 2.79-2.742 2.79Zm18-12.117c1.125 0 1.78-.656 1.804-1.875l.328-12.351c.024-1.196-.914-2.086-2.156-2.086c-1.265 0-2.156.867-2.133 2.062l.305 12.375c.023 1.196.68 1.875 1.852 1.875m0 7.617c1.359 0 2.53-1.078 2.53-2.437c0-1.383-1.148-2.438-2.53-2.438c-1.383 0-2.532 1.078-2.532 2.438c0 1.336 1.172 2.437 2.532 2.437"/></svg>
                    </span>
                    <h2 v-if="props.subscription?.status === 'trial'" class="text-sm">{{ $t('Your trial period is over') }} <br> {{ $t('Please subscribe to a plan to continue using the app') }}.</h2>
                    <h2 v-if="props.subscription?.status === 'active'" class="text-sm">{{ $t('We were unable to autorenew your subscription') }} <br> {{ $t('To continue using the app, please make a payment of') }} {{ props.subscriptionDetails?.accountBalance }}.</h2>
                </div>
                <div>
                    <div class="float-right">
                        <Link href="/subscription" v-if="props.subscription?.status === 'trial'" type="button" class="rounded-md bg-white text-gray-600 px-3 py-2 text-sm shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Subscribe') }}</Link>
                        <button @click="openModal()" v-if="props.subscription?.status === 'active' && props.setting['enable_custom_payment'] == 1" type="button" class="rounded-md bg-white text-gray-600 px-3 py-2 text-sm shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Add payment') }}</button>
                    </div>
                </div>
            </div>
            <div class="md:flex bg-slate-100 md:bg-white shadow-sm p-5 rounded-[0.5rem] space-y-6">
                <div class="md:w-[50%]">
                    <div class="mb-5">
                        <h2 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Plan details') }}</h2>
                        <h2 v-if="props.subscription?.status === 'trial'" class="text-[18px]">{{ $t('Trial period') }}</h2>
                        <h2 v-if="props.subscription?.status === 'active'" class="text-[18px]">{{ props.subscription?.plan?.name }}</h2>
                    </div>
                    <div v-if="props.subscription?.status != 'trial'" class="flex gap-x-3">
                        <div>
                            <h2 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Start date') }}</h2>
                            <span class="text-sm">{{ props.subscription?.start_date }}</span>
                        </div>
                        <div>
                            <h2 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Next billing date') }}</h2>
                            <span class="text-sm">{{ props.subscription?.valid_until }}</span>
                        </div>
                        <div>
                            <h2 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Account balance') }}</h2>
                            <span class="text-sm">{{ props.subscriptionDetails?.accountBalance }}</span>
                        </div>
                    </div>
                    <div v-if="props.subscription?.status === 'trial'" class="flex space-x-6">
                        <div>
                            <h2 class="text-sm text-gray-500 tracking-[0px]">{{ $t('Trial expires on') }}</h2>
                            <span class="text-sm">{{ props.subscription?.valid_until }}</span>
                        </div>
                    </div>
                </div>
                <div class="md:w-[50%]">
                    <div class="md:float-right gap-y-2 gap-x-3" :class="props.setting['enable_custom_payment'] == 0 ? 'flex' : 'grid grid-cols-2'">
                        <button v-if="props.setting['enable_custom_payment'] == 1" @click="openModal()" type="button" class="w-full capitalize rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 mr-2 col-span-2 md:col-span-1 text-center">{{ $t('Add payment') }}</button>
                        <Link href="/subscription" v-if="props.subscription?.status === 'trial'" class="w-full rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 col-span-2 md:col-span-1 text-center">{{ $t('Subscribe to plan') }}</Link>
                        <Link href="/subscription" v-if="props.subscription?.status === 'active'" class="w-full rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 col-span-2 md:col-span-1 text-center">{{ $t('Upgrade subscription') }}</Link>
                    </div>
                </div>
            </div>
            <div class=" bg-slate-100 md:bg-gray-50 p-5 md:px-0 rounded-[0.5rem] mt-10 w-full">
                <div class="w-full">
                    <div class="mb-5">
                        <h2 class="text-xl tracking-[0px]">{{ $t('Invoices') }}</h2>
                        <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                            <span class="ml-1 mt-1">{{ $t('You can view your invoices and past billing details here') }}</span>
                        </p>
                    </div>
                    <div class="w-100">
                        <BillingTable :rows="props.rows" :filters="props.filters"/>
                    </div>
                </div>
            </div>
        </div>
        <Modal :label="label" :isOpen=isOpenModal>
            <form @submit.prevent="submitForm()" class="gap-y-4">
                <h2 class="text-2xl mb-4">{{ $t('Add payment') }}</h2>
                <p class="text-sm">{{ $t('Add your own custom amount') }}</p>
                <FormInput v-model="form.amount" :error="form.errors.amount" :name="''" :type="'number'" :class="'w-100'"/>
                <h2 class="text-sm mt-4 mb-2">{{ $t('Pay via') }}</h2>
                <div class="grid grid-cols-2 gap-2">
                    <div v-for="(item, index) in props.methods" :key="index" class="">
                        <div class="flex items-center">
                            <label @click="selectPayment(item.name)" for="myCheckbox" class="cursor-pointer">
                                <div class="w-5 h-5 border border-gray-400 rounded-md flex items-center justify-center" :class="form.method === item.name ? 'bg-[#000]' : ''">
                                    <svg v-if="form.method === item.name" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                </div>
                            </label>
                            <span @click="selectPayment(item.name)" class="ml-2 text-sm cursor-pointer">{{ item.name }}</span>
                        </div>
                    </div>
                </div>
                <div class="form-error text-[#b91c1c] text-xs mt-2">{{ form.errors.method }}</div>
                <div class="mt-6 flex">
                    <button type="button" @click.self="onClose" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                    <button 
                        :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]"
                        :disabled="isLoading"
                    >
                        <span v-if="isLoading" class="flex space-x-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span>{{ $t('Processing') }}</span>
                        </span>
                        <span v-else class="flex space-x-1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M12 13a1.49 1.49 0 0 0-1 2.61V17a1 1 0 0 0 2 0v-1.39A1.49 1.49 0 0 0 12 13m5-4V7A5 5 0 0 0 7 7v2a3 3 0 0 0-3 3v7a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-7a3 3 0 0 0-3-3M9 7a3 3 0 0 1 6 0v2H9Zm9 12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1Z"/></svg>
                            <span>{{ $t('Continue') }}</span>
                        </span>
                    </button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import BillingTable from '@/Components/Tables/BillingTable.vue';
    import { Link, useForm, router } from "@inertiajs/vue3";
    import Modal from '@/Components/Modal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import { ref, onMounted } from 'vue';
    import Echo from 'laravel-echo';
    import Pusher from 'pusher-js';

    const props = defineProps([
        'subscription',
        'rows', 
        'filters', 
        'subscriptionIsActive', 
        'subscriptionDetails', 
        'methods', 
        'isPaymentLoading',
        'pusherSettings',
        'organizationId',
        'setting'
    ]);

    const subscription = ref(props.subscription.data)
    const isOpenModal = ref(false);
    const isLoading = ref(false);
    
    const form = useForm({
        'amount' : null,
        'method' : null
    });

    const selectPayment = (method) => {
        form.method = method;
    };

    function openModal(){
        isOpenModal.value = true;
    }

    function onClose() {
        isOpenModal.value = false
    }

    const submitForm = async () => {
        isLoading.value = true;
        form.post('/pay', {
            preserveScroll: true,
            onFinish: () => { 
                isLoading.value = false
            },
        });
    };

    onMounted(() => {
        //Pusher.logToConsole = true;
        if(props.pusherSettings['pusher_app_key'] != null && props.pusherSettings['pusher_app_cluster'] != null){
            window.Pusher = Pusher;

            window.Echo = new Echo({
                broadcaster: 'pusher',
                key: props.pusherSettings['pusher_app_key'],
                cluster: props.pusherSettings['pusher_app_cluster'],
                encrypted: true,
            });

            window.Echo.channel('payments.ch' + props.organizationId).listen('NewPaymentEvent', (event) => {
                router.visit('/billing', {});
            });
        }
    });
</script>