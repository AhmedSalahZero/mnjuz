<script setup>
    import { ref } from 'vue';
    import { Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
    import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline';

    const props = defineProps({
        modelValue: Boolean,
        label: String,
        description: String,
        confirmButtonText: {
            type: String,
            default: 'Confirm'
        },
        confirmButtonClass: {
            type: String,
            default: 'bg-red-600 hover:bg-red-500'
        },
        icon: {
            type: String,
            default: 'warning' // 'warning' or 'info'
        }
    })

    const isLoading = ref(false);
    const emit = defineEmits(['update:modelValue', 'confirm']);

    function confirm () {
        isLoading.value = true;
        setTimeout(() => {
            emit('confirm');
            isLoading.value = false;
        }, 1000);
    }

    function onClose() {
        emit('update:modelValue', false);
    }
</script>
<template>
    <TransitionRoot as="template" :show="modelValue">
        <Dialog as="div" class="relative z-10" @close="onClose">
            <TransitionChild as="template" enter="ease-out duration-300" enter-from="opacity-0" enter-to="opacity-100" leave="ease-in duration-200" leave-from="opacity-100" leave-to="opacity-0">
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" />
            </TransitionChild>

            <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
                <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                    <TransitionChild as="template" enter="ease-out duration-300" enter-from="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" enter-to="opacity-100 translate-y-0 sm:scale-100" leave="ease-in duration-200" leave-from="opacity-100 translate-y-0 sm:scale-100" leave-to="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95">
                        <DialogPanel class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                            <div class="bg-white px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                                <div class="sm:flex sm:items-start">
                                    <div
                                        :class="[
                                            'mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full sm:mx-0 sm:h-10 sm:w-10',
                                            icon === 'warning' ? 'bg-red-100' : 'bg-blue-100'
                                        ]">
                                        <ExclamationTriangleIcon 
                                            :class="[
                                                'h-6 w-6', 
                                                icon === 'warning' ? 'text-red-600' : 'text-blue-600'
                                            ]" 
                                            aria-hidden="true" 
                                        />
                                    </div>
                                    <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                                        <DialogTitle as="h3" class="text-base font-semibold leading-6 text-gray-900">{{ props.label }}</DialogTitle>
                                        <div class="mt-2">
                                            <p class="text-sm text-gray-500">{{ props.description }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                                <button v-if="!isLoading" type="button"
                                    :class="[
                                        'inline-flex w-full justify-center rounded-md px-3 py-2 text-sm font-semibold text-white shadow-sm sm:ml-3 sm:w-auto',
                                        confirmButtonClass
                                    ]"
                                    @click="confirm">
                                    {{ confirmButtonText }}
                                </button>
                                <button v-else type="button" class="inline-flex w-full justify-center rounded-md bg-gray-400 px-3 py-2 text-sm font-semibold text-white sm:ml-3 sm:w-auto">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                </button>
                                <button type="button" class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto" @click.self="onClose" ref="cancelButtonRef">{{ $t('Cancel') }}</button>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </div>
        </Dialog>
    </TransitionRoot>
</template>
  