<template>
	<AppLayout>
		<div class="bg-white md:bg-inherit md:flex md:flex-grow capitalize">
			<div class="md:w-[30%] md:flex flex-col h-full bg-white border-r border-l"
				:class="category ? 'hidden' : ''">
				<div class="px-4 pt-4">
					<div class="flex justify-between mt-2">
						<div class="flex space-x-1 text-xl">
							<h2>{{ $t('Categories') }}</h2>
							<span class="text-slate-500 ">{{ props.rowCount }}</span>
						</div>
						<div class="flex space-x-2 items-center">
							<span @click="openModal()" class="cursor-pointer" title="Add Category">
								<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24">
									<g fill="currentColor" fill-rule="evenodd" clip-rule="evenodd">
										<path
											d="M2 12C2 6.477 6.477 2 12 2s10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12Zm10-8a8 8 0 1 0 0 16a8 8 0 0 0 0-16Z" />
										<path
											d="M13 7a1 1 0 1 0-2 0v4H7a1 1 0 1 0 0 2h4v4a1 1 0 1 0 2 0v-4h4a1 1 0 1 0 0-2h-4V7Z" />
									</g>
								</svg>
							</span>
						</div>
					</div>
				</div>
				<ContactTable :rows="props.rows" :filters="props.filters" :type="'category'"
					:contactCategoriesEnabled="true" @callback="handleCategory" />
			</div>
			<div class="md:w-[70%] bg-cover md:h-[100vh] flex justify-center overflow-y-scroll ">
				<div v-if="category">
					<ContactCategoryInfo :category="category" />
				</div>
				<div v-else class="md:block pt-20 hidden">
					<div class="border py-10 w-[30em] rounded-xl bg-white">
						<h2 class="text-center text-2xl text-slate-500 mb-6">{{ $t('Select category') }}</h2>
						<div class="flex justify-center">
							<div class="border-r border-slate-500 h-10"></div>
						</div>
						<h2 class="text-center text-slate-600">{{ $t('Or') }}</h2>
						<div class="flex justify-center">
							<div class="border-r border-slate-500 h-10"></div>
						</div>
						<div class="flex justify-center mt-6">
							<button @click="openModal()"
								class="bg-primary rounded-lg text-sm text-white p-2 px-8 text-center capitalize">{{ $t('Add category') }}</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</AppLayout>
	<FormModal v-model="isOpenFormModal" :label="$t('Add category')" :url="formUrl" :form="form"
		:formInputs="formInputs" @callback="handleCallback" />
</template>
<script setup>
import AppLayout from "./../Layout/App.vue"
import { ref, watchEffect } from 'vue'
import ContactCategoryInfo from '@/Components/ContactCategoryInfo.vue'
import ContactTable from '@/Components/Tables/ContactTable.vue'
import FormModal from '@/Components/FormModal.vue'
import { router } from '@inertiajs/vue3'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

const props = defineProps({ rows: Object, filters: Object, rowCount: Number, category: Object })
const category = ref(props.category)
watchEffect(() => { category.value = props.category })

const isOpenFormModal = ref(false)
const currentUrl = window.location.href
const formUrl = ref(currentUrl)
const form = ref({
	name: '',
	background_color: '#22c55e',
	text_color: '#ffffff',
})

const initialFormInputs = [
	{
		inputType: 'FormInput',
		name: 'name',
		label: trans('name'),
		type: 'text',
		className: 'sm:col-span-6',
	},
	{
		inputType: 'FormInput',
		name: 'background_color',
		label: trans('Background color'),
		type: 'color',
		className: 'sm:col-span-3',
	},
	{
		inputType: 'FormInput',
		name: 'text_color',
		label: trans('Text color'),
		type: 'color',
		className: 'sm:col-span-3',
	},
]

const formInputs = initialFormInputs

const openModal = () => {
	isOpenFormModal.value = true
	form.value.name = ''
	form.value.background_color = '#22c55e'
	form.value.text_color = '#ffffff'
}

const handleCategory = (value) => {
	router.visit('/contact-categories', {
		method: 'get',
		data: value,
	})
}

const handleCallback = (res) => {
	category.value = res.data
	form.value.name = res.data.name
	form.value.background_color = res.data.background_color ?? '#22c55e'
	form.value.text_color = res.data.text_color ?? '#ffffff'
}
</script>
