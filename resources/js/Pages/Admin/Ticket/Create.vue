<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-0 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] overflow-y-scroll">
            <div class="md:flex justify-between hidden">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Create ticket') }}</h1>
                    <p class="mb-6 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Have an issue? Describe it in detail below') }}</span>
                    </p>
                </div>
                <div>
                    <Link href="/admin/support" class="flex items-center space-x-4 rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path fill="currentColor" fill-rule="evenodd" d="M5.841 5.28a.75.75 0 0 0-1.06-1.06L1.53 7.47L1 8l.53.53l3.25 3.25a.75.75 0 0 0 1.061-1.06l-1.97-1.97H14.25a.75.75 0 0 0 0-1.5H3.871l1.97-1.97Z" clip-rule="evenodd"/></svg>
                        {{ $t('Back') }}
                    </Link>
                </div>
            </div>
            <form @submit.prevent="submitForm()" class="bg-white py-5 px-5 rounded-[0.5rem] md:w-2/3">
                <div class="mt-5 mb-5 grid md:grid-cols-2 gap-x-8 gap-y-8">
                    <FormInput v-model="form.subject" :name="$t('Subject')" :type="'text'" :error="form.errors.subject" :class="'col-span-2'" :labelClass="'mb-0'"/>
                    <FormSelect v-model="form.category" :name="$t('Category')" :options="categoryOptions" :error="form.errors.category" :class="'col-span-2 md:col-span-1'" :placeholder="'Select Category'"/>
                    <FormSelectCombo v-model="user" :name="$t('User')" :loadOptions="loadUsers" :error="form.errors.user" :class="'col-span-2 md:col-span-1'" :placeholder="'Enter user email'"/>
                    <FormTextArea v-model="form.message" :name="$t('Description')" :type="'text'" :showLabel="true" :error="form.errors.message" :textAreaRows="5" :class="'col-span-2 mb-10'"/>
                </div>
                <div class="flex justify-end py-2">
                    <button type="submit" class="self-end flex items-center space-x-4 rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-slate-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Create ticket') }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from './../Layout/App.vue';
    import { Link, useForm } from "@inertiajs/vue3";
    import { ref, onMounted, watch } from 'vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormSelectCombo from '@/Components/FormSelectCombo.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';

    const props = defineProps(['title', 'categories']);
    const user = ref(null);
    const form = useForm({
        'subject' : null,
        'category' : null,
        'message' : null,
        'user' : null,
    });

    const categoryOptions = ref([]);
    const organizationOptions = ref([]);

    function loadUsers(query, setOptions) {
        fetch("/admin/users?search=" + query, {
                headers: {
                    'Accept': 'application/json' // Ensure that the request accepts JSON
                }
            })
            .then(response => response.json())
            .then(result => {
                setOptions(result.rows);
            })
            .catch(error => {
                console.error("Error fetching users:", error);
            });
    }

    const transformCategories = (categories) => {
        return categories.map((category) => ({
            value: category.id,
            label: category.name,
        }));
    };

    const submitForm = () => {
        form.post('/admin/support');
    }

    watch(user, (newValue) => {
        form.user = newValue?.value;
    });

    onMounted(() => {
        categoryOptions.value = transformCategories(props.categories);
    });
</script>