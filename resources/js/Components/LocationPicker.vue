<script setup>
/**
 * منتقي موقع على خريطة جوجل: بحث بالاسم، ومؤشّر يُسحب أو يُنقر لتحديد النقطة.
 *
 * يُستخدم في موضعين — إعدادات المنشأة لحفظ موقع النشاط، وملحن المحادثة لاختيار
 * نقطة تُرسل للعميل — فبقي مكوّناً واحداً بلا منطق خاص بأيّهما.
 *
 * لا نعتمد على Geocoding API إطلاقاً: مفتاح المنصة لا يُفعّلها، وسحب المؤشّر
 * وحده لا يُنتج عنواناً نصّياً. الاسم والعنوان يأتيان من نتيجة البحث أو يكتبهما
 * المستخدم بنفسه.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { GoogleMap, Marker } from 'vue3-google-map'
import { useTrans } from '@/Composables/useTrans'

const trans = useTrans()

const props = defineProps({
	apiKey: { type: String, default: '' },
	modelValue: { type: Object, default: null },
	height: { type: String, default: '320px' },
	// مركز افتراضي حين لا يوجد موقع محفوظ بعد — الرياض.
	defaultCenter: { type: Object, default: () => ({ lat: 24.7136, lng: 46.6753 }) },
	showTextFields: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue'])

const mapRef = ref(null)
const searchInput = ref(null)
const autocomplete = ref(null)
const searchQuery = ref('')

const hasKey = computed(() => !!props.apiKey)

const position = computed(() => {
	const value = props.modelValue
	if (!value || value.latitude === null || value.latitude === undefined) return null
	const lat = Number(value.latitude)
	const lng = Number(value.longitude)
	if (Number.isNaN(lat) || Number.isNaN(lng)) return null
	return { lat, lng }
})

const center = computed(() => position.value ?? props.defaultCenter)

const emitLocation = (patch) => {
	emit('update:modelValue', {
		latitude: null,
		longitude: null,
		name: '',
		address: '',
		...(props.modelValue ?? {}),
		...patch,
	})
}

const setPoint = (lat, lng) => {
	// نُقرّب إلى ثماني خانات: هي دقّة السنتيمتر، وما بعدها ضوضاء تُطيل الحمولة.
	emitLocation({
		latitude: Number(Number(lat).toFixed(8)),
		longitude: Number(Number(lng).toFixed(8)),
	})
}

const onMapClick = (event) => {
	if (!event?.latLng) return
	setPoint(event.latLng.lat(), event.latLng.lng())
}

const onMarkerDragEnd = (event) => {
	if (!event?.latLng) return
	setPoint(event.latLng.lat(), event.latLng.lng())
}

/**
 * ربط بحث Places بحقل الإدخال بعد جهوز الخريطة.
 *
 * الودجة تدير رمز الجلسة بنفسها، وهو ما يجعل التسعير على البحث المكتمل لا على
 * كل ضغطة مفتاح — فلا نبني بحثاً يدوياً محلّه.
 */
const attachAutocomplete = async () => {
	if (autocomplete.value) return
	const api = mapRef.value?.api
	if (!api?.places || !searchInput.value) return

	await nextTick()

	autocomplete.value = new api.places.Autocomplete(searchInput.value, {
		fields: ['geometry', 'name', 'formatted_address'],
	})

	autocomplete.value.addListener('place_changed', () => {
		const place = autocomplete.value.getPlace()
		if (!place?.geometry?.location) return

		emitLocation({
			latitude: Number(place.geometry.location.lat().toFixed(8)),
			longitude: Number(place.geometry.location.lng().toFixed(8)),
			name: place.name || props.modelValue?.name || '',
			address: place.formatted_address || props.modelValue?.address || '',
		})

		const map = mapRef.value?.map
		if (map) {
			map.setCenter(place.geometry.location)
			map.setZoom(16)
		}
	})
}

watch(
	() => mapRef.value?.ready,
	(ready) => {
		if (ready) attachAutocomplete()
	}
)

const clearSearch = () => {
	searchQuery.value = ''
}

defineExpose({ clearSearch })
</script>

<template>
	<div>
		<div v-if="!hasKey"
			class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
			{{ $t('Google Maps API key is not configured. Ask your administrator to add it in the admin settings.') }}
		</div>

		<template v-else>
			<div class="relative mb-3">
				<input ref="searchInput" v-model="searchQuery" type="text"
					:placeholder="$t('Search for a place by name')"
					class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-primary focus:ring-primary"
					@keydown.enter.prevent />
			</div>

			<GoogleMap ref="mapRef" :api-key="apiKey" :libraries="['places']" :style="{ width: '100%', height }"
				:center="center" :zoom="position ? 16 : 11" :street-view-control="false" :map-type-control="false"
				@click="onMapClick">
				<Marker v-if="position" :options="{ position, draggable: true }" @dragend="onMarkerDragEnd" />
			</GoogleMap>

			<p class="mt-2 text-xs text-slate-500">
				{{ $t('Click on the map or drag the marker to fine-tune the exact point.') }}
			</p>

			<div v-if="showTextFields" class="mt-3 grid grid-cols-2 gap-3">
				<div>
					<label class="mb-1 block text-xs text-slate-600">{{ $t('Location name') }}</label>
					<input :value="modelValue?.name ?? ''" type="text"
						class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-primary focus:ring-primary"
						@input="emitLocation({ name: $event.target.value })" />
				</div>
				<div>
					<label class="mb-1 block text-xs text-slate-600">{{ $t('Address label') }}</label>
					<input :value="modelValue?.address ?? ''" type="text"
						class="w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-primary focus:ring-primary"
						@input="emitLocation({ address: $event.target.value })" />
				</div>
			</div>

			<p v-if="position" class="mt-2 text-xs text-slate-500" dir="ltr">
				{{ position.lat.toFixed(6) }}, {{ position.lng.toFixed(6) }}
			</p>
		</template>
	</div>
</template>
