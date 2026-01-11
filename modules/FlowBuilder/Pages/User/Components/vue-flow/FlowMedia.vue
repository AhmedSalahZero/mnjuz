<script setup>
import axios from "axios"
import { ref } from 'vue'

const props = defineProps(['type', 'uuid', 'nodeId', 'modelValue'])
const file = ref(null)
const uuid = ref(props.uuid)
const nodeId = ref(props.nodeId)

const handleFileChange = (event) => {
  const input = event.target;
  if (input && input.files && input.files[0]) {
    file.value = input.files[0];
    uploadMedia();  // Call uploadMedia immediately when a file is selected
  }
};

const emit = defineEmits(['update:modelValue']);

const uploadMedia = async () => {
  if (!file.value) {
    //console.log('No file selected');
    return;
  }

  const formData = new FormData();
  formData.append('file', file.value);

  try {
    const response = await axios.post(`/automation/flows/upload-media/${uuid.value}/${nodeId.value}`, formData, {
      headers: {
        'Content-Type': 'multipart/form-data', // Ensure the content type is set for file upload
      },
    });

    emit('update:modelValue', response.data);
  } catch (error) {
    //console.error('Error uploading file:', error);
  }
};

const removeMedia = () => {
  emit('update:modelValue', []);
}
</script>
<template>
    <label class="mb-2 text-sm"><span class="text-red-500">*</span>  Upload media</label>
    <div class="flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
        <input @change="handleFileChange($event)" v-if="props.type == 'image'" type="file" class="sr-only" accept=".jpg, .png" :id="'file-upload' + props.nodeId">
        <input @change="handleFileChange($event)" v-if="props.type == 'video'" type="file" class="sr-only" accept=".mp4, .3gp" :id="'file-upload' + props.nodeId">
        <input @change="handleFileChange($event)" v-if="props.type == 'audio'" type="file" class="sr-only" accept=".aac, .mp3, .amr, .m4a" :id="'file-upload' + props.nodeId">
        <input @change="handleFileChange($event)" v-if="props.type == 'document'" type="file" class="sr-only" accept=".pdf, .txt, .xls, .xlsx, .doc, .docx, .ppt, .pptx" :id="'file-upload' + props.nodeId">
        <div class="text-center">
        <div v-if="modelValue.length == 0">
            <label for="file-upload">
            <svg v-if="props.type == 'image'" class="mx-auto h-12 w-12 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M14 9a1.5 1.5 0 1 1 3 0a1.5 1.5 0 0 1-3 0Z"></path><path fill="currentColor" fill-rule="evenodd" d="M7.268 4.658a54.647 54.647 0 0 1 9.465 0l1.51.132a3.138 3.138 0 0 1 2.831 2.66a30.604 30.604 0 0 1 0 9.1a3.138 3.138 0 0 1-2.831 2.66l-1.51.131c-3.15.274-6.316.274-9.465 0l-1.51-.131a3.138 3.138 0 0 1-2.832-2.66a30.601 30.601 0 0 1 0-9.1a3.138 3.138 0 0 1 2.831-2.66l1.51-.132Zm9.335 1.495a53.147 53.147 0 0 0-9.206 0l-1.51.131A1.638 1.638 0 0 0 4.41 7.672a29.101 29.101 0 0 0-.311 5.17L7.97 8.97a.75.75 0 0 1 1.09.032l3.672 4.13l2.53-.844a.75.75 0 0 1 .796.21l3.519 3.91a29.101 29.101 0 0 0 .014-8.736a1.638 1.638 0 0 0-1.478-1.388l-1.51-.131Zm2.017 11.435l-3.349-3.721l-2.534.844a.75.75 0 0 1-.798-.213l-3.471-3.905l-4.244 4.243c.049.498.11.996.185 1.491a1.638 1.638 0 0 0 1.478 1.389l1.51.131c3.063.266 6.143.266 9.206 0l1.51-.131c.178-.016.35-.06.507-.128Z" clip-rule="evenodd"></path></svg>
            <svg v-if="props.type == 'video'" class="mx-auto h-12 w-12 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.5" d="M2 11.5c0-3.287 0-4.931.908-6.038a4 4 0 0 1 .554-.554C4.57 4 6.212 4 9.5 4c3.287 0 4.931 0 6.038.908a4 4 0 0 1 .554.554C17 6.57 17 8.212 17 11.5v1c0 3.287 0 4.931-.908 6.038a4.001 4.001 0 0 1-.554.554C14.43 20 12.788 20 9.5 20c-3.287 0-4.931 0-6.038-.908a4 4 0 0 1-.554-.554C2 17.43 2 15.788 2 12.5v-1Zm15-2l.658-.329c1.946-.973 2.92-1.46 3.63-1.02c.712.44.712 1.528.712 3.703v.292c0 2.176 0 3.263-.711 3.703c-.712.44-1.685-.047-3.63-1.02L17 14.5v-5Z"></path></svg>
            <svg v-if="props.type == 'audio'" class="mx-auto h-12 w-12 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" color="currentColor"><path d="M21 19.083v-3.166c0-1.769 0-2.653-.54-2.873s-1.176.405-2.447 1.656c-.662.651-1.047.791-1.971.791c-.82 0-1.229 0-1.523.194c-.604.396-.519 1.178-.519 1.815s-.085 1.419.519 1.815c.294.194.704.194 1.523.194c.924 0 1.309.14 1.97.791c1.272 1.251 1.908 1.877 2.448 1.656c.54-.22.54-1.104.54-2.873"/><path d="M12 22h-1.273c-3.26 0-4.892 0-6.024-.798a4.1 4.1 0 0 1-.855-.805C3 19.331 3 17.797 3 14.727v-2.545c0-2.963 0-4.445.469-5.628c.754-1.903 2.348-3.403 4.37-4.113C9.095 2 10.668 2 13.818 2c1.798 0 2.698 0 3.416.252c1.155.406 2.066 1.263 2.497 2.35C20 5.278 20 6.125 20 7.818V10"/><path d="M3 12a3.333 3.333 0 0 1 3.333-3.333c.666 0 1.451.116 2.098-.057A1.67 1.67 0 0 0 9.61 7.43c.173-.647.057-1.432.057-2.098A3.333 3.333 0 0 1 13 2"/></g></svg>
            <svg v-if="props.type == 'document'" class="mx-auto h-12 w-12 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M18.53 9L13 3.47a.75.75 0 0 0-.53-.22H8A2.75 2.75 0 0 0 5.25 6v12A2.75 2.75 0 0 0 8 20.75h8A2.75 2.75 0 0 0 18.75 18V9.5a.75.75 0 0 0-.22-.5Zm-5.28-3.19l2.94 2.94h-2.94ZM16 19.25H8A1.25 1.25 0 0 1 6.75 18V6A1.25 1.25 0 0 1 8 4.75h3.75V9.5a.76.76 0 0 0 .75.75h4.75V18A1.25 1.25 0 0 1 16 19.25Z"></path><path fill="currentColor" d="M13.49 14.85a3.15 3.15 0 0 1-1.31-1.66a4.44 4.44 0 0 0 .19-2a.8.8 0 0 0-1.52-.19a5 5 0 0 0 .25 2.4A29 29 0 0 1 9.83 16c-.71.4-1.68 1-1.83 1.69c-.12.56.93 2 2.72-1.12a18.58 18.58 0 0 1 2.44-.72a4.72 4.72 0 0 0 2 .61a.82.82 0 0 0 .62-1.38c-.42-.43-1.67-.31-2.29-.23Zm-4.78 3a4.32 4.32 0 0 1 1.09-1.24c-.68 1.08-1.09 1.27-1.09 1.25Zm2.92-6.81c.26 0 .24 1.15.06 1.46a3.07 3.07 0 0 1-.06-1.45Zm-.87 4.88a14.76 14.76 0 0 0 .88-1.92a3.88 3.88 0 0 0 1.08 1.26a12.35 12.35 0 0 0-1.96.67Zm4.7-.18s-.18.22-1.33-.28c1.25-.08 1.46.21 1.33.29Z"></path></svg>
            </label>
            <div class="flex text-sm text-gray-600">
            <label :for="'file-upload' + props.nodeId" class="text-center relative cursor-pointer bg-white rounded-md font-medium hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                <span v-if="props.type == 'image'">Upload image file</span>
                <span v-if="props.type == 'video'">Upload video file</span>
                <span v-if="props.type == 'audio'">Upload audio file</span>
                <span v-if="props.type == 'document'">Upload document file</span>
            </label>
            </div>
            <p v-if="props.type == 'image'" class="text-xs text-gray-500">PNG or JPG files only</p>
            <p v-if="props.type == 'video'" class="text-xs text-gray-500">MP4 or 3GPP files only</p>
            <p v-if="props.type == 'audio'" class="text-xs text-gray-500">MP3/AAC/AMR or <br> MP4 files only</p>
            <p v-if="props.type == 'document'" class="text-xs text-gray-500">PDF/TXT/XLS/XLSX/DOC/DOCX/PPT or PPTX files only</p>
        </div>
        <div v-else class="flex text-sm text-gray-600">
            <div class="">
                <div class="text-sm">{{ JSON.parse(props.modelValue.metadata)?.name }}</div>
                <button @click="removeMedia()" class="flex items-center gap-x-1 bg-slate-50 rounded-md px-2 py-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                    Remove
                </button>
            </div>
        </div>
        </div>
    </div>
</template>