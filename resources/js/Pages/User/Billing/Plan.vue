<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="flex justify-between mt-8 md:mt-0">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Billing and subscription') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Select the plan that you want to subscribe to') }}</span>
                    </p>
                </div>
            </div>
            <div class="md:flex gap-4">
                <div class="md:w-[65%]">
                    <div class="grid grid-cols-2 gap-4">
                        <div v-for="(item, index) in props.plans?.data" :key="index" @click="selectPlan(item.id, item.name, item.period, item.price)" class="rounded-[0.5rem] shadow-sm p-5 cursor-pointer border-2 col-span-2 md:col-span-1" :class="form.plan === item.id ? 'border-gray-700 bg-slate-50' : 'border-slate-100 md:border-white bg-slate-20 md:bg-white'">
                            <div class="flex justify-between">
                                <div class="">
                                    <h3>{{ item.name }}</h3>
                                </div>
                                <div class="">
                                    <label for="myCheckbox" class="cursor-pointer">
                                        <div class="w-5 h-5 border border-gray-400 rounded-md flex items-center justify-center" :class="form.plan === item.id ? 'bg-[#000]' : ''">
                                            <svg v-if="form.plan === item.id" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <h2 class="text-2xl">{{ item.price }}</h2>
                            <h3 v-if="item.period === 'monthly'" class="text-sm mb-4">{{ $t('Per month') }}</h3>
                            <h3 v-if="item.period === 'yearly'" class="text-sm mb-4">{{ $t('Per year') }}</h3>
                            <h3 class="mb-1">{{ $t('Features') }}</h3>
                            <div class="flex justify-between text-sm">
                                <div class="flex space-x-1">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                    </span>
                                    <h3>{{ getDetail(item?.metadata, 'campaign_limit') == '-1' ?  $t('Unlimited') : getDetail(item?.metadata, 'campaign_limit') }} {{ $t('Campaigns') }}</h3>
                                </div>
                            </div>
                            <div class="flex justify-between text-sm">
                                <div class="flex space-x-1">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                    </span>
                                    <h3>{{ getDetail(item?.metadata, 'message_limit') == '-1' ?  $t('Unlimited') : getDetail(item?.metadata, 'message_limit') }} {{ $t('Messages') }}</h3>
                                </div>
                            </div>
                            <div class="flex justify-between text-sm">
                                <div class="flex space-x-1">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                    </span>
                                    <h3>{{ getDetail(item?.metadata, 'contacts_limit') == '-1' ?  $t('Unlimited') : getDetail(item?.metadata, 'contacts_limit') }} {{ $t('Contacts') }}</h3>
                                </div>
                            </div>
                            <div class="flex justify-between text-sm">
                                <div class="flex space-x-1">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                    </span>
                                    <h3>{{ getDetail(item?.metadata, 'canned_replies_limit') == '-1' ?  $t('Unlimited') : getDetail(item?.metadata, 'canned_replies_limit') }} {{ $t('Canned replies') }}</h3>
                                </div>
                            </div>
                            <div class="flex justify-between text-sm">
                                <div class="flex space-x-1">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                    </span>
                                    <h3>{{ getDetail(item?.metadata, 'team_limit') == '-1' ?  $t('Unlimited') : getDetail(item?.metadata, 'team_limit') }} {{ $t('Users') }}</h3>
                                </div>
                            </div>
                            <div v-for="(value, key) in filteredAddons(item)" class="flex justify-between text-sm">
                                <div class="flex space-x-1">
                                    <span>
                                        <svg v-if="value == true" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                        <svg v-else class="text-red-500" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 26 26"><g fill="currentColor"><path d="M10.172 17.243a1 1 0 1 1-1.415-1.415l7.071-7.07a1 1 0 1 1 1.415 1.414z"/><path d="M8.757 10.172a1 1 0 0 1 1.415-1.415l7.07 7.071a1 1 0 1 1-1.414 1.415z"/><path fill-rule="evenodd" d="M13 24c6.075 0 11-4.925 11-11S19.075 2 13 2S2 6.925 2 13s4.925 11 11 11m0 2c7.18 0 13-5.82 13-13S20.18 0 13 0S0 5.82 0 13s5.82 13 13 13" clip-rule="evenodd"/></g></svg>
                                    </span>
                                    <h3>{{ key }}</h3>
                                </div>
                            </div>
                            <div v-if="props.enable_ai_billing == 1" class="flex justify-between text-sm">
                                <div class="flex space-x-1">
                                    <span>
                                        <svg v-if="getDetail(item?.metadata, 'ai_text_response_limit') && filteredAddons(item)['AI Assistant']"  xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                        <svg v-else class="text-red-500" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 26 26"><g fill="currentColor"><path d="M10.172 17.243a1 1 0 1 1-1.415-1.415l7.071-7.07a1 1 0 1 1 1.415 1.414z"/><path d="M8.757 10.172a1 1 0 0 1 1.415-1.415l7.07 7.071a1 1 0 1 1-1.414 1.415z"/><path fill-rule="evenodd" d="M13 24c6.075 0 11-4.925 11-11S19.075 2 13 2S2 6.925 2 13s4.925 11 11 11m0 2c7.18 0 13-5.82 13-13S20.18 0 13 0S0 5.82 0 13s5.82 13 13 13" clip-rule="evenodd"/></g></svg>
                                    </span>
                                    <h3>
                                        <span v-if="filteredAddons(item)['AI Assistant']">{{ getDetail(item?.metadata, 'ai_text_response_limit') == '-1' ?  $t('Unlimited') : getDetail(item?.metadata, 'ai_text_response_limit') }}</span>
                                        {{ $t('AI Text Response Limit') }}
                                    </h3>
                                </div>
                            </div>
                            <div v-if="props.enable_ai_billing == 1" class="flex justify-between text-sm">
                                <div class="flex space-x-1">
                                    <span>
                                        <svg v-if="getDetail(item?.metadata, 'ai_audio_response_limit') && filteredAddons(item)['AI Assistant']"  xmlns="http://www.w3.org/2000/svg" width="16px" height="16px" viewBox="0 0 16 16"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"><path d="M14.25 8.75c-.5 2.5-2.385 4.854-5.03 5.38A6.25 6.25 0 0 1 3.373 3.798C5.187 1.8 8.25 1.25 10.75 2.25"/><path d="m5.75 7.75l2.5 2.5l6-6.5"/></g></svg>
                                        <svg v-else class="text-red-500" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 26 26"><g fill="currentColor"><path d="M10.172 17.243a1 1 0 1 1-1.415-1.415l7.071-7.07a1 1 0 1 1 1.415 1.414z"/><path d="M8.757 10.172a1 1 0 0 1 1.415-1.415l7.07 7.071a1 1 0 1 1-1.414 1.415z"/><path fill-rule="evenodd" d="M13 24c6.075 0 11-4.925 11-11S19.075 2 13 2S2 6.925 2 13s4.925 11 11 11m0 2c7.18 0 13-5.82 13-13S20.18 0 13 0S0 5.82 0 13s5.82 13 13 13" clip-rule="evenodd"/></g></svg>
                                    </span>
                                    <h3>
                                        <span v-if="filteredAddons(item)['AI Assistant']">{{ getDetail(item?.metadata, 'ai_audio_response_limit') == '-1' ?  $t('Unlimited') : getDetail(item?.metadata, 'ai_audio_response_limit') }}</span>
                                        {{ $t('AI Audio Response Limit') }}
                                    </h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="md:w-[35%]">
                    <div class="bg-white rounded-md shadow-md p-5">
                        <h3 class="text-xl mb-4">{{ $t('Summary') }}</h3>
                        <div v-if="!form.plan">
                            <div class="border border-dashed flex itens-center justify-center p-10 rounded-md">
                                <h3>{{ $t('Select plan to continue') }}</h3>
                            </div>
                        </div>
                        <div v-if="form.plan">
                            <div class="flex justify-between mb-4 text-sm">
                                <div>
                                    <h3>{{ selectedPlan.name }}</h3>
                                    <h3 class="bg-slate-100 w-[fit-content] py-1 px-2 rounded-md capitalize text-xs">{{ selectedPlan.period }}</h3>
                                </div>
                                <h3>{{ basePrice }}</h3>
                            </div>
                            <hr>
                            <div class="flex justify-between mt-4 text-sm">
                                <h3>{{ $t('Gross total') }}</h3>
                                <h3>{{ grossAmount }}</h3>
                            </div>
                            <div v-if="taxRates.length > 0" class="bg-slate-100 px-2 py-2 space-y-2 rounded-md mt-2 mb-2">
                                <h3 class="text-sm border-b border-dashed">{{ $t('Tax') }}</h3>
                                <div v-for="(item, index) in taxRates" :key="index" class="flex justify-between text-sm">
                                    <h3>{{ item.name }} <span class="">({{ item.percentage }}%)</span></h3>
                                    <h3>{{item.amount }}</h3>
                                </div>
                            </div>
                            <div v-if="credit.total > 0" class="bg-slate-100 px-2 py-2 space-y-2 rounded-md mt-2 mb-2">
                                <div class="flex justify-between text-sm">
                                    <div>{{ $t('Available credits') }} <br><span class="text-xs">({{ $t('Applicable credits for this invoice') }})</span></div>
                                    <h3 v-if="parseFloat(-credit.total) <= parseFloat(netAmount)" class="text-red-500">{{ credit.total }}</h3>
                                    <h3 v-if="parseFloat(-credit.total) > parseFloat(netAmount)" class="text-red-500">
                                    ({{ netAmount }})
                                    </h3>
                                </div>
                            </div>
                            <div v-if="debit.total > 0" class="bg-slate-100 px-2 py-2 space-y-2 rounded-md mt-2 mb-2">
                                <div class="flex justify-between text-sm">
                                    <div>{{ $t('Available debits') }} <br><span class="text-xs">({{ $t('Applicable debits due') }})</span></div>
                                    <h3>{{ debit.total }}</h3>
                                </div>
                            </div>
                            <div v-if="parseFloat(amountDue) > 0" class="bg-slate-100 px-2 py-2 space-y-2 rounded-md mt-2 mb-2">
                                <div class="text-sm">
                                    <div class="text-sm border-b border-dashed">{{ $t('Coupon code') }}</div>
                                    <form v-if="coupon.length === 0" @submit.prevent="applyCoupon" class="mt-2 bg-white w-full rounded-md border-0 py-1 pl-2 pr-1 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6">
                                        <div class="flex items-center justify-between">
                                            <input v-model="form1.coupon" class="h-full w-3/4 outline-none">
                                            <button :class="['h-full w-[fit-content] py-0.5 px-2 text-[12px] flex items-center justify-center bg-primary text-white rounded-md', { 'opacity-50': form1.processing }]" :disabled="form1.processing">
                                                <svg v-if="form1.processing" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                                <span v-else>{{ $t('Apply') }}</span>
                                            </button>
                                        </div>
                                        <span class="text-red-500 text-xs" v-if="form1.errors.coupon">{{ form1.errors.coupon }}</span>
                                    </form>
                                    
                                    <div v-else class="mt-2 flex justify-between text-sm">
                                        <div class="flex items-center">
                                            <h3>{{ coupon?.code }}</h3> 
                                            <span v-if="coupon?.type == 'percentage'" class="">({{ coupon?.amount }}% OFF)</span>
                                            <button @click="removeCoupon" class="text-red-500">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 20a8 8 0 1 1 0-16a8 8 0 0 1 0 16M9.707 8.293a1 1 0 0 0-1.414 1.414L10.586 12l-2.293 2.293a1 1 0 1 0 1.414 1.414L12 13.414l2.293 2.293a1 1 0 0 0 1.414-1.414L13.414 12l2.293-2.293a1 1 0 0 0-1.414-1.414L12 10.586z"/></svg>
                                            </button>
                                        </div>
                                        <h3 class="text-red-500">{{ coupon?.discount }}</h3>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-between text-xl mt-4 mb-4">
                                <h3>{{ $t('Total due') }}</h3>
                                <h3>{{ amountDue }}</h3>
                            </div>
                            <hr>
                            <h2 v-if="parseFloat(amountDue) > 0" class="text-[14px] mt-3 mb-2">{{ $t('Pay via') }}</h2>
                            <div v-if="parseFloat(amountDue) > 0" class="flex grid grid-cols-2 gap-2">
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
                            <div class="mt-8">
                                <button v-if="(buttonLoading == false && form.method != null) || (buttonLoading == false && amountDue <= 0)" @click="submitForm()" type="button" class="w-full flex justify-center items-center space-x-1 rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M12 13a1.49 1.49 0 0 0-1 2.61V17a1 1 0 0 0 2 0v-1.39A1.49 1.49 0 0 0 12 13m5-4V7A5 5 0 0 0 7 7v2a3 3 0 0 0-3 3v7a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-7a3 3 0 0 0-3-3M9 7a3 3 0 0 1 6 0v2H9Zm9 12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1Z"/></svg>
                                    <span>{{ $t('Continue') }}</span>
                                </button>
                                <div v-else class="w-full flex justify-center items-center space-x-1 rounded-md bg-gray-300 px-3 py-2 text-sm text-gray-400 shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                                    <svg v-if="buttonLoading == false" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M12 13a1.49 1.49 0 0 0-1 2.61V17a1 1 0 0 0 2 0v-1.39A1.49 1.49 0 0 0 12 13m5-4V7A5 5 0 0 0 7 7v2a3 3 0 0 0-3 3v7a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-7a3 3 0 0 0-3-3M9 7a3 3 0 0 1 6 0v2H9Zm9 12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1Z"/></svg>
                                    <span v-if="buttonLoading == false">{{ $t('Continue') }}</span>
                                    <svg v-if="buttonLoading != false" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity="0.5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                    <span v-if="buttonLoading != false">{{ $t('Redirecting you') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { router, useForm } from "@inertiajs/vue3";
    import { ref } from 'vue';
    import { Link } from "@inertiajs/vue3";

    const props = defineProps(['addons', 'enable_ai_billing', 'plans', 'methods', 'subscription', 'subscriptionDetails']);
    const subscriptionDetails = ref(props.subscriptionDetails);

    const form = useForm({
        'plan': props.subscription?.plan_id,
        'method': null,
    });

    const form1 = useForm({
        'coupon': subscriptionDetails.value?.coupon?.code,
    });

    const buttonLoading = ref(false);

    const selectedPlan = ref({
        name: props.subscription?.plan?.name,
        period: props.subscription?.plan?.period,
        amount: props.subscription?.plan?.price,
    });

    const grossAmount = ref(subscriptionDetails.value?.grossAmount);
    const netAmount = ref(subscriptionDetails.value?.netAmount);
    const amountDue = ref(subscriptionDetails.value?.amountDue);
    const taxRates = ref(subscriptionDetails.value?.taxRates);
    const credit = ref(subscriptionDetails.value?.credit);
    const debit = ref(subscriptionDetails.value?.debit);
    const basePrice = ref(subscriptionDetails.value?.basePrice);
    const coupon = ref(subscriptionDetails.value?.coupon);

    const selectPlan = (plan, name, period, amount) => {
        form.plan = plan;
        selectedPlan.value = { name, period, amount };
        getPlanDetails(plan);
    };

    const selectPayment = (method) => {
        form.method = method;
    };

    const getDetail = (value, key) => {
        if(value){
            const item = JSON.parse(value);
            return item?.[key] ?? null;
        } else {
            return null;
        }
    }
    
    const filteredAddons = (item) => {
        const addons = JSON.parse(item.metadata)['addons'];

        if (!addons || typeof addons !== 'object') {
            return {}; // Return an empty object if addons is not valid
        }

        return Object.entries(props.addons).reduce((acc, [key, value]) => {
            if(value === 1 && addons.hasOwnProperty(key)){
                acc[key] = addons[key];
            }
            return acc;
        }, {});
    };

    const getPlanDetails = (planId) => {
        router.visit('/subscription/' + planId, {
            method: 'get',
            preserveState: true,
            onSuccess: (response) => {
                form.plan = planId;
                const res = response.props.response_data.data;
                subscriptionDetails.value = response.props.response_data.data;
                grossAmount.value = res.grossAmount;
                taxRates.value = res.taxRates;
                netAmount.value = res.netAmount;
                credit.value = res.credit;
                debit.value = res.debit;
                basePrice.value = res.basePrice;
                amountDue.value = res.amountDue;
                coupon.value = res.coupon
            },
        })
    }

    const removeCoupon = () => {
        const planId = form.plan;

        router.visit('/subscription/coupon/remove/' + planId, {
            method: 'get',
            preserveState: true,
            onSuccess: (response) => {
                form.plan = planId;
                const res = response.props.response_data.data;
                subscriptionDetails.value = response.props.response_data.data;
                grossAmount.value = res.grossAmount;
                taxRates.value = res.taxRates;
                netAmount.value = res.netAmount;
                credit.value = res.credit;
                debit.value = res.debit;
                basePrice.value = res.basePrice;
                amountDue.value = res.amountDue;
                coupon.value = res.coupon
            },
        });
    }

    const applyCoupon = () => {
        const planId = form.plan;
        
        form1.post('/subscription/coupon/apply/' + planId, {
            preserveScroll: true,
            onSuccess: (response) => {
                form.plan = planId;
                const res = response.props.response_data.data;
                subscriptionDetails.value = response.props.response_data.data;
                grossAmount.value = res.grossAmount;
                taxRates.value = res.taxRates;
                netAmount.value = res.netAmount;
                credit.value = res.credit;
                debit.value = res.debit;
                basePrice.value = res.basePrice;
                amountDue.value = res.amountDue;
                coupon.value = res.coupon
            },
        });
    }

    const submitForm = async () => {
        buttonLoading.value = true;

        form.post('/subscription', {
            preserveScroll: true,
        });
    };

    const convertToInt = (numberInput) => {
        const cleanedNumber = String(numberInput).replace(/,/g, ''); // Remove commas
        return parseFloat(cleanedNumber); // Convert to number and round down
    };
</script>