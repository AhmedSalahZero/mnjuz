<template>
    <div :class="rtlClass" class="min-h-screen flex flex-col bg-[#F7F8F7]">
        <!-- الترويسة: الشعار في البداية ورابط الدخول في النهاية -->
        <header class="flex items-center justify-between px-5 sm:px-8 py-4 flex-shrink-0">
            <Link href="/">
                <img class="h-8 w-auto object-contain" v-if="props.companyConfig.logo"
                    :src="'/media/' + props.companyConfig.logo" :alt="props.companyConfig.company_name">
                <span v-else class="text-lg font-bold text-primary">{{ props.companyConfig.company_name }}</span>
            </Link>
            <div class="text-xs text-slate-500">
                {{ $t('Already have an account?') }}
                <Link href="login" class="text-primary font-bold hover:underline">{{ $t('Login') }}</Link>
            </div>
        </header>

        <!-- شريط التقدّم -->
        <div class="px-5 sm:px-8 flex-shrink-0">
            <div class="max-w-xl mx-auto">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-bold text-slate-500">
                        {{ $t('Step') }} {{ currentStep + 1 }} {{ $t('of') }} {{ totalSteps }}
                    </span>
                    <span class="text-xs font-black text-primary">{{ progressPercent }}%</span>
                </div>
                <div class="h-1.5 rounded-full overflow-hidden bg-slate-200">
                    <div class="h-full rounded-full bg-primary transition-all duration-500"
                        :style="{ width: progressPercent + '%' }"></div>
                </div>
            </div>
        </div>

        <!-- المحتوى: خطوة واحدة ظاهرة في كل مرة -->
        <main class="flex-1 flex flex-col px-5 sm:px-8 py-6 overflow-y-auto">
            <form @submit.prevent="onSubmit()" class="w-full max-w-xl mx-auto flex-1 flex flex-col">
                <div v-if="$page.props.flash?.status?.type === 'error'"
                    class="mb-5 rounded-2xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                    {{ $page.props.flash.status.message }}
                </div>

                <!-- :key يُعيد التركيب فتُعاد حركة الدخول عند كل خطوة -->
                <div :key="currentStep" class="wizard-step">
                    <h1 class="step-title">{{ steps[currentStep].title }}</h1>
                    <p class="step-sub">{{ steps[currentStep].subtitle }}</p>

                    <!-- ١: الحساب الشخصي -->
                    <template v-if="currentStep === 0">
                        <div class="grid gap-x-4 gap-y-4 grid-cols-2">
                            <FormInput v-model="form.first_name" :name="$t('First name')" :error="form.errors.first_name" :type="'text'"/>
                            <FormInput v-model="form.last_name" :name="$t('Last name')" :error="form.errors.last_name" :type="'text'"/>
                        </div>
                        <div class="mt-4">
                            <FormInput v-model="form.email" :name="$t('Email')" :error="form.errors.email" :type="'email'"/>
                        </div>
                        <div class="mt-4">
                            <FormPhoneInput v-model="form.phone" :name="$t('Phone')" :error="form.errors.phone" :type="'text'"/>
                        </div>
                        <div class="mt-4 grid gap-x-4 gap-y-4 grid-cols-2">
                            <FormInput v-model="form.password" :name="$t('Password')" :error="form.errors.password" :type="'password'"/>
                            <FormInput v-model="form.password_confirmation" :name="$t('Confirm password')" :error="form.errors.password_confirmation" :type="'password'"/>
                        </div>
                    </template>

                    <!-- ٢: بيانات المنشأة -->
                    <template v-else-if="currentStep === 1">
                        <FormInput v-model="form.organization_name" :name="$t('Organization name')" :error="form.errors.organization_name" :type="'text'"/>
                        <div class="mt-4">
                            <FormInput v-model="form.vat" :name="$t('VAT number')" :error="form.errors.vat" :type="'text'"/>
                            <p class="field-hint">{{ $t('Optional') }}</p>
                        </div>
                        <div class="mt-4">
                            <FormInput v-model="form.website" :name="$t('Website')" :error="form.errors.website" :type="'url'" :placeholder="'https://example.com'"/>
                            <p class="field-hint">{{ $t('Optional') }}</p>
                        </div>
                    </template>

                    <!-- ٣: العنوان -->
                    <template v-else>
                        <!-- قائمة بحث مع علم الدولة — 250 دولة يصعب تصفّحها بلا بحث -->
                        <FormCountrySelect v-model="form.country" :name="$t('Country')"
                            :placeholder="$t('Search for your country')"
                            :emptyText="$t('No results')"
                            :options="props.countries" :error="form.errors.country"/>
                        <div class="mt-4 grid gap-x-4 gap-y-4 grid-cols-2">
                            <FormInput v-model="form.state" :name="$t('State')" :error="form.errors.state" :type="'text'"/>
                            <FormInput v-model="form.city" :name="$t('City')" :error="form.errors.city" :type="'text'"/>
                        </div>
                        <div class="mt-4">
                            <FormInput v-model="form.street" :name="$t('Address')" :error="form.errors.street" :type="'text'"/>
                        </div>
                        <div class="mt-4">
                            <FormInput v-model="form.zip" :name="$t('Zip code')" :error="form.errors.zip" :type="'text'"/>
                            <p class="field-hint">{{ $t('This address is also used for billing and shipping on your invoices.') }}</p>
                        </div>
                    </template>

                    <div v-if="form.errors.recaptcha_response" class="field-err">{{ form.errors.recaptcha_response }}</div>
                </div>

                <!-- التذييل: رجوع في البداية والتالي في النهاية -->
                <footer class="mt-8 pt-4 border-t border-slate-200 flex items-center gap-3">
                    <button v-if="currentStep > 0" type="button" @click="previousStep()" class="btn-ghost">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                            :class="isRtl ? 'rotate-180' : ''">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m15 6l-6 6l6 6"/>
                        </svg>
                        {{ $t('Back') }}
                    </button>
                    <div class="flex-1"></div>
                    <button type="submit" class="btn-primary px-8" :disabled="isLoading">
                        <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                        <span v-else>{{ isLastStep ? $t('Create account') : $t('Next') }}</span>
                    </button>
                </footer>

                <div v-if="props.companyConfig?.allow_facebook_login === '1' || props.companyConfig?.allow_google_login === '1'"
                    class="mt-8 flex flex-col items-center">
                    <span class="text-xs text-slate-500 mb-3">{{ $t('Or continue with') }}</span>
                    <div class="flex justify-center gap-4">
                        <a v-if="props.companyConfig?.allow_facebook_login === '1'" href="/social-login/facebook" class="border rounded-full p-2 cursor-pointer bg-white">
                            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 256 256"><path fill="#1877F2" d="M256 128C256 57.308 198.692 0 128 0C57.308 0 0 57.307 0 128c0 63.888 46.808 116.843 108 126.445V165H75.5v-37H108V99.8c0-32.08 19.11-49.8 48.347-49.8C170.352 50 185 52.5 185 52.5V84h-16.14C152.958 84 148 93.867 148 103.99V128h35.5l-5.675 37H148v89.445c61.192-9.602 108-62.556 108-126.445"/><path fill="#FFF" d="m177.825 165l5.675-37H148v-24.01C148 93.866 152.959 84 168.86 84H185V52.5S170.352 50 156.347 50C127.11 50 108 67.72 108 99.8V128H75.5v37H108v89.445A128.959 128.959 0 0 0 128 256a128.9 128.9 0 0 0 20-1.555V165h29.825"/></svg>
                        </a>
                        <a v-if="props.companyConfig?.allow_google_login === '1'" href="/social-login/google" class="border rounded-full p-2 cursor-pointer bg-white">
                            <svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 128 128"><path fill="#fff" d="M44.59 4.21a63.28 63.28 0 0 0 4.33 120.9a67.6 67.6 0 0 0 32.36.35a57.13 57.13 0 0 0 25.9-13.46a57.44 57.44 0 0 0 16-26.26a74.33 74.33 0 0 0 1.61-33.58H65.27v24.69h34.47a29.72 29.72 0 0 1-12.66 19.52a36.16 36.16 0 0 1-13.93 5.5a41.29 41.29 0 0 1-15.1 0A37.16 37.16 0 0 1 44 95.74a39.3 39.3 0 0 1-14.5-19.42a38.31 38.31 0 0 1 0-24.63a39.25 39.25 0 0 1 9.18-14.91A37.17 37.17 0 0 1 76.13 27a34.28 34.28 0 0 1 13.64 8q5.83-5.8 11.64-11.63c2-2.09 4.18-4.08 6.15-6.22A61.22 61.22 0 0 0 87.2 4.59a64 64 0 0 0-42.61-.38z"/><path fill="#e33629" d="M44.59 4.21a64 64 0 0 1 42.61.37a61.22 61.22 0 0 1 20.35 12.62c-2 2.14-4.11 4.14-6.15 6.22Q95.58 29.23 89.77 35a34.28 34.28 0 0 0-13.64-8a37.17 37.17 0 0 0-37.46 9.74a39.25 39.25 0 0 0-9.18 14.91L8.76 35.6A63.53 63.53 0 0 1 44.59 4.21z"/><path fill="#f8bd00" d="M3.26 51.5a62.93 62.93 0 0 1 5.5-15.9l20.73 16.09a38.31 38.31 0 0 0 0 24.63q-10.36 8-20.73 16.08a63.33 63.33 0 0 1-5.5-40.9z"/><path fill="#587dbd" d="M65.27 52.15h59.52a74.33 74.33 0 0 1-1.61 33.58a57.44 57.44 0 0 1-16 26.26c-6.69-5.22-13.41-10.4-20.1-15.62a29.72 29.72 0 0 0 12.66-19.54H65.27c-.01-8.22 0-16.45 0-24.68z"/><path fill="#319f43" d="M8.75 92.4q10.37-8 20.73-16.08A39.3 39.3 0 0 0 44 95.74a37.16 37.16 0 0 0 14.08 6.08a41.29 41.29 0 0 0 15.1 0a36.16 36.16 0 0 0 13.93-5.5c6.69 5.22 13.41 10.4 20.1 15.62a57.13 57.13 0 0 1-25.9 13.47a67.6 67.6 0 0 1-32.36-.35a63 63 0 0 1-23-11.59A63.73 63.73 0 0 1 8.75 92.4z"/></svg>
                        </a>
                    </div>
                </div>
            </form>
        </main>
    </div>
