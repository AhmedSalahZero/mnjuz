<script setup>
    const props = defineProps({
        methods: {
            type: Array,
            required: true,
        },
        modelValue: {
            type: String,
            default: null,
        },
    });

    const emit = defineEmits(['update:modelValue']);

    const selectPayment = (method) => {
        emit('update:modelValue', method);
    };

    const isMyFatoorah = (name) => name?.toLowerCase() === 'myfatoorah';

    const saudiMethods = [
        { key: 'mada', label: 'Mada' },
        { key: 'visa', label: 'Visa' },
        { key: 'mastercard', label: 'Mastercard' },
        { key: 'applepay', label: 'Apple Pay' },
        { key: 'stcpay', label: 'STC Pay' },
    ];
</script>

<template>
    <div>
        <div v-if="!methods || methods.length === 0" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-medium mb-1">{{ $t('No payment methods available') }}</p>
            <p class="text-amber-800">{{ $t('Please contact your administrator to activate a payment gateway.') }}</p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div
            v-for="(item, index) in methods"
            :key="index"
            @click="selectPayment(item.name)"
            class="rounded-xl border-2 p-4 cursor-pointer transition-all"
            :class="modelValue === item.name ? 'border-gray-800 bg-slate-50 shadow-sm' : 'border-slate-200 hover:border-slate-300 bg-white'"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <div
                            class="w-5 h-5 border border-gray-400 rounded-md flex items-center justify-center shrink-0"
                            :class="modelValue === item.name ? 'bg-[#000]' : ''"
                        >
                            <svg
                                v-if="modelValue === item.name"
                                class="w-4 h-4 text-white"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <span class="text-sm font-medium">{{ item.name }}</span>
                    </div>

                    <p v-if="isMyFatoorah(item.name)" class="text-xs text-gray-500 mt-2 leading-5">
                        {{ $t('Secure checkout in SAR with Saudi payment methods') }}
                    </p>
                </div>

                <span
                    v-if="isMyFatoorah(item.name)"
                    class="text-[10px] uppercase tracking-wide bg-emerald-100 text-emerald-700 px-2 py-1 rounded-full shrink-0"
                >
                    SAR
                </span>
            </div>

            <div v-if="isMyFatoorah(item.name)" class="flex flex-wrap gap-2 mt-3">
                <span
                    v-for="method in saudiMethods"
                    :key="method.key"
                    class="text-[11px] px-2 py-1 rounded-md bg-gray-100 text-gray-700"
                >
                    {{ method.label }}
                </span>
            </div>
        </div>
        </div>
    </div>
</template>
