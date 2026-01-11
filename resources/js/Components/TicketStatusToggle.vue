<script setup>
import { Link } from '@inertiajs/vue3'
import { onMounted, onUnmounted, ref } from 'vue'

const props = defineProps({
  languages: Object,
  currentLanguage: String,
  status: String,
  rowCount: Number,
})
const isOpen = ref(false)

const toggleDropdown = () => {
  isOpen.value = !isOpen.value
}

const params = new URLSearchParams(window.location.search)
let isRead = null
if (params.has('is_read')) {
  isRead = params.get('is_read')
}
const handleClickOutside = (event) => {
  if (isOpen.value && !event.target.closest('.status-dd')) {
    isOpen.value = false
  }
}

const capitalizeString = (str) => {
  // Check if the string is empty or null
  if (!str) return ''

  // Capitalize the first character and concatenate it with the rest of the string
  return str.charAt(0).toUpperCase() + str.slice(1)
}

onMounted(() => {
  document.body.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.body.removeEventListener('click', handleClickOutside)
})
</script>
<template>
  <div class="relative text-sm">
    <div
      @click="toggleDropdown()"
      class="status-dd">
      <div
        class="cursor-pointer flex items-center space-x-1 bg-slate-50 w-[fit-content] h-[fit-content] rounded-md p-1 px-2 text-sm">
        <span class="capitalize"
          >{{ isRead ? $t('Unread') : $t(capitalizeString(props.status)) }}
          <!-- <pre>{{ currentActive }}</pre> -->
        </span>
        <span class="text-slate-500">{{ props.rowCount }}</span>
        <!-- <span class="text-slate-500">{{ props.rowCount }}</span> -->
        <span>
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="24"
            height="24"
            viewBox="0 0 24 24">
            <path
              fill="currentColor"
              fill-rule="evenodd"
              d="M16.53 8.97a.75.75 0 0 1 0 1.06l-4 4a.75.75 0 0 1-1.06 0l-4-4a.75.75 0 1 1 1.06-1.06L12 12.44l3.47-3.47a.75.75 0 0 1 1.06 0"
              clip-rule="evenodd" />
          </svg>
        </span>
      </div>
    </div>
    <div
      v-if="isOpen"
      class="absolute bg-white z-10 px-1 py-2 mt-2 shadow w-full rounded-md text-black">
      <div>
        <Link
          :href="'/chats?status=unassigned'"
          class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
          {{ $t('Unassigned') }}
        </Link>
        <Link
          :href="'/chats?status=open'"
          class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
          {{ $t('Open') }}
        </Link>
        <Link
          :href="'/chats?status=closed'"
          class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
          {{ $t('Closed') }}
        </Link>
        <Link
          :href="'/chats?is_read=0'"
          class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
          {{ $t('Unread') }}
        </Link>

        <Link
          :href="'/chats?status=all'"
          class="block px-2 py-1 cursor-pointer hover:bg-slate-100 rounded-md">
          {{ $t('All') }}
        </Link>
      </div>
    </div>
  </div>
</template>