</template>

<script setup>
import FormInput from '@/Components/FormInput.vue';
import FormPhoneInput from '@/Components/FormPhoneInput.vue';
import FormCountrySelect from '@/Components/FormCountrySelect.vue';
import { useRtl } from '@/Composables/useRtl';
import { useTrans } from '@/Composables/useTrans';
import { Link, useForm } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { unMountRecaptcha, useRecaptcha } from '../../Composables/ReCaptcha';

const { rtlClass, isRtl } = useRtl();
const trans = useTrans();

const props = defineProps(['flash', 'config', 'companyConfig', 'countries']);

const isLoading = ref(false);
const currentStep = ref(0);

const form = useForm({
    first_name: null,
    last_name: null,
    email: null,
    phone: null,
    password: null,
    password_confirmation: null,
    organization_name: null,
    vat: null,
    website: null,
    country: null,
    state: null,
    city: null,
    street: null,
    zip: null,
    recaptcha_response: null,
});

// عنوان ووصف كل خطوة، وحقولها التي نستخدمها لتحديد أين نُظهر خطأ الخادم.
const steps = computed(() => [
    {
        title: trans('Create your account'),
        subtitle: trans('Your basic details to sign in and manage your organization'),
        fields: ['first_name', 'last_name', 'email', 'phone', 'password', 'password_confirmation'],
    },
    {
        title: trans('About your organization'),
        subtitle: trans('This information appears on your invoices'),
        fields: ['organization_name', 'vat', 'website'],
    },
    {
        title: trans('Where is your organization located?'),
        subtitle: trans('We use this address for billing and shipping'),
        fields: ['country', 'state', 'city', 'street', 'zip'],
    },
]);

