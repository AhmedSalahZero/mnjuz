<template>
  <AppLayout v-slot:default="slotProps">
    <div class="md:flex md:flex-grow md:overflow-hidden">
      <div
        class="md:w-[30%] md:flex flex-col h-full bg-white border-r border-l"
        :class="contact ? 'hidden' : ''">
        <ChatTable
          :rows="rows"
          :filters="props.filters"
          :rowCount="props.rowCount"
          :ticketingIsEnabled="ticketingIsEnabled"
          :status="props?.status"
          :chatSortDirection="props.chat_sort_direction" />
      </div>
      <div
        class="min-w-0 bg-cover flex flex-col chat-bg"
        :class="contact ? 'h-screen md:w-[70%]' : 'md:h-screen md:w-[70%]'">
        <ChatHeader
          v-if="contact"
          :ticketingIsEnabled="ticketingIsEnabled"
          :contact="contact"
          :displayContactInfo="displayContactInfo"
          :ticket="ticket"
          :addon="addon"
          @toggleView="toggleContactView"
          @deleteThread="deleteThread"
          @closeThread="closeThread" />
        <div
          v-if="contact && !displayTemplate"
          class="flex-1 overflow-y-auto"
          ref="scrollContainer2">
          <ChatThread
            v-if="!displayContactInfo && !loadingThread && !displayTemplate"
            :contactId="contact.id"
            :initialMessages="chatThread"
            :hasMoreMessages="hasMoreMessages"
            :initialNextPage="nextPage" />
          <Contact
            v-if="displayContactInfo && !displayTemplate"
            class="bg-white h-full"
            :fields="props.fields"
            :contact="contact"
            :locationSettings="props.locationSettings" />
        </div>

        <div
          v-if="props.contact?.is_blocked"
          class="is-blocked flex justify-center items-center gap-2 px-3 py-1 h-[80px] text-center bg-red-50/80 backdrop-blur-sm border border-red-200 rounded-lg">
          <div class="relative flex h-2 w-2">
            <span
              class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
          </div>
          <span class="text-xs font-bold text-red-800 uppercase tracking-tighter">{{
            $t('Blocked')
          }}</span>
        </div>
        <div
          v-if="
            contact &&
            !contact.is_blocked &&
            !displayContactInfo &&
            !formLoading &&
            !displayTemplate
          "
          class="w-full py-4">
          <ChatForm
            :contact="contact"
            :simpleForm="simpleForm"
            :chatLimitReached="isChatLimitReached"
            @viewTemplate="displayTemplate = true" />
        </div>
        <div
          v-if="displayTemplate"
          class="flex-1 overflow-y-hidden">
          <CampaignForm
            v-if="displayTemplate"
            class="bg-white h-full"
            :contact="contact.uuid"
            :templates="templates"
            :contactGroups="[]"
            :settings="props.settings"
            :displayCancelBtn="false"
            :displayTitle="true"
            :isCampaignFlow="false"
            :scheduleTemplate="false"
            :sendText="'Send Message'"
            @viewTemplate="displayTemplate = false" />
        </div>
      </div>
      <!--<div v-if="contact" class="md:w-[25%] min-w-0 bg-cover flex flex-col bg-white border-l">
                <ChatContact v-if="contact" class="bg-white h-full" :contact="contact" />
            </div>-->
    </div>
    <button
      class="hidden"
      ref="toggleNavbarBtn"
      @click="slotProps.toggleNavBar"></button>
  </AppLayout>
