<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="flex justify-between mt-8 md:mt-0">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Ticket ref') }}: {{ props.ticket.reference }}</h1>
                </div>
                <div>
                    <Link href="/admin/support" class="flex items-center space-x-4 rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path fill="currentColor" fill-rule="evenodd" d="M5.841 5.28a.75.75 0 0 0-1.06-1.06L1.53 7.47L1 8l.53.53l3.25 3.25a.75.75 0 0 0 1.061-1.06l-1.97-1.97H14.25a.75.75 0 0 0 0-1.5H3.871l1.97-1.97Z" clip-rule="evenodd"/></svg>
                        {{ $t('Back') }}
                    </Link>
                </div>
            </div>

            <div class="grid grid-cols-2 md:flex gap-x-6 mt-4 md:mt-10">
                <div class="col-span-2 md:order-1 md:w-[70%]">
                    <div class="bg-white md:border py-5 md:px-5 rounded-[0.5rem] mb-4 text-sm">
                        <h1 class="text-xl">{{ $t('Subject') }}: {{ props.ticket.subject }}</h1>
                        <div class="border border-dashed py-2 px-2 mt-8 bg-slate-100">{{ props.ticket.message }}</div>
                    </div>
                    <div v-if="props.ticket.status === 'open' || props.ticket.status === 'pending'" class="bg-white border py-5 px-5 rounded-[0.5rem] mb-4">
                        <form @submit.prevent="submitForm()">
                            <FormTextArea v-model="form.message" :name="$t('Comment')" :type="'text'" :showLabel="true" :error="form.errors.message" :textAreaRows="3" :class="'sm:col-span-6 mb-5'"/>
                            <button type="submit" class="mb-2 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">{{ $t('Add comment') }}</button>
                        </form>
                    </div>

                    <div v-for="(item, index) in props.ticket.comments_with_user" :key="index" class="bg-white border py-5 px-5 rounded-[0.5rem] mb-2">
                        <div class="flex space-x-4 text-sm">
                            <div>
                                <div class="bg-slate-100 rounded-full h-12 w-12 p-4 flex justify-center items-center">
                                    {{ getInitials(item.user.first_name, item.user.last_name) }}
                                </div>
                            </div>
                            <div>
                                <div class="space-x-4 mb-1">
                                    <span>{{ item.user.first_name + ' ' + item.user.last_name }}</span>
                                    <span>{{ formatDateTime(item.created_at) }}</span>
                                </div>
                                <div>{{ item.message }}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-span-2 w-[100%] md:order-2 md:w-[30%]">
                    <div class="bg-white md:border md:p-4 text-sm rounded-[0.5rem]">
                        <div class="bg-slate-100 p-2 rounded mb-2 space-x-3">
                            <span>{{ $t('Category') }}:</span>
                            <span class="capitalize">{{ props.ticket.category.name }}</span>
                        </div>
                        <div class="bg-slate-100 p-2 rounded mb-2 space-x-3">
                            <span>{{ $t('Status') }}:</span>
                            <span class="capitalize">{{ props.ticket.status }}</span>
                        </div>
                        <div class="bg-slate-100 p-2 rounded mb-2 space-x-3">
                            <span>{{ $t('Priority') }}:</span>
                            <span v-if="props.ticket.status === 'open' || props.ticket.status === 'pending'" class="capitalize">
                                <FormSelect v-model="priority" @update:modelValue="updatePriority" :options="priorityOptions" :class="'w-full'" :placeholder="'Not Set'"/>
                            </span>
                            <span v-else class="capitalize">{{ props.ticket.priority }}</span>
                        </div>
                        <div class="bg-slate-100 p-2 rounded mb-2 space-x-3">
                            <span>{{ $t('Assigned to') }}:</span>
                            <span v-if="props.ticket.status === 'open' || props.ticket.status === 'pending'" class="capitalize">
                                <FormSelect v-model="user" @update:modelValue="updateUser" :options="userOptions" :class="'w-full'" :placeholder="'Select User'"/>
                            </span>
                            <span v-else class="capitalize">{{ props.ticket?.agent ? props.ticket?.agent?.first_name + ' ' + props.ticket?.agent?.last_name : $t('Not set') }}</span>
                        </div>
                        <div class="bg-slate-100 p-2 rounded mb-2 space-x-3">
                            <span>{{ $t('Date created') }}:</span>
                            <span>{{ formatDateTime(props.ticket.created_at) }}</span>
                        </div>
                        <div v-if="props.ticket.status === 'open' || props.ticket.status === 'pending'" class="flex grid grid-cols-2 space-x-2 mt-4">
                            <button @click="changeTicketStatus('closed')" class="mb-2 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">{{ $t('Close ticket') }}</button>
                            <button @click="changeTicketStatus('resolved')" class="mb-2 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">{{ $t('Mark as resolved') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from './../Layout/App.vue';
    import axios from 'axios';
    import { Link, router, useForm } from "@inertiajs/vue3";
    import { ref, onMounted, onBeforeUnmount, watch } from 'vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';

    const props = defineProps(['title', 'ticket', 'users']);
    const priority = ref(props.ticket.priority);
    const user = ref(props.ticket.assigned_to);
    const userOptions = ref([]);
    const form = useForm({
        'message' : null,
    });
    const priorityOptions = ref([
        { value: 'critical', label: 'Critical' },
        { value: 'high', label: 'High' },
        { value: 'medium', label: 'Medium' },
        { value: 'low', label: 'Low' },
    ])

    const formatDateTime = (value) => {
        const options = { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' };
        return new Date(value).toLocaleString('en-US', options);
    };

    const getInitials = (firstName, lastName) => {
      const firstInitial = firstName.charAt(0).toUpperCase();
      const lastInitial = lastName.charAt(0).toUpperCase();

      return `${firstInitial}${lastInitial}`;
    }

    const submitForm = () => {
        form.post('/admin/support/' + props.ticket.uuid + '/comment', {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    const changeTicketStatus = (status) => {
        router.post('/admin/support/' + props.ticket.uuid + '/status', { status: status });
    }

    const transformUsers = (users) => {
        return users.map((user) => ({
            value: user.id,
            label: user.first_name + ' ' + user.last_name,
        }));
    };

    const updateUser = async(value) => {
        try {
            const response = await axios.post('/admin/support/' + props.ticket.uuid + '/assign', { user: value });
            //console.log('Response:', response.data);
        } catch (error) {
            //console.error('Error making POST request:', error);
        }
    }

    const updatePriority = async(value) => {
        try {
            const response = await axios.post('/admin/support/' + props.ticket.uuid + '/priority', { priority: value });
            //console.log('Response:', response.data);
        } catch (error) {
            //console.error('Error making POST request:', error);
        }
    }

    onMounted(() => {
        userOptions.value = transformUsers(props.users);
    });
</script>