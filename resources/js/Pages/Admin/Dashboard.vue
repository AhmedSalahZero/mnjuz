<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit p-4 md:p-8 rounded-[5px] text-[#000] h-full overflow-y-auto">
            <div class="flex justify-between mt-3 md:mt-0">
                <div>
                    <h2 class="md:block hidden text-xl mb-1">{{ $t('Dashboard') }}</h2>
                    <p class="mb-6 flex items-center leading-6">
                        <span class="mt-1 font-semibold md:font-normal text-xl">{{ $t('Welcome back') }}, {{ user?.first_name }} 👋</span>
                    </p>
                </div>
            </div>
            <div class="md:flex space-x-2 md:space-x-2 mt-4 md:mt-0 mb-8 text-xl md:text-sm hidden">
                <Link href="/admin/organizations/create" class="bg-primary py-2 px-3 rounded-lg text-white text-center">{{ $t('Add organization') }}</Link>
                <Link href="/admin/users/create" class="bg-primary py-2 px-3 rounded-lg text-white text-center">{{ $t('Add user') }}</Link>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-2 md:space-y-0">
                <div class="bg-slate-100 md:bg-white col-span-2 md:col-span-1 rounded-lg p-3">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-slate-600">{{ $t('Total revenue') }}</h2>
                            <h1 class="text-xl text-gray-600">{{ props.totalRevenue }}</h1>
                        </div>
                        <div class="flex">
                            <span class="bg-secondary/10 p-3 rounded-full self-start">
                                <svg class="text-secondary" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12c0-4.714 0-7.071 1.464-8.536C4.93 2 7.286 2 12 2c4.714 0 7.071 0 8.535 1.464C22 4.93 22 7.286 22 12c0 4.714 0 7.071-1.465 8.535C19.072 22 16.714 22 12 22s-7.071 0-8.536-1.465C2 19.072 2 16.714 2 12Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m7 14l2.293-2.293a1 1 0 0 1 1.414 0l1.586 1.586a1 1 0 0 0 1.414 0L17 10m0 0v2.5m0-2.5h-2.5"/></g></svg>
                            </span>
                        </div>
                    </div>
                    <div class="text-sm space-x-1 md:block hidden">
                        <Link href="/admin/payment-logs" class="flex items-center space-x-1 underline">
                            <span>{{ $t('View all') }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m8 16l8-8m0 0h-6m6 0v6"></path></svg>
                        </Link>
                    </div>
                </div>
                <div class="bg-slate-100 md:bg-white col-span-1 rounded-lg p-3">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-slate-600">{{ $t('Active users') }}</h2>
                            <h1 class="text-xl text-gray-600">{{ props.userCount }}</h1>
                        </div>
                        <div class="flex">
                            <span class="bg-secondary/10 p-3 rounded-full self-start">
                                <svg class="text-secondary" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12c0-4.714 0-7.071 1.464-8.536C4.93 2 7.286 2 12 2c4.714 0 7.071 0 8.535 1.464C22 4.93 22 7.286 22 12c0 4.714 0 7.071-1.465 8.535C19.072 22 16.714 22 12 22s-7.071 0-8.536-1.465C2 19.072 2 16.714 2 12Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m7 14l2.293-2.293a1 1 0 0 1 1.414 0l1.586 1.586a1 1 0 0 0 1.414 0L17 10m0 0v2.5m0-2.5h-2.5"/></g></svg>
                            </span>
                        </div>
                    </div>
                    <div class="text-sm space-x-1 md:block hidden">
                        <Link href="/admin/users" class="flex items-center space-x-1 underline">
                            <span>{{ $t('View all') }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m8 16l8-8m0 0h-6m6 0v6"></path></svg>
                        </Link>
                    </div>
                </div>
                <div class="bg-slate-100 md:bg-white rounded-lg p-3">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-slate-600">{{ $t('Open tickets') }}</h2>
                            <h1 class="text-xl text-gray-600">{{ props.openTickets }}</h1>
                        </div>
                        <div class="flex">
                            <span class="bg-secondary/10 p-3 rounded-full self-start">
                                <svg class="text-secondary" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12c0-4.714 0-7.071 1.464-8.536C4.93 2 7.286 2 12 2c4.714 0 7.071 0 8.535 1.464C22 4.93 22 7.286 22 12c0 4.714 0 7.071-1.465 8.535C19.072 22 16.714 22 12 22s-7.071 0-8.536-1.465C2 19.072 2 16.714 2 12Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m7 14l2.293-2.293a1 1 0 0 1 1.414 0l1.586 1.586a1 1 0 0 0 1.414 0L17 10m0 0v2.5m0-2.5h-2.5"/></g></svg>
                            </span>
                        </div>
                    </div>
                    <div class="text-sm space-x-1 md:block hidden">
                        <Link href="/admin/support" class="flex items-center space-x-1 underline">
                            <span>{{ $t('View all') }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m8 16l8-8m0 0h-6m6 0v6"></path></svg>
                        </Link>
                    </div>
                </div>
                <div class="bg-slate-100 col-span-2 md:col-span-1 md:bg-white rounded-lg p-3">
                    <div class="flex justify-between items-center">
                        <div>
                            <h2 class="text-slate-600">{{ $t('Total messages') }}</h2>
                            <h1 class="text-xl text-gray-600">{{ props.totalMessages }}</h1>
                        </div>
                        <div class="flex">
                            <span class="bg-secondary/10 p-3 rounded-full self-start">
                                <svg class="text-secondary" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 12c0-4.714 0-7.071 1.464-8.536C4.93 2 7.286 2 12 2c4.714 0 7.071 0 8.535 1.464C22 4.93 22 7.286 22 12c0 4.714 0 7.071-1.465 8.535C19.072 22 16.714 22 12 22s-7.071 0-8.536-1.465C2 19.072 2 16.714 2 12Z"/><path stroke-linecap="round" stroke-linejoin="round" d="m7 14l2.293-2.293a1 1 0 0 1 1.414 0l1.586 1.586a1 1 0 0 0 1.414 0L17 10m0 0v2.5m0-2.5h-2.5"/></g></svg>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="md:grid md:grid-cols-2 gap-x-4 mt-8">
                <div>
                    <div id="chart" class="md:block hidden">
                        <apexchart type="area" height="350" :options="chartOptions" :series="series"></apexchart>
                    </div>
                </div>
                <div class="bg-white rounded-lg py-4 md:px-4 h-[fit-content]">
                    <h1>{{ $t('Recent transactions') }}</h1>
                    <p class="text-sm">{{ $t('Below are your most recent transactions') }}</p>
                    <div v-if="props.payments.length === 0" class="md:p-5 border-2 border-dashed mt-4">
                        <p class="text-sm text-center">{{ $t('You do not have any transactions') }}</p>
                    </div>
                    <div v-else class="space-y-1">
                        <div v-for="(item, index) in props.payments.data" :key="index" class="mt-4 border-l-4 bg-gray-50 flex justify-between p-2 text-sm">
                            <div class="capitalize">{{ item.organization.name }}</div>
                            <div class="self-end">
                                <span class="bg-[#ffe5b4] p-1 rounded-md text-xs px-2 capitalize">{{ item.amount }}</span>
                            </div>
                        </div>
                        <Link href="/admin/payment-logs" class="flex items-center space-x-1 underline pt-3 text-sm">
                            <span>{{ $t('View all') }}</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m8 16l8-8m0 0h-6m6 0v6"></path></svg>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./Layout/App.vue";
    import { computed, defineProps, ref, onMounted } from "vue";
    import { Link, usePage } from "@inertiajs/vue3";

    const user = computed(() => usePage().props.auth.user);

    // Check for language refresh parameter and refresh page if needed
    onMounted(() => {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('refresh_lang') === '1') {
            // Remove the parameter and refresh to apply user's language
            urlParams.delete('refresh_lang');
            const newUrl = window.location.pathname + (urlParams.toString() ? '?' + urlParams.toString() : '');
            window.history.replaceState({}, '', newUrl);
            window.location.reload();
        }
    });

    const props = defineProps({
        title: { type: String},
        payments: { type: Object },
        totalRevenue: { type: String },
        userCount: { type: Number },
        openTickets: { type: Number },
        totalMessages: { type: Number },
        period: { type: Object },
        newUsers: { type: Object },
        revenue: { type: Object },
    });

    const chartOptions = {
        chart: {
            height: 350,
            type: 'area',
            toolbar: {
                show: false
            },
            zoom: {
                enabled: false
            }
        },
        colors: ['#418207'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 1,
                opacityFrom: 0.7,
                opacityTo: 0.9,
                stops: [0, 90, 100]
            }
        },
        dataLabels: {
            enabled: false
        },
        stroke: {
            width: 1,
            curve: 'smooth'
        },
        xaxis: {
            type: 'datetime',
            categories: props.period
        },
        tooltip: {
            x: {
                format: 'dd/MM/yy HH:mm'
            },
        },
    };

    const series = [
        {
            name: 'New Customers',
            data: props.newUsers
        }, {
            name: 'Revenue',
            data: props.revenue
        }
    ];
</script>