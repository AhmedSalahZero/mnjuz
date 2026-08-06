<template>
    <div :class="rtlClass" class="min-h-screen flex flex-col bg-[#F7F8F7]">
        <!-- الترويسة: الشعار في البداية ورابط الدخول في النهاية -->
        <header class="flex items-center justify-between px-5 sm:px-8 py-4 flex-shrink-0">
            <Link href="/">
                <img class="h-8 w-auto object-contain" v-if="props.companyConfig.logo"
                    :src="'/media/' + props.companyConfig.logo" :alt="props.companyConfig.company_name">
                <span v-else class="text-lg font-bold text-primary">{{ props.companyConfig.company_name }}</span>
            </Link>
            <div class="flex items-center gap-5">
                <div class="text-xs text-slate-500">
                    {{ $t('Already have an account?') }}
                    <Link href="login" class="text-primary font-bold hover:underline">{{ $t('Login') }}</Link>
                </div>
                <!-- اللغة تُحدَّد قبل التسجيل: هي لغة الواجهة ولغة العميل في
                     منصة الفوترة (default_language) معاً. -->
                <LangToggle :languages="$page.props.languages" :currentLanguage="$page.props.currentLanguage" />
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

            </form>
        </main>
    </div>
</template>

<script setup>
import FormInput from '@/Components/FormInput.vue';
import FormPhoneInput from '@/Components/FormPhoneInput.vue';
import FormCountrySelect from '@/Components/FormCountrySelect.vue';
import LangToggle from '@/Components/LangToggle.vue';
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
