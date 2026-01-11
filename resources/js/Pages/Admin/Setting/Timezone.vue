<template>
    <AppLayout>
        <div>
            <h2 class="text-xl mb-1">{{ $t('Timezone and currency') }}</h2>
            <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                <span class="ml-1 mt-1">{{ $t('Configure timezone, date and time formats and currency') }}</span>
            </p>
        </div>
        <form @submit.prevent="submitForm()">
            <div class="space-y-12">
                <div class="pb-12">
                    <div class="grid gap-6 grid-cols-2 pb-10 border-b md:w-2/3">
                        <FormSelect v-model="form.timezone" :name="$t('Timezone')" :type="'text'"  :options="props.timezones" :error="form.errors.timezone" :class="'col-span-2'"/>
                        <FormSelect v-model="form.date_format" :name="$t('Date format')" :type="'text'"  :options="props.date_formats" :error="form.errors.date_format" :class="'col-span-1'"/>
                        <FormSelect v-model="form.time_format" :name="$t('Time format')" :type="'text'"  :options="timeFormatOptions" :error="form.errors.time_format" :class="'col-span-1'"/>
                        <FormSelect v-model="form.currency" :name="$t('Currency')" :type="'text'"  :options="props.currencies" :error="form.errors.currency" :class="'col-span-2'"/>
                    </div>
                    <div class="mt-6 flex items-center justify-end gap-x-6 md:w-2/3">
                        <button
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': isLoading }]"
                            :disabled="isLoading"
                        >
                            <svg v-if="isLoading" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./Layout/App.vue";
    import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
    import { useForm } from "@inertiajs/vue3";
    import BaseListbox from '@/Components/BaseListbox.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';

    const props = defineProps({
        config: {
            type: Object,
            required: true
        },
        timezones: {
            type: Array,
            required: true
        },
        date_formats: {
            type: Array,
            required: true
        },
        time_formats: {
            type: Array,
            required: true
        },
        currencies: {
            type: Array,
            required: true
        }
    });

    const getValueByKey = (key) => {
        const found = props.config.find(item => item.key === key);
        return found ? found.value : '';
    };

    const isLoading = ref(false);

    const form = useForm({
        timezone: getValueByKey('timezone'),
        currency: getValueByKey('currency'),
        date_format: getValueByKey('date_format'),
        time_format: getValueByKey('time_format'),
    })

    // Function to format time based on selected time format
    const formatTime = (date, timeFormat, timezone) => {
        if (!timeFormat) return date.toLocaleTimeString();
        
        // Map common time format options to JavaScript date formatting
        const formatMap = {
            'H:i:s': 'HH:mm:ss',
            'h:i:s A': 'hh:mm:ss a',
            'H:i': 'HH:mm',
            'h:i A': 'hh:mm a',
            '24': 'HH:mm:ss',
            '12': 'hh:mm:ss a'
        };
        
        const format = formatMap[timeFormat] || 'HH:mm:ss';
        
        return new Intl.DateTimeFormat('en-US', {
            timeZone: timezone || 'UTC',
            hour12: format.includes('a'),
            hour: format.includes('HH') ? '2-digit' : 'numeric',
            minute: '2-digit',
            second: format.includes('ss') ? '2-digit' : undefined
        }).format(date);
    };

    // Create dynamic time format options with actual time display
    const timeFormatOptions = computed(() => {
        if (!form.timezone) return props.time_formats;
        
        const now = new Date();
        return props.time_formats.map(format => {
            const timeInTimezone = formatTime(now, format.value, form.timezone);
            return {
                ...format,
                label: timeInTimezone
            };
        });
    });

    const submitForm = async () => {
        form.put('/admin/settings?type=timezone', {
            preserveScroll: true,
        });
    };
</script>