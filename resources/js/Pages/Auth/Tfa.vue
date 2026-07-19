<template>
    <div :class="rtlClass">
      <div class="flex h-screen justify-center">
        <div class="flex justify-center">
          <div class="w-[20em] mt-40">
            <div class="flex justify-center mb-5">
              <Link href="/">
                <img
                  class="max-w-[180px]"
                  v-if="props.companyConfig.logo"
                  :src="'/media/' + props.companyConfig.logo"
                  :alt="props.companyConfig.company_name"
                />
                <h4 v-else class="text-2xl mb-2">
                  {{ props.companyConfig.company_name }}
                </h4>
              </Link>
            </div>
            <h1 class="text-2xl text-center">{{ $t('Login to your account') }}</h1>
            <div class="text-center text-sm text-slate-500">
              {{ $t('Switch account?') }}
              <Link href="/logout" class="text-sm text-primary-600 dark:text-primary-500 border-b hover:border-gray-500">{{ $t('Logout') }}</Link>
            </div>
            <form @submit.prevent="submitForm()" class="mt-5">
              <div class="mt-5 space-y-4">
                <FormInput v-model="form.token" :name="$t('Verification Code')" :error="form.errors.token" :type="'text'" :class="'col-span-3'"/>
              </div>
              <div class="mt-6">
                <button
                  v-if="!isLoading"
                  type="submit"
                  class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full"
                >
                  {{ $t('Login to your account') }}
                </button>
                <button
                  v-else
                  type="button"
                  class="rounded-md bg-primary px-3 py-3 text-sm text-white shadow-sm w-full flex justify-center"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                  >
                    <path
                      fill="currentColor"
                      d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z"
                      opacity=".5"
                    />
                    <path
                      fill="currentColor"
                      d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"
                    >
                      <animateTransform
                        attributeName="transform"
                        dur="1s"
                        from="0 12 12"
                        repeatCount="indefinite"
                        to="360 12 12"
                        type="rotate"
                      />
                    </path>
                  </svg>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </template>
  <script setup>
  import FormInput from '@/Components/FormInput.vue';
import { useRtl } from '@/Composables/useRtl';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { defineProps, ref, watch } from 'vue';
import { toast } from 'vue3-toastify';

  const { rtlClass, isRtl } = useRtl();
  
  const props = defineProps(['flash', 'config', 'companyConfig']);
  const isLoading = ref(false);
  
  const form = useForm({
    token: null,
  });
  
  const getValueByKey = (key) => {
    const found = props.config.find((item) => item.key === key);
    return found ? found.value : '';
  };
  
  const submitForm = async (event) => {
    isLoading.value = true;
  
    form.post('/tfa', {
      onSuccess: () => form.reset(),
      onFinish: () => {
        isLoading.value = false;
      },
    });
  };
  
  watch(
    () => [usePage().props.flash, { deep: true }],
    () => {
      if (usePage().props.flash.status != null) {
        toast(usePage().props.flash.status.message, {
          autoClose: 3000,
        });
      }
    },
  );
</script>
  