<template>
	<SettingLayout :modules="props.modules">
		<div class="md:min-h-[90vh]">
			<div class="flex justify-center items-start">
				<div class="md:w-[60em] w-full space-y-4 mb-8">
					<!-- الحالة -->
					<section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
						<div class="flex items-start justify-between gap-4">
							<div>
								<h4 class="text-[16px]">{{ $t('Web Chat') }}</h4>
								<p class="text-slate-500 mt-1">
									{{ $t('A chat window on your website. Visitors write to you, your automated replies answer them, and the conversation lands in your inbox.') }}
								</p>
							</div>
							<div class="flex items-center gap-2 shrink-0">
								<span class="text-slate-600">{{ form.enabled ? $t('Enabled') : $t('Disabled') }}</span>
								<FormToggleSwitch v-model="form.enabled" />
							</div>
						</div>
					</section>

					<!-- الكود -->
					<section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
						<h4 class="text-[15px]">{{ $t('Installation code') }}</h4>
						<p class="text-slate-500 mt-1 mb-3">
							{{ $t('Paste this code once on every page of your website, just before the closing body tag.') }}
						</p>
						<div class="relative">
							<pre dir="ltr" class="bg-slate-900 text-slate-100 rounded-md p-3 text-xs overflow-x-auto whitespace-pre-wrap break-all">{{ props.widget.snippet }}</pre>
							<button type="button" @click="copySnippet"
								class="absolute top-2 end-2 bg-white/10 hover:bg-white/20 text-white text-xs rounded px-2 py-1">
								{{ copied ? $t('Copied') : $t('Copy') }}
							</button>
						</div>

						<details class="mt-3">
							<summary class="cursor-pointer text-slate-700">{{ $t('How to install on your platform') }}</summary>
							<ul class="mt-2 space-y-2 text-slate-600 list-disc ps-5">
								<li><span class="font-medium">Salla:</span> {{ $t('webchat_install_salla') }}</li>
								<li><span class="font-medium">Zid:</span> {{ $t('webchat_install_zid') }}</li>
								<li><span class="font-medium">Shopify:</span> {{ $t('webchat_install_shopify') }}</li>
								<li><span class="font-medium">WordPress:</span> {{ $t('webchat_install_wordpress') }}</li>
							</ul>
						</details>

						<div class="mt-4 flex items-center justify-between gap-3 border-t border-slate-100 pt-3">
							<p class="text-slate-500 text-xs">{{ $t('If someone copied your code to another website, generate a new key. The old code stops working immediately.') }}</p>
							<button type="button" @click="confirmRotate = true"
								class="shrink-0 rounded-md border border-red-200 text-red-700 px-3 py-1.5 text-xs hover:bg-red-50">
								{{ $t('Generate a new key') }}
							</button>
						</div>
					</section>

					<!-- الشكل -->
					<section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
						<h4 class="text-[15px] mb-3">{{ $t('Appearance') }}</h4>
						<div class="grid md:grid-cols-2 gap-4">
							<label class="block">
								<span class="text-slate-700">{{ $t('Color') }}</span>
								<span class="mt-1 flex items-center gap-2">
									<input type="color" v-model="form.appearance.color" class="h-9 w-12 rounded border border-slate-300" />
									<input type="text" v-model="form.appearance.color" dir="ltr" maxlength="7" :class="inputClass('appearance.color')" />
								</span>
								<FieldError :message="form.errors['appearance.color']" />
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Position') }}</span>
								<select v-model="form.appearance.position" :class="inputClass('appearance.position')">
									<option value="right">{{ $t('Bottom right') }}</option>
									<option value="left">{{ $t('Bottom left') }}</option>
								</select>
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Horizontal offset (px)') }}</span>
								<input type="number" min="0" max="200" v-model.number="form.appearance.offset_x" :class="inputClass('appearance.offset_x')" />
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Vertical offset (px)') }}</span>
								<input type="number" min="0" max="200" v-model.number="form.appearance.offset_y" :class="inputClass('appearance.offset_y')" />
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Title') }}</span>
								<input type="text" maxlength="80" v-model="form.appearance.title" :placeholder="$t('Chat with us')" :class="inputClass('appearance.title')" />
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Subtitle') }}</span>
								<input type="text" maxlength="80" v-model="form.appearance.subtitle" :placeholder="$t('We usually reply in a few minutes')" :class="inputClass('appearance.subtitle')" />
							</label>
							<label class="block md:col-span-2">
								<span class="text-slate-700">{{ $t('Welcome message') }}</span>
								<textarea rows="2" maxlength="500" v-model="form.appearance.welcome_message" :class="inputClass('appearance.welcome_message')"></textarea>
								<span class="text-xs text-slate-500">{{ $t('Shown to the visitor before they write. It is not sent as a message.') }}</span>
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Language') }}</span>
								<select v-model="form.appearance.language" :class="inputClass('appearance.language')">
									<option value="auto">{{ $t('Same as the website') }}</option>
									<option value="ar">العربية</option>
									<option value="en">English</option>
								</select>
							</label>
							<div class="block">
								<span class="text-slate-700">{{ $t('Logo') }}</span>
								<div class="mt-1 flex items-center gap-3">
									<img v-if="props.widget.logo" :src="props.widget.logo" alt="" class="h-10 w-10 rounded-full object-cover border border-slate-200" />
									<label class="cursor-pointer rounded-md border border-slate-300 px-3 py-1.5 text-xs hover:bg-slate-50">
										{{ props.widget.logo ? $t('Change') : $t('Upload') }}
										<input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="uploadLogo" />
									</label>
									<button v-if="props.widget.logo" type="button" class="text-xs text-red-700" @click="removeLogo">{{ $t('Remove') }}</button>
								</div>
								<span class="text-xs text-slate-500">{{ $t('PNG, JPG or WEBP, up to 1 MB.') }}</span>
							</div>
						</div>
					</section>

					<!-- نموذج الزائر -->
					<section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
						<h4 class="text-[15px]">{{ $t('Visitor form') }}</h4>
						<p class="text-slate-500 mt-1 mb-3">{{ $t('A name is always required before the visitor can write.') }}</p>
						<div class="grid md:grid-cols-2 gap-4">
							<label class="block">
								<span class="text-slate-700">{{ $t('Default country code') }}</span>
								<input type="text" maxlength="2" dir="ltr" v-model="form.prechat.default_country" placeholder="SA"
									@input="form.prechat.default_country = form.prechat.default_country.toUpperCase()" :class="inputClass('prechat.default_country')" />
								<FieldError :message="form.errors['prechat.default_country']" />
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Mobile field') }}</span>
								<select v-model="form.prechat.phone" :class="inputClass('prechat.phone')">
									<option value="off">{{ $t('Hidden') }}</option>
									<option value="optional">{{ $t('Optional') }}</option>
									<option value="required">{{ $t('Required') }}</option>
								</select>
								<FieldError :message="form.errors['prechat.phone']" />
								<span v-if="form.prechat.phone !== 'required'" class="block text-xs text-amber-700 mt-1">
									{{ $t('Without a mobile number you can reach the visitor only when they return to your site.') }}
								</span>
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Email field') }}</span>
								<select v-model="form.prechat.email" :class="inputClass('prechat.email')">
									<option value="off">{{ $t('Hidden') }}</option>
									<option value="optional">{{ $t('Optional') }}</option>
									<option value="required">{{ $t('Required') }}</option>
								</select>
							</label>
							<label class="block">
								<span class="text-slate-700">{{ $t('Name field label') }}</span>
								<input type="text" maxlength="60" v-model="form.prechat.name_label" :placeholder="$t('Your name')" :class="inputClass('prechat.name_label')" />
							</label>
							<label v-if="form.prechat.phone !== 'off'" class="block">
								<span class="text-slate-700">{{ $t('Mobile field label') }}</span>
								<input type="text" maxlength="60" v-model="form.prechat.phone_label" :placeholder="$t('Mobile number')" :class="inputClass('prechat.phone_label')" />
							</label>
							<label class="block md:col-span-2">
								<span class="text-slate-700">{{ $t('Consent text (optional)') }}</span>
								<textarea rows="2" maxlength="500" v-model="form.prechat.consent_text" :class="inputClass('prechat.consent_text')"></textarea>
								<span class="text-xs text-slate-500">{{ $t('When filled, the visitor must tick it before starting the chat.') }}</span>
							</label>
						</div>
					</section>

					<!-- السلوك والمواقع -->
					<section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
						<h4 class="text-[15px] mb-3">{{ $t('Behavior') }}</h4>
						<div class="space-y-3">
							<div class="flex items-center justify-between gap-4">
								<span class="text-slate-700">{{ $t('Show the agent first name to the visitor') }}</span>
								<FormToggleSwitch v-model="form.behavior.show_agent_names" />
							</div>
							<div class="flex items-center justify-between gap-4">
								<span class="text-slate-700">{{ $t('Play a sound for new replies') }}</span>
								<FormToggleSwitch v-model="form.behavior.sound" />
							</div>
							<div class="flex items-center justify-between gap-4">
								<span class="text-slate-700">{{ $t('Let visitors send files') }}</span>
								<FormToggleSwitch v-model="form.behavior.uploads" />
							</div>
						</div>

						<label class="block mt-5">
							<span class="text-slate-700">{{ $t('Allowed websites') }}</span>
							<textarea rows="3" dir="ltr" v-model="domainsText" placeholder="example.com&#10;*.example.com" :class="inputClass('allowed_domains')"></textarea>
							<span class="text-xs text-slate-500">{{ $t('One domain per line. Leave empty to allow any website. A star allows all subdomains.') }}</span>
						</label>
					</section>

					<!-- معاينة حيّة: الـ widget نفسه بإعداداته المحفوظة — والمحادثة فيها حقيقية -->
					<section class="bg-white border border-slate-200 rounded-lg p-4 text-sm">
						<div class="flex items-center justify-between mb-2">
							<h4 class="text-[15px]">{{ $t('Preview') }}</h4>
							<button type="button" class="text-xs text-slate-600 underline" @click="previewKey++">{{ $t('Refresh') }}</button>
						</div>
						<p class="text-slate-500 text-xs mb-3">{{ $t('The preview shows the saved settings. Messages you send here reach your inbox like a real visitor.') }}</p>
						<iframe :key="previewKey" :src="props.widget.preview_url" :title="$t('Preview')"
							class="w-full h-[640px] rounded-lg border border-slate-200 bg-slate-50" loading="lazy"></iframe>
					</section>

					<div class="flex justify-end">
						<button type="button" @click="save" :disabled="form.processing"
							class="rounded-md bg-primary px-5 py-2 text-sm text-white shadow-sm disabled:opacity-50">
							{{ form.processing ? $t('Saving...') : $t('Save') }}
						</button>
					</div>
				</div>
			</div>
		</div>

		<Modal :show="confirmRotate" @close="confirmRotate = false" max-width="md">
			<div class="p-5 text-sm">
				<h3 class="text-base font-medium text-slate-800">{{ $t('Generate a new key?') }}</h3>
				<p class="mt-2 text-slate-600">{{ $t('The code currently on your website will stop working right away. You must paste the new code on your website after this.') }}</p>
				<div class="mt-5 flex justify-end gap-2">
					<button type="button" class="rounded-md border border-slate-300 px-3 py-1.5" @click="confirmRotate = false">{{ $t('Cancel') }}</button>
					<button type="button" class="rounded-md bg-red-600 px-3 py-1.5 text-white" @click="rotateKey">{{ $t('Generate a new key') }}</button>
				</div>
			</div>
		</Modal>
	</SettingLayout>
