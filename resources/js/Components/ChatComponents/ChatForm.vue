<script setup>
import axios from 'axios'
import MicRecorder from 'mic-recorder-to-mp3-fixed'
import { computed, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue'
import EmojiPicker from 'vue3-emoji-picker'
import 'vue3-emoji-picker/css'

const recorder = ref(null)
const props = defineProps(['contact', 'chatLimitReached', 'simpleForm'])
const processingForm = ref(null)
const processingAISuggestions = ref(false)
const formTextInput = ref(null)
const isRecording = ref(false)
const mediaRecorder = ref(null)
const audioChunks = ref([])
const recordingTime = ref(0)
const timerInterval = ref(null)
const playbackTime = ref(0)
const playbackInterval = ref(null)
const audioDuration = ref(0)
const audioPreviewUrl = ref(null)
const isPlaying = ref(false)
const audioPlayer = ref(null)
const isAudioRecording = ref(false)
const form = ref({
  uuid: props.contact.uuid,
  message: null,
  type: null,
  file: null,
})
const form2 = ref({
  uuid: props.contact.uuid,
  message: null,
  type: null,
  file: null,
})
const emojiPicker = ref(false)
const emojiPickerRef = ref(null)

watchEffect(() => {
  form.value.uuid = props.contact.uuid
})

const emit = defineEmits(['response', 'viewTemplate'])

const viewTemplate = () => {
  emit('viewTemplate', true)
}

const sendMessage = async () => {
  form.value.message = formTextInput.value
  processingForm.value = true

  if (form.value.message != null || form.value.file != null) {
    const formData = new FormData()

    formData.append('message', form.value.message)
    formData.append('type', form.value.type)
    formData.append('uuid', form.value.uuid)

    if (form.value.file) {
      formData.append('file', form.value.file)
    }

    try {
      const response = await axios.post('/chats', formData)

      if (isAudioRecording.value == true) {
        await sendAudioMessage()
      }

      form.value.message = null
      formTextInput.value = null
      form.value.file = null

      processingForm.value = false
    } catch (error) {
      // Handle the error
      // console.error('Error:', error);
    }
  } else {
    if (isAudioRecording.value == true) {
      await sendAudioMessage()
    }

    processingForm.value = false
  }
}

const sendAudioMessage = async () => {
  const formData = new FormData()
  formData.append('type', form2.value.type)
  formData.append('uuid', form2.value.uuid)

  if (form2.value.file) {
    formData.append('file', form2.value.file)
  }

  try {
    const response = await axios.post('/chats', formData)

    if (isAudioRecording.value == true) {
      deleteRecording()
    }
  } catch (error) {
    // Handle the error
    // console.error('Error:', error);
  }
}

const textInputRef = ref(null)
const adjustTextareaHeight = () => {
  const textInput = textInputRef.value
  textInput.style.height = 'auto'
  textInput.style.height = textInput.scrollHeight + 'px'
}

const handleEnterKey = (event) => {
  if (formTextInput.value != null && formTextInput.value.trim() != '') {
    sendMessage()
  }
}

const isInboundChatWithin24Hours = computed(() => {
  if (props.contact.last_inbound_chat) {
    const lastInboundChatTime = new Date(props.contact.last_inbound_chat.created_at)
    const currentTime = new Date()
    const timeDifference = currentTime - lastInboundChatTime

    return timeDifference < 24 * 60 * 60 * 1000
  }

  return false
})

const handleFileUpload = (event) => {
  const file = event.target.files[0]
  const reader = new FileReader()
  reader.onload = (e) => {
    form.value.file = file
    sendMessage()
  }
  reader.readAsDataURL(file)
}

const getAcceptedFileTypes = () => {
  switch (form.value.type) {
    case 'image':
      return '.jpg, .png'
    case 'document':
      return '.txt, .pdf, .ppt, .doc, .xls, .docx, .pptx, .xlsx'
    case 'audio':
      return '.mp3, .ogg'
    case 'video':
      return '.mp4'
    default:
      return '' // Empty string allows all file types
  }
}

const toggleEmojiPicker = (e) => {
  e.stopPropagation()
  emojiPicker.value = !emojiPicker.value
}

const closeEmojiPicker = () => {
  emojiPicker.value = false
}

const addEmoji = (emoji) => {
  const textarea = textInputRef.value
  const currentValue = formTextInput.value || ''
  const start = textarea.selectionStart || 0
  const end = textarea.selectionEnd || 0

  const newText = currentValue.substring(0, start) + emoji.i + currentValue.substring(end)

  formTextInput.value = newText

  // Focus the textarea and place the cursor at the correct position
  setTimeout(() => {
    textarea.focus()
    textarea.setSelectionRange(start + emoji.i.length, start + emoji.i.length)
  }, 0)
}

const handleClickOutside = (event) => {
  if (
    emojiPickerRef.value &&
    !emojiPickerRef.value.contains(event.target) &&
    !textInputRef.value.contains(event.target)
  ) {
    closeEmojiPicker()
  }
}

const startRecording = async () => {
  try {
    isAudioRecording.value = true
    await recorder.value.start()
    isRecording.value = true
    startTimer()
  } catch (error) {
    console.error('Error accessing microphone:', error)
  }
}

const stopRecording = async () => {
  if (isRecording.value) {
    try {
      const [buffer, blob] = await recorder.value.stop().getMp3()
      const audioFile = new File(buffer, 'audio-message.mp3', {
        type: blob.type,
        lastModified: Date.now(),
      })

      audioPreviewUrl.value = URL.createObjectURL(blob)
      form2.value.type = 'audio'
      form2.value.file = audioFile

      isRecording.value = false
      stopTimer()
    } catch (error) {
      console.error('Error stopping recording:', error)
    }
  }
}

const deleteRecording = () => {
  if (audioPreviewUrl.value) {
    URL.revokeObjectURL(audioPreviewUrl.value)
  }
  audioPreviewUrl.value = null
  recordingTime.value = 0
  playbackTime.value = 0
  audioDuration.value = 0
  audioChunks.value = []
  form2.value.type = null
  form2.value.file = null
  stopPlaybackTimer()
}

const togglePlayback = () => {
  if (!audioPlayer.value) return

  if (isPlaying.value) {
    audioPlayer.value.pause()
    stopPlaybackTimer()
  } else {
    audioPlayer.value.play()
    startPlaybackTimer()
  }
  isPlaying.value = !isPlaying.value
}

const startTimer = () => {
  recordingTime.value = 0
  timerInterval.value = setInterval(() => {
    recordingTime.value++
  }, 1000)
}

const startPlaybackTimer = () => {
  playbackTime.value = 0
  playbackInterval.value = setInterval(() => {
    if (audioPlayer.value) {
      playbackTime.value = Math.floor(audioPlayer.value.currentTime)
    }
  }, 100)
}

const stopTimer = () => {
  if (timerInterval.value) {
    clearInterval(timerInterval.value)
    timerInterval.value = null
  }
}

const stopPlaybackTimer = () => {
  if (playbackInterval.value) {
    clearInterval(playbackInterval.value)
    playbackInterval.value = null
  }
}

const formatTime = (seconds) => {
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60
  return `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`
}

const handleAudioLoaded = () => {
  if (audioPlayer.value) {
    audioDuration.value = Math.floor(audioPlayer.value.duration)
  }
}

const getSuggestion = async () => {
  processingAISuggestions.value = true
  try {
    const response = await axios.get('/automation/chat/suggestion', {
      params: {
        contact: props.contact.uuid,
      },
    })

    if (response.data.success) {
      form.value.type = 'text'
      formTextInput.value = response.data.data.text
    } else {
      console.error('Failed to get suggestion:', response.data.message)
    }
  } catch (error) {
    console.error('Error getting suggestion:', error.response?.data?.message || error.message)
  } finally {
    processingAISuggestions.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
  recorder.value = new MicRecorder({
    bitRate: 128,
  })
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
  stopTimer()
  stopPlaybackTimer()
  if (audioPreviewUrl.value) {
    URL.revokeObjectURL(audioPreviewUrl.value)
  }
})
</script>
<template>
  <div
    v-if="props.chatLimitReached"
    class="flex justify-center items-center w-full px-6 md:px-4">
    <div class="flex items-start space-x-4 bg-orange-100 rounded-lg p-2 mb-2 px-4">
      <span class="text-red-700">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="30"
          height="30"
          viewBox="0 0 36 36">
          <path
            fill="currentColor"
            d="M18 21.32a1.3 1.3 0 0 0 1.3-1.3V14a1.3 1.3 0 1 0-2.6 0v6a1.3 1.3 0 0 0 1.3 1.32Z"
            class="clr-i-outline clr-i-outline-path-1" />
          <circle
            cx="17.95"
            cy="24.27"
            r="1.5"
            fill="currentColor"
            class="clr-i-outline clr-i-outline-path-2" />
          <path
            fill="currentColor"
            d="M30.33 25.54L20.59 7.6a3 3 0 0 0-5.27 0L5.57 25.54A3 3 0 0 0 8.21 30h19.48a3 3 0 0 0 2.64-4.43Zm-1.78 1.94a1 1 0 0 1-.86.49H8.21a1 1 0 0 1-.88-1.48l9.74-17.94a1 1 0 0 1 1.76 0l9.74 17.94a1 1 0 0 1-.02.99Z"
            class="clr-i-outline clr-i-outline-path-3" />
          <path
            fill="none"
            d="M0 0h36v36H0z" />
        </svg>
      </span>
      <div>
        <div class="text-sm">{{ $t('Maximum chat limit reached') }}</div>
        <div class="text-sm">
          {{
            $t(
              'You have reached the maximum chat limit for your subscription! Please upgrade to send/receive more messages',
            )
          }}
        </div>
      </div>
    </div>
  </div>
  <div
    v-if="!isInboundChatWithin24Hours && !props.chatLimitReached"
    class="flex justify-center items-center w-full px-6 md:px-4">
    <div class="flex items-center justify-between space-x-4 bg-orange-100 rounded-lg p-2 mb-2 px-4">
      <div class="flex items-start justify-between space-x-4">
        <span class="text-red-700">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="30"
            height="30"
            viewBox="0 0 36 36">
            <path
              fill="currentColor"
              d="M18 21.32a1.3 1.3 0 0 0 1.3-1.3V14a1.3 1.3 0 1 0-2.6 0v6a1.3 1.3 0 0 0 1.3 1.32Z"
              class="clr-i-outline clr-i-outline-path-1" />
            <circle
              cx="17.95"
              cy="24.27"
              r="1.5"
              fill="currentColor"
              class="clr-i-outline clr-i-outline-path-2" />
            <path
              fill="currentColor"
              d="M30.33 25.54L20.59 7.6a3 3 0 0 0-5.27 0L5.57 25.54A3 3 0 0 0 8.21 30h19.48a3 3 0 0 0 2.64-4.43Zm-1.78 1.94a1 1 0 0 1-.86.49H8.21a1 1 0 0 1-.88-1.48l9.74-17.94a1 1 0 0 1 1.76 0l9.74 17.94a1 1 0 0 1-.02.99Z"
              class="clr-i-outline clr-i-outline-path-3" />
            <path
              fill="none"
              d="M0 0h36v36H0z" />
          </svg>
        </span>
        <div>
          <div class="text-sm">{{ $t('24 hour limit') }}</div>
          <div class="text-sm">
            {{
              $t(
                'Whatsapp does not allow sending messages 24 hours after they last messaged you. However, you can send them a template message',
              )
            }}
          </div>
        </div>
      </div>
      <button
        @click="viewTemplate()"
        class="rounded-md bg-primary px-3 py-1 text-sm text-white shadow-sm w-[25%]">
        Send Template
      </button>
    </div>
  </div>

  <form
    v-if="
      simpleForm &&
      isInboundChatWithin24Hours &&
      !props.chatLimitReached &&
      !props.contact.is_blocked
    "
    @submit.prevent="sendMessage()"
    class="flex items-center px-2 md:px-10 space-x-2">
    <div
      class="flex items-center w-full rounded-lg py-4 md:py-2 pl-2 pr-2"
      :class="processingForm ? 'bg-gray-200' : 'bg-white'">
      <div class="absolute">
        <button
          type="button"
          @click="toggleEmojiPicker">
          😀
        </button>
        <div
          v-if="emojiPicker"
          class="absolute left-0 bottom-full"
          ref="emojiPickerRef">
          <EmojiPicker
            :native="true"
            @select="addEmoji" />
        </div>
      </div>
      <textarea
        ref="textInputRef"
        @focus="form.type = 'text'"
        @keydown.enter.exact.prevent="handleEnterKey"
        class="w-full ml-3 outline-none resize-none text-sm md:text-base pl-6"
        :class="processingForm ? 'bg-gray-200' : 'bg-white'"
        v-model="formTextInput"
        @input="adjustTextareaHeight"
        type="text"
        rows="1"
        :placeholder="$t('Type your message...')"
        :disabled="processingForm">
      </textarea>
      <input
        type="file"
        class="sr-only"
        :accept="getAcceptedFileTypes()"
        id="file-upload"
        @change="handleFileUpload($event)" />
      <label
        @click="form.type = 'image'"
        for="file-upload"
        class="text-slate-500 mr-2 cursor-pointer">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="24"
          height="24"
          viewBox="0 0 24 24">
          <g
            fill="none"
            stroke="currentColor"
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5">
            <path
              d="M6.5 8a2 2 0 1 0 4 0a2 2 0 0 0-4 0Zm14.427 1.99c-6.61-.908-12.31 4-11.927 10.51" />
            <path d="M3 13.066c2.78-.385 5.275.958 6.624 3.1" />
            <path
              d="M3 12c0-4.243 0-6.364 1.318-7.682C5.636 3 7.758 3 12 3c4.243 0 6.364 0 7.682 1.318C21 5.636 21 7.758 21 12c0 4.243 0 6.364-1.318 7.682C18.364 21 16.242 21 12 21c-4.243 0-6.364 0-7.682-1.318C3 18.364 3 16.242 3 12Z" />
          </g>
        </svg>
      </label>
      <label
        @click="form.type = 'document'"
        for="file-upload"
        class="text-slate-500 mr-1 cursor-pointer">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="22"
          height="22"
          viewBox="0 0 24 24">
          <g
            fill="none"
            stroke="currentColor"
            stroke-width="1.5">
            <path
              d="M3 10c0-3.771 0-5.657 1.172-6.828C5.343 2 7.229 2 11 2h2c3.771 0 5.657 0 6.828 1.172C21 4.343 21 6.229 21 10v4c0 3.771 0 5.657-1.172 6.828C18.657 22 16.771 22 13 22h-2c-3.771 0-5.657 0-6.828-1.172C3 19.657 3 17.771 3 14v-4Z" />
            <path
              stroke-linecap="round"
              d="M8 10h8m-8 4h5" />
          </g>
        </svg>
      </label>
      <label
        @click="form.type = 'audio'"
        for="file-upload"
        class="text-slate-500 mr-1 cursor-pointer">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="22"
          height="22"
          viewBox="0 0 1024 1024">
          <path
            fill="currentColor"
            d="M842 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 140.3-113.7 254-254 254S258 594.3 258 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 168.7 126.6 307.9 290 327.6V884H326.7c-13.7 0-24.7 14.3-24.7 32v36c0 4.4 2.8 8 6.2 8h407.6c3.4 0 6.2-3.6 6.2-8v-36c0-17.7-11-32-24.7-32H548V782.1c165.3-18 294-158 294-328.1M512 624c93.9 0 170-75.2 170-168V232c0-92.8-76.1-168-170-168s-170 75.2-170 168v224c0 92.8 76.1 168 170 168m-94-392c0-50.6 41.9-92 94-92s94 41.4 94 92v224c0 50.6-41.9 92-94 92s-94-41.4-94-92z" />
        </svg>
      </label>
      <label
        @click="form.type = 'video'"
        for="file-upload"
        class="text-slate-500 mr-2 cursor-pointer">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="28"
          height="28"
          viewBox="0 0 32 32">
          <path
            fill="currentColor"
            d="M6.5 5.5A4.5 4.5 0 0 0 2 10v12a4.5 4.5 0 0 0 4.5 4.5h12A4.5 4.5 0 0 0 23 22v-1.5l4.2 3.15c1.153.865 2.8.042 2.8-1.4V9.75c0-1.442-1.647-2.265-2.8-1.4L23 11.5V10a4.5 4.5 0 0 0-4.5-4.5zM23 14l5-3.75v11.5L23 18zm-2-4v12a2.5 2.5 0 0 1-2.5 2.5h-12A2.5 2.5 0 0 1 4 22V10a2.5 2.5 0 0 1 2.5-2.5h12A2.5 2.5 0 0 1 21 10" />
        </svg>
      </label>
      <label
        @click="viewTemplate()"
        class="text-slate-500 mr-4 cursor-pointer">
        <svg
          xmlns="http://www.w3.org/2000/svg"
          width="24"
          height="24"
          viewBox="0 0 256 256">
          <path
            fill="currentColor"
            d="M216 80h-32V48a16 16 0 0 0-16-16H40a16 16 0 0 0-16 16v128a8 8 0 0 0 13 6.22L72 154v30a16 16 0 0 0 16 16h93.59L219 230.22a8 8 0 0 0 5 1.78a8 8 0 0 0 8-8V96a16 16 0 0 0-16-16M66.55 137.78L40 159.25V48h128v88H71.58a8 8 0 0 0-5.03 1.78M216 207.25l-26.55-21.47a8 8 0 0 0-5-1.78H88v-32h80a16 16 0 0 0 16-16V96h32Z"></path>
        </svg>
      </label>
      <button
        class="flex items-center"
        type="submit"
        :disabled="formTextInput === null || formTextInput.trim() === '' || processingForm">
        <svg
          v-if="!processingForm"
          :class="
            formTextInput === null || formTextInput.trim() === '' ? 'text-slate-300' : 'text-black'
          "
          xmlns="http://www.w3.org/2000/svg"
          width="22"
          height="22"
          viewBox="0 0 16 16">
          <path
            fill="currentColor"
            d="M1.724 1.053a.5.5 0 0 0-.714.545l1.403 4.85a.5.5 0 0 0 .397.354l5.69.953c.268.053.268.437 0 .49l-5.69.953a.5.5 0 0 0-.397.354l-1.403 4.85a.5.5 0 0 0 .714.545l13-6.5a.5.5 0 0 0 0-.894l-13-6.5Z" />
        </svg>
        <svg
          v-else
          xmlns="http://www.w3.org/2000/svg"
          width="24"
          height="24"
          viewBox="0 0 24 24">
          <circle
            cx="12"
            cy="2"
            r="0"
            fill="currentColor">
            <animate
              attributeName="r"
              begin="0"
              calcMode="spline"
              dur="1s"
              keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
              repeatCount="indefinite"
              values="0;2;0;0" />
          </circle>
          <circle
            cx="12"
            cy="2"
            r="0"
            fill="currentColor"
            transform="rotate(45 12 12)">
            <animate
              attributeName="r"
              begin="0.125s"
              calcMode="spline"
              dur="1s"
              keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
              repeatCount="indefinite"
              values="0;2;0;0" />
          </circle>
          <circle
            cx="12"
            cy="2"
            r="0"
            fill="currentColor"
            transform="rotate(90 12 12)">
            <animate
              attributeName="r"
              begin="0.25s"
              calcMode="spline"
              dur="1s"
              keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
              repeatCount="indefinite"
              values="0;2;0;0" />
          </circle>
          <circle
            cx="12"
            cy="2"
            r="0"
            fill="currentColor"
            transform="rotate(135 12 12)">
            <animate
              attributeName="r"
              begin="0.375s"
              calcMode="spline"
              dur="1s"
              keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
              repeatCount="indefinite"
              values="0;2;0;0" />
          </circle>
          <circle
            cx="12"
            cy="2"
            r="0"
            fill="currentColor"
            transform="rotate(180 12 12)">
            <animate
              attributeName="r"
              begin="0.5s"
              calcMode="spline"
              dur="1s"
              keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
              repeatCount="indefinite"
              values="0;2;0;0" />
          </circle>
          <circle
            cx="12"
            cy="2"
            r="0"
            fill="currentColor"
            transform="rotate(225 12 12)">
            <animate
              attributeName="r"
              begin="0.625s"
              calcMode="spline"
              dur="1s"
              keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
              repeatCount="indefinite"
              values="0;2;0;0" />
          </circle>
          <circle
            cx="12"
            cy="2"
            r="0"
            fill="currentColor"
            transform="rotate(270 12 12)">
            <animate
              attributeName="r"
              begin="0.75s"
              calcMode="spline"
              dur="1s"
              keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
              repeatCount="indefinite"
              values="0;2;0;0" />
          </circle>
          <circle
            cx="12"
            cy="2"
            r="0"
            fill="currentColor"
            transform="rotate(315 12 12)">
            <animate
              attributeName="r"
              begin="0.875s"
              calcMode="spline"
              dur="1s"
              keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
              repeatCount="indefinite"
              values="0;2;0;0" />
          </circle>
        </svg>
      </button>
    </div>
  </form>

  <form
    v-if="
      !simpleForm &&
      isInboundChatWithin24Hours &&
      !props.chatLimitReached &&
      !props.contact.is_blocked
    "
    @submit.prevent="sendMessage()"
    class="flex items-center px-2 md:px-10 space-x-2">
    <div class="bg-white rounded-md w-full p-4">
      <div>
        <textarea
          ref="textInputRef"
          @focus="form.type = 'text'"
          @keydown.enter.exact.prevent="handleEnterKey"
          class="w-full outline-none resize-none text-sm rounded-md p-2"
          :class="processingForm ? 'bg-gray-100' : 'bg-white'"
          v-model="formTextInput"
          @input="adjustTextareaHeight"
          type="text"
          rows="3"
          :placeholder="$t('Type your message...')"
          :disabled="processingForm">
        </textarea>
        <input
          type="file"
          class="sr-only"
          :accept="getAcceptedFileTypes()"
          id="file-upload"
          @change="handleFileUpload($event)" />
      </div>
      <!-- Recording Interface -->
      <div
        v-if="isRecording || audioPreviewUrl"
        class="bg-slate-50 rounded-lg border px-2 mb-2">
        <div class="flex items-center justify-between">
          <div class="flex items-center">
            <span class="text-red-500 mr-2">
              <span
                v-if="isRecording"
                class="animate-pulse">
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24">
                  <circle
                    cx="12"
                    cy="12"
                    r="7"
                    fill="red"
                    opacity="0.5" />
                  <path
                    fill="#fee2e2"
                    fill-rule="evenodd"
                    d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2S2 6.477 2 12s4.477 10 10 10m0-3a7 7 0 1 0 0-14a7 7 0 0 0 0 14"
                    clip-rule="evenodd" />
                </svg>
              </span>
              <span
                v-if="audioPreviewUrl"
                @click="togglePlayback">
                <svg
                  v-if="!isPlaying"
                  xmlns="http://www.w3.org/2000/svg"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24">
                  <path
                    fill="#22c55e"
                    stroke="#22c55e"
                    stroke-linecap="round"
                    stroke-width="1.5"
                    d="M3 12v6.967c0 2.31 2.534 3.769 4.597 2.648l3.203-1.742M3 8V5.033c0-2.31 2.534-3.769 4.597-2.648l12.812 6.968a2.998 2.998 0 0 1 0 5.294l-6.406 3.484" />
                </svg>
                <svg
                  v-else
                  xmlns="http://www.w3.org/2000/svg"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24">
                  <path
                    fill="none"
                    stroke="red"
                    stroke-linecap="round"
                    stroke-width="1.5"
                    d="M2 18c0 1.886 0 2.828.586 3.414S4.114 22 6 22s2.828 0 3.414-.586S10 19.886 10 18V6c0-1.886 0-2.828-.586-3.414S7.886 2 6 2s-2.828 0-3.414.586S2 4.114 2 6v8m20-8c0-1.886 0-2.828-.586-3.414S19.886 2 18 2s-2.828 0-3.414.586S14 4.114 14 6v12c0 1.886 0 2.828.586 3.414S16.114 22 18 22s2.828 0 3.414-.586S22 19.886 22 18v-8" />
                </svg>
              </span>
            </span>
            <span
              class="text-sm"
              v-if="isRecording"
              >{{ formatTime(recordingTime) }}</span
            >
            <span
              class="text-sm"
              v-else
              >{{ formatTime(playbackTime) }} / {{ formatTime(recordingTime) }}</span
            >
          </div>
          <div class="flex gap-2">
            <!-- Stop Recording Button -->
            <button
              v-if="isRecording"
              @click="stopRecording"
              class="p-2 rounded-full hover:bg-red-100"
              title="Stop Recording">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="24"
                height="24"
                viewBox="0 0 24 24">
                <path
                  fill="red"
                  stroke="red"
                  stroke-width="1.5"
                  d="M2 12c0-4.714 0-7.071 1.464-8.536C4.93 2 7.286 2 12 2s7.071 0 8.535 1.464C22 4.93 22 7.286 22 12s0 7.071-1.465 8.535C19.072 22 16.714 22 12 22s-7.071 0-8.536-1.465C2 19.072 2 16.714 2 12Z" />
              </svg>
            </button>

            <!-- Delete Button -->
            <button
              v-if="audioPreviewUrl"
              @click="deleteRecording"
              class="p-2 rounded-full hover:bg-red-100 text-red-500"
              title="Delete Recording">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="24"
                height="24"
                viewBox="0 0 24 24">
                <path
                  fill="none"
                  stroke="red"
                  stroke-linecap="round"
                  stroke-width="1.5"
                  d="M9.17 4a3.001 3.001 0 0 1 5.66 0m5.67 2h-17m14.874 9.4c-.177 2.654-.266 3.981-1.131 4.79s-2.195.81-4.856.81h-.774c-2.66 0-3.99 0-4.856-.81c-.865-.809-.953-2.136-1.13-4.79l-.46-6.9m13.666 0l-.2 3M9.5 11l.5 5m4.5-5l-.5 5" />
              </svg>
            </button>
          </div>
        </div>
        <audio
          ref="audioPlayer"
          :src="audioPreviewUrl"
          @ended="isPlaying = false"
          class="hidden" />
      </div>
      <div class="flex gap-x-4 items-center text-gray-600">
        <div
          @click="viewTemplate()"
          class="flex gap-x-1 text-sm items-center">
          <span>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="24"
              height="24"
              viewBox="0 0 256 256">
              <path
                fill="currentColor"
                d="M216 80h-32V48a16 16 0 0 0-16-16H40a16 16 0 0 0-16 16v128a8 8 0 0 0 13 6.22L72 154v30a16 16 0 0 0 16 16h93.59L219 230.22a8 8 0 0 0 5 1.78a8 8 0 0 0 8-8V96a16 16 0 0 0-16-16M66.55 137.78L40 159.25V48h128v88H71.58a8 8 0 0 0-5.03 1.78M216 207.25l-26.55-21.47a8 8 0 0 0-5-1.78H88v-32h80a16 16 0 0 0 16-16V96h32Z"></path>
            </svg>
          </span>
          <button>{{ $t('Templates') }}</button>
        </div>
        <div>|</div>
        <div class="flex gap-x-4 text-sm items-center">
          <div>
            <button
              type="button"
              class="py-1"
              @click="toggleEmojiPicker">
              <span>
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="24"
                  height="24"
                  viewBox="0 0 16 16">
                  <path
                    fill="currentColor"
                    fill-rule="evenodd"
                    d="M4.111 2.18a7 7 0 1 1 7.778 11.64A7 7 0 0 1 4.11 2.18zm.556 10.809a6 6 0 1 0 6.666-9.978a6 6 0 0 0-6.666 9.978M6.5 7a1 1 0 1 1-2 0a1 1 0 0 1 2 0m5 0a1 1 0 1 1-2 0a1 1 0 0 1 2 0M8 11a3 3 0 0 1-2.65-1.58l-.87.48a4 4 0 0 0 7.12-.16l-.9-.43A3 3 0 0 1 8 11"
                    clip-rule="evenodd" />
                </svg>
              </span>
            </button>
            <div class="absolute">
              <div
                v-if="emojiPicker"
                class="absolute left-0 bottom-full"
                ref="emojiPickerRef">
                <EmojiPicker
                  :native="true"
                  @select="addEmoji" />
              </div>
            </div>
          </div>
          <div>
            <label
              class="py-1 cursor-pointer"
              @click="form.type = 'document'"
              for="file-upload">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="20"
                height="20"
                viewBox="0 0 56 56">
                <path
                  fill="currentColor"
                  d="M44.09 28.867L26.863 46.094c-4.476 4.5-10.5 4.054-14.343.164c-3.867-3.844-4.313-9.82.187-14.32L36.191 8.453c2.696-2.695 6.657-3.07 9.235-.515c2.555 2.601 2.18 6.539-.492 9.234L21.988 40.14c-1.171 1.195-2.554.843-3.375.047c-.796-.82-1.125-2.18.047-3.376l16.031-16.007c.704-.727.75-1.758.07-2.438c-.679-.656-1.71-.61-2.413.094L16.246 34.563c-2.39 2.39-2.297 6.046-.187 8.156c2.297 2.297 5.789 2.25 8.18-.164l23.085-23.063c4.383-4.383 4.172-10.148.375-13.969c-3.75-3.726-9.586-4.007-13.992.375L10.082 29.547c-5.789 5.789-5.344 14.062-.117 19.289c5.227 5.203 13.5 5.648 19.289-.117l17.344-17.344c.703-.68.68-1.922-.024-2.555c-.68-.726-1.78-.633-2.484.047" />
              </svg>
            </label>
          </div>
          <div>
            <label
              @click="form.type = 'audio'"
              class="py-1 cursor-pointer"
              for="file-upload">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="22"
                height="22"
                viewBox="0 0 24 24">
                <g
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.5">
                  <path
                    d="M9 19a3 3 0 1 1-6 0a3 3 0 0 1 6 0Zm12-2a3 3 0 1 1-6 0a3 3 0 0 1 6 0ZM9 19V8m12 9V6" />
                  <path
                    stroke-linecap="round"
                    d="m15.735 3.755l-4 1.333c-1.32.44-1.98.66-2.357 1.184S9 7.492 9 8.882V12l12-4v-.45c0-2.533 0-3.8-.83-4.398c-.831-.599-2.032-.198-4.435.603Z" />
                </g>
              </svg>
            </label>
          </div>
          <div>
            <label
              @click="form.type = 'video'"
              class="py-1 cursor-pointer"
              for="file-upload">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="28"
                height="28"
                viewBox="0 0 32 32">
                <path
                  fill="currentColor"
                  d="M6.5 5.5A4.5 4.5 0 0 0 2 10v12a4.5 4.5 0 0 0 4.5 4.5h12A4.5 4.5 0 0 0 23 22v-1.5l4.2 3.15c1.153.865 2.8.042 2.8-1.4V9.75c0-1.442-1.647-2.265-2.8-1.4L23 11.5V10a4.5 4.5 0 0 0-4.5-4.5zM23 14l5-3.75v11.5L23 18zm-2-4v12a2.5 2.5 0 0 1-2.5 2.5h-12A2.5 2.5 0 0 1 4 22V10a2.5 2.5 0 0 1 2.5-2.5h12A2.5 2.5 0 0 1 21 10" />
              </svg>
            </label>
          </div>
          <div>
            <label
              class="py-1 cursor-pointer"
              @click="form.type = 'image'"
              for="file-upload">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="24"
                height="24"
                viewBox="0 0 24 24">
                <g
                  fill="none"
                  stroke="currentColor"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="1.5">
                  <path
                    d="M6.5 8a2 2 0 1 0 4 0a2 2 0 0 0-4 0Zm14.427 1.99c-6.61-.908-12.31 4-11.927 10.51" />
                  <path d="M3 13.066c2.78-.385 5.275.958 6.624 3.1" />
                  <path
                    d="M3 12c0-4.243 0-6.364 1.318-7.682C5.636 3 7.758 3 12 3c4.243 0 6.364 0 7.682 1.318C21 5.636 21 7.758 21 12c0 4.243 0 6.364-1.318 7.682C18.364 21 16.242 21 12 21c-4.243 0-6.364 0-7.682-1.318C3 18.364 3 16.242 3 12Z" />
                </g>
              </svg>
            </label>
          </div>
        </div>
        <div>|</div>
        <button
          type="button"
          class="py-1 cursor-pointer relative"
          @click="startRecording"
          v-if="!isRecording && !audioPreviewUrl">
          <span class="relative">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="22"
              height="22"
              viewBox="0 0 1024 1024">
              <path
                fill="currentColor"
                d="M842 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 140.3-113.7 254-254 254S258 594.3 258 454c0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8c0 168.7 126.6 307.9 290 327.6V884H326.7c-13.7 0-24.7 14.3-24.7 32v36c0 4.4 2.8 8 6.2 8h407.6c3.4 0 6.2-3.6 6.2-8v-36c0-17.7-11-32-24.7-32H548V782.1c165.3-18 294-158 294-328.1M512 624c93.9 0 170-75.2 170-168V232c0-92.8-76.1-168-170-168s-170 75.2-170 168v224c0 92.8 76.1 168 170 168m-94-392c0-50.6 41.9-92 94-92s94 41.4 94 92v224c0 50.6-41.9 92-94 92s-94-41.4-94-92z" />
            </svg>
          </span>
        </button>
        <div class="ms-[-5px]">
          <button
            v-if="!processingAISuggestions"
            class="text-[14px] flex items-center gap-2 hover:bg-gray-100 p-1 rounded-md"
            type="button"
            @click="getSuggestion()">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="24"
              height="24"
              viewBox="0 0 24 24">
              <g
                fill="none"
                stroke="#000"
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.5"
                color="#000">
                <path d="m7 8l2.942 1.74c1.715 1.014 2.4 1.014 4.116 0L17 8" />
                <path
                  d="M21.984 12.976c.021-.986.021-1.966 0-2.952c-.065-3.065-.098-4.598-1.229-5.733c-1.131-1.136-2.705-1.175-5.854-1.254a115 115 0 0 0-5.802 0c-3.149.079-4.723.118-5.854 1.254c-1.131 1.135-1.164 2.668-1.23 5.733a69 69 0 0 0 0 2.952c.066 3.065.099 4.598 1.23 5.733c1.131 1.136 2.705 1.175 5.854 1.254c1.305.033 2.601.044 3.901.033" />
                <path
                  d="m18.5 14l.258.697c.338.914.507 1.371.84 1.704c.334.334.791.503 1.705.841L22 17.5l-.697.258c-.914.338-1.371.507-1.704.84c-.334.334-.503.791-.841 1.705L18.5 21l-.258-.697c-.338-.914-.507-1.371-.84-1.704c-.334-.334-.791-.503-1.705-.841L15 17.5l.697-.258c.914-.338 1.371-.507 1.704-.84c.334-.334.503-.791.841-1.705z" />
              </g>
            </svg>
            <span>{{ $t('AI Assist') }}</span>
          </button>
          <div
            v-else
            class="text-[14px] flex items-center gap-2 bg-gray-100 p-1 px-2 rounded-md">
            <span>{{ $t('Searching for suggestions') }}</span>
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="24"
              height="24"
              viewBox="0 0 24 24">
              <circle
                cx="18"
                cy="12"
                r="0"
                fill="currentColor">
                <animate
                  attributeName="r"
                  begin=".67"
                  calcMode="spline"
                  dur="1.5s"
                  keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                  repeatCount="indefinite"
                  values="0;2;0;0" />
              </circle>
              <circle
                cx="12"
                cy="12"
                r="0"
                fill="currentColor">
                <animate
                  attributeName="r"
                  begin=".33"
                  calcMode="spline"
                  dur="1.5s"
                  keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                  repeatCount="indefinite"
                  values="0;2;0;0" />
              </circle>
              <circle
                cx="6"
                cy="12"
                r="0"
                fill="currentColor">
                <animate
                  attributeName="r"
                  begin="0"
                  calcMode="spline"
                  dur="1.5s"
                  keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                  repeatCount="indefinite"
                  values="0;2;0;0" />
              </circle>
            </svg>
          </div>
        </div>
        <div class="ml-auto">
          <button
            class="flex items-center gap-x-2 p-1 px-2 rounded-md border border-gray-400 text-sm"
            :disabled="(formTextInput === null || formTextInput.trim() === '') && !form2.file">
            <span
              :class="
                (formTextInput === null || formTextInput.trim() === '') && !form2.file
                  ? 'text-slate-300'
                  : 'text-black'
              "
              >Send</span
            >
            <div>
              <svg
                v-if="!processingForm"
                :class="
                  (formTextInput === null || formTextInput.trim() === '') && !form2.file
                    ? 'text-slate-300'
                    : 'text-black'
                "
                xmlns="http://www.w3.org/2000/svg"
                width="16"
                height="16"
                viewBox="0 0 16 16">
                <path
                  fill="currentColor"
                  d="M1.724 1.053a.5.5 0 0 0-.714.545l1.403 4.85a.5.5 0 0 0 .397.354l5.69.953c.268.053.268.437 0 .49l-5.69.953a.5.5 0 0 0-.397.354l-1.403 4.85a.5.5 0 0 0 .714.545l13-6.5a.5.5 0 0 0 0-.894l-13-6.5Z" />
              </svg>
              <svg
                v-else
                xmlns="http://www.w3.org/2000/svg"
                width="16"
                height="16"
                viewBox="0 0 24 24">
                <circle
                  cx="12"
                  cy="2"
                  r="0"
                  fill="currentColor">
                  <animate
                    attributeName="r"
                    begin="0"
                    calcMode="spline"
                    dur="1s"
                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                    repeatCount="indefinite"
                    values="0;2;0;0" />
                </circle>
                <circle
                  cx="12"
                  cy="2"
                  r="0"
                  fill="currentColor"
                  transform="rotate(45 12 12)">
                  <animate
                    attributeName="r"
                    begin="0.125s"
                    calcMode="spline"
                    dur="1s"
                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                    repeatCount="indefinite"
                    values="0;2;0;0" />
                </circle>
                <circle
                  cx="12"
                  cy="2"
                  r="0"
                  fill="currentColor"
                  transform="rotate(90 12 12)">
                  <animate
                    attributeName="r"
                    begin="0.25s"
                    calcMode="spline"
                    dur="1s"
                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                    repeatCount="indefinite"
                    values="0;2;0;0" />
                </circle>
                <circle
                  cx="12"
                  cy="2"
                  r="0"
                  fill="currentColor"
                  transform="rotate(135 12 12)">
                  <animate
                    attributeName="r"
                    begin="0.375s"
                    calcMode="spline"
                    dur="1s"
                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                    repeatCount="indefinite"
                    values="0;2;0;0" />
                </circle>
                <circle
                  cx="12"
                  cy="2"
                  r="0"
                  fill="currentColor"
                  transform="rotate(180 12 12)">
                  <animate
                    attributeName="r"
                    begin="0.5s"
                    calcMode="spline"
                    dur="1s"
                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                    repeatCount="indefinite"
                    values="0;2;0;0" />
                </circle>
                <circle
                  cx="12"
                  cy="2"
                  r="0"
                  fill="currentColor"
                  transform="rotate(225 12 12)">
                  <animate
                    attributeName="r"
                    begin="0.625s"
                    calcMode="spline"
                    dur="1s"
                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                    repeatCount="indefinite"
                    values="0;2;0;0" />
                </circle>
                <circle
                  cx="12"
                  cy="2"
                  r="0"
                  fill="currentColor"
                  transform="rotate(270 12 12)">
                  <animate
                    attributeName="r"
                    begin="0.75s"
                    calcMode="spline"
                    dur="1s"
                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                    repeatCount="indefinite"
                    values="0;2;0;0" />
                </circle>
                <circle
                  cx="12"
                  cy="2"
                  r="0"
                  fill="currentColor"
                  transform="rotate(315 12 12)">
                  <animate
                    attributeName="r"
                    begin="0.875s"
                    calcMode="spline"
                    dur="1s"
                    keySplines="0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8;0.2 0.2 0.4 0.8"
                    repeatCount="indefinite"
                    values="0;2;0;0" />
                </circle>
              </svg>
            </div>
          </button>
        </div>
      </div>
    </div>
  </form>
</template>