const totalSteps = computed(() => steps.value.length);
const isLastStep = computed(() => currentStep.value === totalSteps.value - 1);
const progressPercent = computed(() => Math.round(((currentStep.value + 1) / totalSteps.value) * 100));

const previousStep = () => {
    if (currentStep.value > 0) currentStep.value--;
};

const onSubmit = () => {
    if (!isLastStep.value) {
        currentStep.value++;
        return;
    }
    submitForm();
};

const getValueByKey = (key) => {
    const found = props.config.find(item => item.key === key);
    return found ? found.value : '';
};

const submitForm = async () => {
    isLoading.value = true;

    if (getValueByKey('recaptcha_active') === '1') {
        form.recaptcha_response = await getRecaptchaToken();
    }

    form.post('signup', {
        preserveScroll: true,
        onError: (errors) => {
            // نرجع لأول خطوة فيها خطأ، وإلا بقي المستخدم على الخطوة الأخيرة
            // يضغط «إنشاء الحساب» دون أن يرى سبب الرفض.
            const failed = steps.value.findIndex(step => step.fields.some(field => errors[field]));
            if (failed !== -1) currentStep.value = failed;
        },
        onFinish: () => {
            isLoading.value = false;
        }
    });
};

const getRecaptchaToken = () => {
    return new Promise((resolve) => {
        grecaptcha.ready(() => {
            grecaptcha.execute(getValueByKey('recaptcha_site_key'), { action: 'submit' })
                .then((token) => resolve(token));
        });
    });
};

