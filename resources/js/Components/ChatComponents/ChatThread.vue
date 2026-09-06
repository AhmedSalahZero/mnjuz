<script setup>
import { computed, ref, onMounted, watch } from 'vue'
import { default as axios } from 'axios'
import ChatBubble from '@/Components/ChatComponents/ChatBubble.vue'
import ChatMediaAlbum from '@/Components/ChatComponents/ChatMediaAlbum.vue'
import { groupMediaAlbums } from '@/Composables/mediaAlbums'

const props = defineProps({
	contactId: {
		type: Number,
		required: true,
	},
	initialMessages: {
		type: Array,
		required: true,
	},
	hasMoreMessages: {
		type: Boolean,
		required: true,
	},
	initialNextPage: {
		type: Number,
		required: true,
	},
})

const messages = ref(props.initialMessages)

/**
 * أنواع لا تُعرض في المحادثة إطلاقاً.
 *
 * التفاعل بالإيموجي منها. إخفاء محتواه وحده لم يكن كافياً: غلاف الفقاعة
 * يُرسَم هنا لا في ChatBubble، فكانت تظهر فقاعة فارغة لكل تفاعل — وهي أظهر
 * إزعاجاً من التفاعل نفسه.
 */
const HIDDEN_CHAT_TYPES = ['reaction']

const chatMetadataType = (entry) => {
	const raw = entry?.value?.metadata
	if (typeof raw !== 'string') return raw?.type ?? null
	try {
		return JSON.parse(raw)?.type ?? null
	} catch {
		return null
	}
}

/**
 * ما يُرسَم فعلاً. نُرشّح هنا لا في ChatBubble كي لا يبقى العنصر الحاوي
 * بهوامشه فيُحدث فجوةً بين الرسائل بلا سبب ظاهر.
 */
const visibleMessages = computed(() =>
	messages.value.filter((chat) => {
		const entry = chat?.[0]
		if (entry?.type !== 'chat') return true
		return !HIDDEN_CHAT_TYPES.includes(chatMetadataType(entry))
	})
)

/**
 * الصور والفيديوهات المرسَلة دفعةً واحدة تُعرض شبكةً واحدة كما في واتساب.
 *
 * الضمّ عرضٌ لا تخزين: كل صورة تبقى رسالةً مستقلّة عند العميل وفي قاعدة
 * البيانات — واجهة واتساب السحابية لا تعرف الألبوم أصلاً — لكن عشر صور في
 * عشر فقاعات متراصّة كانت تبتلع المحادثة وتُخفي ما قبلها.
 */
const renderItems = computed(() => groupMediaAlbums(visibleMessages.value))
watch(
	() => props.initialMessages,
	(newInitialMessages) => {
		messages.value = newInitialMessages
	},
)
watch(
	() => props.hasMoreMessages,
	(newHasMoreMessages) => {
		hasMore.value = newHasMoreMessages
	},
)
watch(
	() => props.initialNextPage,
	(newInitialNextPage) => {
		nextPage.value = newInitialNextPage
	},
)
const loading = ref(false)
const nextPage = ref(props.initialNextPage)
const hasMore = ref(props.hasMoreMessages)

const loadMoreMessages = async () => {
	if (loading.value || !hasMore.value) return

	loading.value = true
	try {
		const response = await axios.get(`/chats/${props.contactId}/messages?page=${nextPage.value}`)
		messages.value = [...response.data.messages, ...messages.value]
		hasMore.value = response.data.hasMoreMessages
		nextPage.value = response.data.nextPage
	} catch (error) {
		console.error('Error loading messages:', error)
	} finally {
		loading.value = false
	}
}
</script>
<template>
	<div class="py-4 md:py-4 relative px-6 md:px-10">
		<div v-if="hasMore" class="text-center py-2">
			<div v-if="loading"
				class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
				<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-gray-700" xmlns="http://www.w3.org/2000/svg"
					fill="none" viewBox="0 0 24 24">
					<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
					<path class="opacity-75" fill="currentColor"
						d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
					</path>
				</svg> {{ $t('Loading...') }}
			</div>
			<button v-else @click="loadMoreMessages"
				class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
				<svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
					stroke="currentColor">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
						d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
				</svg> {{ $t('Load More Messages') }} </button>
		</div>
		<div v-for="item in renderItems" :key="item.key" class="flex flex-grow flex-col"
			:class="item.kind === 'single' && item.chat[0].type === 'ticket' ? 'justify-center' : 'justify-end'">
			<ChatMediaAlbum v-if="item.kind === 'album'" :messages="item.messages" :type="item.direction" />
			<template v-else>
			<ChatBubble v-if="item.chat[0].type === 'chat'" :content="item.chat[0].value" :type="item.chat[0].value.type" />
			<div v-if="item.chat[0].type === 'ticket'" class="py-2">
				<div class="text-center font-light text-sm border-b border-t py-2 border-dashed border-black">
					<div>{{ item.chat[0].value.description }}</div>
					<div class="text-xs">{{ item.chat[0].value.created_at }}</div>
				</div>
			</div>
			<div v-if="item.chat[0].type === 'notes'" class="py-2 bg-orange-100 my-2 rounded-lg p-2 w-[fit-content] ml-auto">
				<div class="text-right font-light text-sm">
					<div>{{ item.chat[0].value.content }}</div>
					<div class="flex items-center justify-between mt-2 space-x-4">
						<p class="text-gray-500 text-xs text-right leading-none">
							{{ item.chat[0].value.created_at }}
						</p>
					</div>
				</div>
			</div>
			</template>
		</div>
	</div>
</template>