</template>
<script setup>
import CampaignForm from '@/Components/CampaignForm.vue'
import ChatForm from '@/Components/ChatComponents/ChatForm.vue'
import ChatHeader from '@/Components/ChatComponents/ChatHeader.vue'
import ChatTable from '@/Components/ChatComponents/ChatTable.vue'
import ChatThread from '@/Components/ChatComponents/ChatThread.vue'
import Contact from '@/Components/ContactInfo.vue'
import { default as axios } from 'axios'
import { debounce } from 'lodash'
import { defineEmits, onMounted, onUnmounted, ref, watch } from 'vue'
import { getEchoInstance } from '../../../echo'
import AppLayout from './../Layout/App.vue'
let echoChannel = null
const props = defineProps({
  rows: Array,
  rowCount: Number,
  pusherSettings: Object,
  organizationId: Number,
  isChatLimitReached: Boolean,
  toggleNavBar: Function,
  state: String,
  demoNumber: String,
  settings: Object,
  status: String,
  chatThread: Array,
  hasMoreMessages: Boolean,
  nextPage: Number,
  addon: Object,
  contact: Object,
  ticket: Object,
  chat_sort_direction: String,
  filters: Object,
  templates: Array,
  fields: Array,
  locationSettings: Object,
  simpleForm: Boolean,
})

const rows = ref(props.rows)
const rowCount = ref(props.rowCount)
const scrollContainer2 = ref(null)
const loadingThread = ref(false)
const displayContactInfo = ref(false)
const displayTemplate = ref(false)
const formLoading = ref(false)
const isChatLimitReached = ref(props.isChatLimitReached)
const toggleNavbarBtn = ref(null)
const config = ref(props.settings.metadata)
const settings = ref(config.value ? JSON.parse(config.value) : null)
const ticketingIsEnabled = ref(settings.value?.tickets?.active ?? false)
const chatThread = ref(props.chatThread)
const contact = ref(props.contact)

watch(
  () => props.rows,
  (newRows) => {
    rows.value = newRows
    //	console.log('inside watch');
  },
)

const toggleDropdown = () => {
  isOpen.value = !isOpen.value
}

function toggleContactView(value) {
  displayContactInfo.value = value
}

const scrollToBottom = () => {
  const container = scrollContainer2.value
  if (container) {
    container.scrollTo({
      top: container.scrollHeight,
      behavior: 'smooth',
    })
  }
}

const closeThread = () => {
  toggleNavbarBtn.value.click()
  contact.value = null
}

const deleteThread = async () => {
  chatThread.value = []
  await axios.delete('/chats/' + contact.value.uuid)
}

const updateChatThread = (chat) => {
  const wamId = chat[0].value.wam_id
  const wamIdExists = chatThread.value.some(
    (existingChat) => existingChat[0].value.wam_id === wamId,
  )

  if (!wamIdExists && chat[0].value.deleted_at == null) {
    chatThread.value.push(chat)
    setTimeout(scrollToBottom, 100)
  }
}

const debouncedUpdateSidePanel = debounce(async (chat) => {
  if (contact.value && contact.value.id == chat[0].value.contact_id) {
    updateChatThread(chat)
  }

  try {
    //	console.log('try11');
    //	console.log('call api');
    const response = await axios.get('/chats')
    if (response?.data?.result) {
      //	console.log('inside watch');
      //		console.log('rows')
      //	console.log(response.data.result)
      rows.value = response.data.result
    }
  } catch (error) {
    console.error('Error updating side panel:', error)
  }
}, 1000) // الانتظار ثانية واحدة قبل التنفيذ

const onCloseDemoModal = () => {
  isDemoModalOpen.value = false
}

onMounted(() => {
  //	console.log('mount');
  //	console.log('inside mounted');
  const echo = getEchoInstance(
    props.pusherSettings['pusher_app_key'],
    props.pusherSettings['pusher_app_cluster'],
  )
  echoChannel = echo.channel('chats.ch' + props.organizationId)
  echoChannel.listen('NewChatEvent', (event) => {
    //	console.log('NewChatEvent');
    //	console.log('new chat',event.chat);
    debouncedUpdateSidePanel(event.chat)
  })

  scrollToBottom()
})

onUnmounted(() => {
  //console.log('unmount');
  if (echoChannel) {
    echoChannel.stopListening('NewChatEvent')
    // أو يمكن
    // Echo.leave('chats.ch' + props.organizationId);
  }
})
</script>