</template>

<script setup>
import SettingLayout from './Layout.vue'
import Modal from '@/Components/Modal.vue'
import FormToggleSwitch from '@/Components/FormToggleSwitch.vue'
import { computed, defineComponent, h, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

const props = defineProps({
	modules: Array,
	widget: { type: Object, required: true },
})

const settings = props.widget.settings

const form = useForm({
	enabled: settings.enabled,
	allowed_domains: settings.allowed_domains,
	appearance: {
		color: settings.appearance.color,
		position: settings.appearance.position,
		offset_x: settings.appearance.offset_x,
		offset_y: settings.appearance.offset_y,
		title: settings.appearance.title,
		subtitle: settings.appearance.subtitle,
		welcome_message: settings.appearance.welcome_message,
		language: settings.appearance.language,
	},
	prechat: { ...settings.prechat },
	behavior: { ...settings.behavior },
})

/** سطر لكل دومين في الواجهة، ومصفوفة في الطلب. */
const domainsText = computed({
	get: () => (form.allowed_domains || []).join('\n'),
	set: (value) => {
		form.allowed_domains = value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
	},
})

const inputClass = (key) => [
	'mt-1 block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm ring-1 ring-inset sm:text-sm',
	form.errors[key] ? 'ring-[#b91c1c]' : 'ring-gray-300',
]

const FieldError = defineComponent({
	props: { message: String },
	setup: (p) => () => (p.message ? h('span', { class: 'block text-[#b91c1c] text-xs mt-1' }, p.message) : null),
})

const previewKey = ref(0)

const save = () => {
	form.post('/settings/webchat', {
		preserveScroll: true,
		// المعاينة تُعاد بعد الحفظ لترى الشكل الجديد.
		onSuccess: () => previewKey.value++,
	})
}

const copied = ref(false)
const copySnippet = async () => {
	try {
		await navigator.clipboard.writeText(props.widget.snippet)
		copied.value = true
		setTimeout(() => (copied.value = false), 2000)
	} catch {
		// الحافظة محجوبة في سياق غير آمن (http): الكود ظاهر ويُحدَّد يدوياً.
	}
}

const confirmRotate = ref(false)
const rotateKey = () => {
	confirmRotate.value = false
	router.post('/settings/webchat/rotate-key', {}, { preserveScroll: true })
}

const uploadLogo = (event) => {
	const file = event.target.files?.[0]
	if (!file) return
	router.post('/settings/webchat/logo', { logo: file }, { forceFormData: true, preserveScroll: true })
	event.target.value = ''
}

const removeLogo = () => {
	router.delete('/settings/webchat/logo', { preserveScroll: true })
}
</script>
