<script setup>
import { ref, onMounted } from 'vue';
import { default as axios } from 'axios';
import ChatBubble from '@/Components/ChatComponents/ChatBubble.vue';

const props = defineProps({
    contactId: {
        type: Number,
        required: true
    },
    initialMessages: {
        type: Array,
        required: true
    },
    hasMoreMessages: {
        type: Boolean,
        required: true
    },
    initialNextPage: {
        type: Number,
        required: true
    }
});

const messages = ref(props.initialMessages);
const loading = ref(false);
const nextPage = ref(props.initialNextPage);
const hasMore = ref(props.hasMoreMessages);

const loadMoreMessages = async () => {
    if (loading.value || !hasMore.value) return;
    
    loading.value = true;
    try {
        const response = await axios.get(`/chats/${props.contactId}/messages?page=${nextPage.value}`);
        messages.value = [...response.data.messages, ...messages.value];
        hasMore.value = response.data.hasMoreMessages;
        nextPage.value = response.data.nextPage;
    } catch (error) {
        console.error('Error loading messages:', error);
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <div class="py-4 md:py-4 relative px-6 md:px-10">
        <div v-if="hasMore" 
             class="text-center py-2">
            <div v-if="loading" 
                 class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-gray-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Loading...
            </div>
            <button v-else
                    @click="loadMoreMessages"
                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
                <svg class="-ml-1 mr-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                </svg>
                Load More Messages
            </button>
        </div>
        
        <div v-for="(chat, index) in messages" 
             :key="index" 
             class="flex flex-grow flex-col"
             :class="chat[0].type === 'ticket' ? 'justify-center' : 'justify-end'">
            <ChatBubble 
                v-if="chat[0].type === 'chat'" 
                :content="chat[0].value" 
                :type="chat[0].value.type" />
            <div v-if="chat[0].type === 'ticket'" class="py-2">
                <div class="text-center font-light text-sm border-b border-t py-2 border-dashed border-black">
                    <div>{{ chat[0].value.description }} </div>
                    <div class="text-xs">{{ chat[0].value.created_at }}</div>
                </div>
            </div>
            <div v-if="chat[0].type === 'notes'" class="py-2 bg-orange-100 my-2 rounded-lg p-2 w-[fit-content] ml-auto">
                <div class="text-right font-light text-sm">
                    <div>{{ chat[0].value.content }}</div>
                    <div class="flex items-center justify-between mt-2 space-x-4">
                        <!--<span v-if="props.type === 'outbound' && content.user" class="text-gray-500 text-xs text-right leading-none">Sent By: {{ content.user?.first_name + ' ' + content.user?.last_name }}</span>-->
                        <p class="text-gray-500 text-xs text-right leading-none">{{ chat[0].value.created_at }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>