onMounted(() => {
    if (getValueByKey('recaptcha_active') === '1') {
        useRecaptcha(getValueByKey('recaptcha_site_key'));
    }
});

onUnmounted(() => {
    unMountRecaptcha(getValueByKey('recaptcha_site_key'));
});
</script>

<style scoped>
.step-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #0F1923;
    margin-bottom: 0.4rem;
}

.step-sub {
    font-size: 0.875rem;
    color: #6B7280;
    margin-bottom: 1.5rem;
}

.field-hint {
    font-size: 0.7rem;
    color: #9CA3AF;
    margin-top: 0.4rem;
}

.field-err {
    font-size: 0.7rem;
    color: #EF4444;
    font-weight: 700;
    margin-top: 0.75rem;
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.7rem 1.5rem;
    border-radius: 0.95rem;
    background: #034737;
    color: #fff;
    font-weight: 700;
    font-size: 0.9rem;
    transition: filter 0.15s;
}

.btn-primary:hover {
    filter: brightness(1.15);
}

.btn-primary:disabled {
    opacity: 0.6;
}

.btn-ghost {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.7rem 1.2rem;
    border-radius: 0.95rem;
    background: #FAFAF8;
    color: #374151;
    border: 1.5px solid rgba(0, 0, 0, 0.07);
    font-weight: 700;
    font-size: 0.9rem;
    transition: border-color 0.15s;
}

.btn-ghost:hover {
    border-color: #034737;
}

/* حركة دخول الخطوة — يعيد :key تركيب العنصر فتُعاد في كل انتقال */
.wizard-step {
    animation: stepIn 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes stepIn {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (prefers-reduced-motion: reduce) {
    .wizard-step {
        animation: none;
    }
}
</style>
