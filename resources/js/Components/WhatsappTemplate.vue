<script setup>
    import { ref } from 'vue';

    const props = defineProps({
        placeholder: {
            type: Boolean,
            default: true
        },
        parameters: {
            type: Object,
            default: null
        },
        visible: Boolean,
    })

    const formatText = (text) => {
        if (text === null || text === undefined) {
            return '';
        }

        return text.replace(/\n/g, '<br>');
    };

    const getExtension = (fileUrl) => {
        // Extract the file extension from the URL
        const extension = fileUrl.split('.').pop().toUpperCase();

        // Define a set of known extensions
        const knownExtensions = ['TXT', 'PDF', 'PPT', 'DOC', 'XLS', 'DOCX', 'PPTX', 'XLSX'];

        // Return the extension if it is known, otherwise return 'Unknown'
        return knownExtensions.includes(extension) ? extension : 'Unknown';
    };

    const downloading = ref(false);

    const downloadClicked = () => {
        downloading.value = true;
        setTimeout(() => {
            downloading.value = false;
        }, 2000);
    };
</script>
<template>
    <div v-if="visible" class="mr-auto rounded-lg rounded-tl-none my-[0.1em] p-1 text-sm bg-white flex flex-col relative speech-bubble-left max-w-[25em]">
        <div v-if="parameters.header.format != null && parameters.header.format != 'TEXT'">
            <div v-if="placeholder == true" class="bg-[#ccd0d5] mb-4 flex justify-center rounded py-8">
                <img v-if="parameters.header.format === 'IMAGE'" :src="'/images/image-placeholder.png'">
                <img v-if="parameters.header.format === 'VIDEO'" :src="'/images/video-placeholder.png'">
                <img v-if="parameters.header.format === 'DOCUMENT'" :src="'/images/document-placeholder.png'">
            </div>
            <div v-else class="mb-4 flex justify-center rounded">
                <div v-if="parameters.header.format === 'IMAGE'" class="relative w-full h-full">
                    <img :src="parameters.header.parameters[0].value" alt="Image" class="rounded-md flex justify-center items-center z-1 w-full h-full" />
                </div>
                <video v-if="parameters.header.format === 'VIDEO'" controls width="300" class="max-h-[350px]">
                    <source :src="parameters.header.parameters[0].value" type="video/mp4">
                </video>
                <div v-if="parameters.header.format === 'DOCUMENT'">
                    <div class="relative">
                        <div class="flex space-x-2 w-full h-1/3 bg-white opacity-90 pt-2">
                            <div>
                                <svg v-if="getExtension(parameters.header.parameters[0].value) === 'PDF'" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 1024 1024"><path fill="red" d="m531.3 574.4l.3-1.4c5.8-23.9 13.1-53.7 7.4-80.7c-3.8-21.3-19.5-29.6-32.9-30.2c-15.8-.7-29.9 8.3-33.4 21.4c-6.6 24-.7 56.8 10.1 98.6c-13.6 32.4-35.3 79.5-51.2 107.5c-29.6 15.3-69.3 38.9-75.2 68.7c-1.2 5.5.2 12.5 3.5 18.8c3.7 7 9.6 12.4 16.5 15c3 1.1 6.6 2 10.8 2c17.6 0 46.1-14.2 84.1-79.4c5.8-1.9 11.8-3.9 17.6-5.9c27.2-9.2 55.4-18.8 80.9-23.1c28.2 15.1 60.3 24.8 82.1 24.8c21.6 0 30.1-12.8 33.3-20.5c5.6-13.5 2.9-30.5-6.2-39.6c-13.2-13-45.3-16.4-95.3-10.2c-24.6-15-40.7-35.4-52.4-65.8zM421.6 726.3c-13.9 20.2-24.4 30.3-30.1 34.7c6.7-12.3 19.8-25.3 30.1-34.7zm87.6-235.5c5.2 8.9 4.5 35.8.5 49.4c-4.9-19.9-5.6-48.1-2.7-51.4c.8.1 1.5.7 2.2 2zm-1.6 120.5c10.7 18.5 24.2 34.4 39.1 46.2c-21.6 4.9-41.3 13-58.9 20.2c-4.2 1.7-8.3 3.4-12.3 5c13.3-24.1 24.4-51.4 32.1-71.4zm155.6 65.5c.1.2.2.5-.4.9h-.2l-.2.3c-.8.5-9 5.3-44.3-8.6c40.6-1.9 45 7.3 45.1 7.4zm191.4-388.2L639.4 73.4c-6-6-14.1-9.4-22.6-9.4H192c-17.7 0-32 14.3-32 32v832c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V311.3c0-8.5-3.4-16.7-9.4-22.7zM790.2 326H602V137.8L790.2 326zm1.8 562H232V136h302v216a42 42 0 0 0 42 42h216v494z"/></svg>
                                <svg v-if="getExtension(parameters.header.parameters[0].value) === 'XLSX' || getExtension(parameters.header.parameters[0].value) === 'XLS'" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 32 32"><defs><linearGradient id="vscodeIconsFileTypeExcel0" x1="4.494" x2="13.832" y1="-2092.086" y2="-2075.914" gradientTransform="translate(0 2100)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#18884f"/><stop offset=".5" stop-color="#117e43"/><stop offset="1" stop-color="#0b6631"/></linearGradient></defs><path fill="#185c37" d="M19.581 15.35L8.512 13.4v14.409A1.192 1.192 0 0 0 9.705 29h19.1A1.192 1.192 0 0 0 30 27.809V22.5Z"/><path fill="#21a366" d="M19.581 3H9.705a1.192 1.192 0 0 0-1.193 1.191V9.5L19.581 16l5.861 1.95L30 16V9.5Z"/><path fill="#107c41" d="M8.512 9.5h11.069V16H8.512Z"/><path d="M16.434 8.2H8.512v16.25h7.922a1.2 1.2 0 0 0 1.194-1.191V9.391A1.2 1.2 0 0 0 16.434 8.2Z" opacity=".1"/><path d="M15.783 8.85H8.512V25.1h7.271a1.2 1.2 0 0 0 1.194-1.191V10.041a1.2 1.2 0 0 0-1.194-1.191Z" opacity=".2"/><path d="M15.783 8.85H8.512V23.8h7.271a1.2 1.2 0 0 0 1.194-1.191V10.041a1.2 1.2 0 0 0-1.194-1.191Z" opacity=".2"/><path d="M15.132 8.85h-6.62V23.8h6.62a1.2 1.2 0 0 0 1.194-1.191V10.041a1.2 1.2 0 0 0-1.194-1.191Z" opacity=".2"/><path fill="url(#vscodeIconsFileTypeExcel0)" d="M3.194 8.85h11.938a1.193 1.193 0 0 1 1.194 1.191v11.918a1.193 1.193 0 0 1-1.194 1.191H3.194A1.192 1.192 0 0 1 2 21.959V10.041A1.192 1.192 0 0 1 3.194 8.85Z"/><path fill="#fff" d="m5.7 19.873l2.511-3.884l-2.3-3.862h1.847L9.013 14.6c.116.234.2.408.238.524h.017c.082-.188.169-.369.26-.546l1.342-2.447h1.7l-2.359 3.84l2.419 3.905h-1.809l-1.45-2.711A2.355 2.355 0 0 1 9.2 16.8h-.024a1.688 1.688 0 0 1-.168.351l-1.493 2.722Z"/><path fill="#33c481" d="M28.806 3h-9.225v6.5H30V4.191A1.192 1.192 0 0 0 28.806 3Z"/><path fill="#107c41" d="M19.581 16H30v6.5H19.581Z"/></svg>
                                <svg v-if="getExtension(parameters.header.parameters[0].value) === 'DOC' || getExtension(parameters.header.parameters[0].value) === 'DOCX'" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 32 32"><defs><linearGradient id="vscodeIconsFileTypeWord0" x1="4.494" x2="13.832" y1="-1712.086" y2="-1695.914" gradientTransform="translate(0 1720)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#2368c4"/><stop offset=".5" stop-color="#1a5dbe"/><stop offset="1" stop-color="#1146ac"/></linearGradient></defs><path fill="#41a5ee" d="M28.806 3H9.705a1.192 1.192 0 0 0-1.193 1.191V9.5l11.069 3.25L30 9.5V4.191A1.192 1.192 0 0 0 28.806 3Z"/><path fill="#2b7cd3" d="M30 9.5H8.512V16l11.069 1.95L30 16Z"/><path fill="#185abd" d="M8.512 16v6.5l10.418 1.3L30 22.5V16Z"/><path fill="#103f91" d="M9.705 29h19.1A1.192 1.192 0 0 0 30 27.809V22.5H8.512v5.309A1.192 1.192 0 0 0 9.705 29Z"/><path d="M16.434 8.2H8.512v16.25h7.922a1.2 1.2 0 0 0 1.194-1.191V9.391A1.2 1.2 0 0 0 16.434 8.2Z" opacity=".1"/><path d="M15.783 8.85H8.512V25.1h7.271a1.2 1.2 0 0 0 1.194-1.191V10.041a1.2 1.2 0 0 0-1.194-1.191Z" opacity=".2"/><path d="M15.783 8.85H8.512V23.8h7.271a1.2 1.2 0 0 0 1.194-1.191V10.041a1.2 1.2 0 0 0-1.194-1.191Z" opacity=".2"/><path d="M15.132 8.85h-6.62V23.8h6.62a1.2 1.2 0 0 0 1.194-1.191V10.041a1.2 1.2 0 0 0-1.194-1.191Z" opacity=".2"/><path fill="url(#vscodeIconsFileTypeWord0)" d="M3.194 8.85h11.938a1.193 1.193 0 0 1 1.194 1.191v11.918a1.193 1.193 0 0 1-1.194 1.191H3.194A1.192 1.192 0 0 1 2 21.959V10.041A1.192 1.192 0 0 1 3.194 8.85Z"/><path fill="#fff" d="M6.9 17.988c.023.184.039.344.046.481h.028c.01-.13.032-.287.065-.47s.062-.338.089-.465l1.255-5.407h1.624l1.3 5.326a7.761 7.761 0 0 1 .162 1h.022a7.6 7.6 0 0 1 .135-.975l1.039-5.358h1.477l-1.824 7.748h-1.727l-1.237-5.126q-.054-.222-.122-.578t-.084-.52h-.021q-.021.189-.084.561c-.042.249-.075.432-.1.552L7.78 19.871H6.024L4.19 12.127h1.5l1.131 5.418a4.469 4.469 0 0 1 .079.443Z"/></svg>
                                <svg v-if="getExtension(parameters.header.parameters[0].value) === 'PPT' || getExtension(parameters.header.parameters[0].value) === 'PPTX'" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 32 32"><defs><linearGradient id="vscodeIconsFileTypePowerpoint0" x1="4.494" x2="13.832" y1="-1748.086" y2="-1731.914" gradientTransform="translate(0 1756)" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ca4c28"/><stop offset=".5" stop-color="#c5401e"/><stop offset="1" stop-color="#b62f14"/></linearGradient></defs><path fill="#ed6c47" d="M18.93 17.3L16.977 3h-.146A12.9 12.9 0 0 0 3.953 15.854V16Z"/><path fill="#ff8f6b" d="M17.123 3h-.146v13l6.511 2.6L30 16v-.146A12.9 12.9 0 0 0 17.123 3Z"/><path fill="#d35230" d="M30 16v.143A12.905 12.905 0 0 1 17.12 29h-.287a12.907 12.907 0 0 1-12.88-12.857V16Z"/><path d="M17.628 9.389V23.26a1.2 1.2 0 0 1-.742 1.1a1.16 1.16 0 0 1-.45.091H7.027a10.08 10.08 0 0 1-.521-.65a12.735 12.735 0 0 1-2.553-7.657v-.286A12.705 12.705 0 0 1 6.05 8.85a8.82 8.82 0 0 1 .456-.65h9.93a1.2 1.2 0 0 1 1.192 1.189Z" opacity=".1"/><path d="M16.977 10.04v13.871a1.15 1.15 0 0 1-.091.448a1.2 1.2 0 0 1-1.1.741H7.62q-.309-.314-.593-.65a10.08 10.08 0 0 1-.521-.65a12.735 12.735 0 0 1-2.553-7.657v-.286A12.705 12.705 0 0 1 6.05 8.85h9.735a1.2 1.2 0 0 1 1.192 1.19Z" opacity=".2"/><path d="M16.977 10.04v12.571a1.2 1.2 0 0 1-1.192 1.189H6.506a12.735 12.735 0 0 1-2.553-7.657v-.286A12.705 12.705 0 0 1 6.05 8.85h9.735a1.2 1.2 0 0 1 1.192 1.19Z" opacity=".2"/><path d="M16.326 10.04v12.571a1.2 1.2 0 0 1-1.192 1.189H6.506a12.735 12.735 0 0 1-2.553-7.657v-.286A12.705 12.705 0 0 1 6.05 8.85h9.084a1.2 1.2 0 0 1 1.192 1.19Z" opacity=".2"/><path fill="url(#vscodeIconsFileTypePowerpoint0)" d="M3.194 8.85h11.938a1.193 1.193 0 0 1 1.194 1.191v11.918a1.193 1.193 0 0 1-1.194 1.191H3.194A1.192 1.192 0 0 1 2 21.959V10.041A1.192 1.192 0 0 1 3.194 8.85Z"/><path fill="#fff" d="M9.293 12.028a3.287 3.287 0 0 1 2.174.636a2.27 2.27 0 0 1 .756 1.841a2.555 2.555 0 0 1-.373 1.376a2.49 2.49 0 0 1-1.059.935a3.607 3.607 0 0 1-1.591.334H7.687v2.8H6.141v-7.922ZM7.686 15.94h1.331a1.735 1.735 0 0 0 1.177-.351a1.3 1.3 0 0 0 .4-1.025q0-1.309-1.525-1.31H7.686v2.686Z"/></svg>
                                <svg v-if="getExtension(parameters.header.parameters[0].value) === 'TXT'" xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 16 16"><path fill="black" fill-rule="evenodd" d="M14 4.5V14a2 2 0 0 1-2 2h-2v-1h2a1 1 0 0 0 1-1V4.5h-2A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v9H2V2a2 2 0 0 1 2-2h5.5zM1.928 15.849v-3.337h1.136v-.662H0v.662h1.134v3.337zm4.689-3.999h-.894L4.9 13.289h-.035l-.832-1.439h-.932l1.228 1.983l-1.24 2.016h.862l.853-1.415h.035l.85 1.415h.907l-1.253-1.992zm1.93.662v3.337h-.794v-3.337H6.619v-.662h3.064v.662H8.546Z"/></svg>
                            </div>
                            <div>
                                <div>
                                    <p class="overflow-ellipsis overflow-hidden line-clamp-2 w-[200px]">{{ $t('Document') }}</p>
                                </div>
                                <div class="flex items-center text-slate-500 text-[11px]">
                                    <span>{{ getExtension(parameters.header.parameters[0].value) }}</span>
                                </div>
                            </div>
                            <a :href="parameters.header.parameters[0].value" @click="downloadClicked" class="flex justify-end w-full">
                                <svg v-if="!downloading" xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24"><path fill="currentColor" d="M4.025 11.975q0 2.825 1.725 5t4.4 2.825q.375.1.6.425t.225.725q0 .4-.275.663t-.625.187q-3.5-.7-5.775-3.437t-2.275-6.388q0-3.65 2.288-6.387T10.1 2.15q.35-.075.625.188T11 3q0 .4-.225.725t-.6.425q-2.7.65-4.425 2.825t-1.725 5Zm8.95 1.15l1.85-1.85q.3-.3.725-.3t.725.3q.3.3.288.725t-.313.725L12.675 16.3q-.3.3-.7.3t-.7-.3L7.65 12.65q-.3-.3-.288-.713t.313-.712q.3-.3.713-.3t.712.3l1.875 1.9V8q0-.425.288-.713T11.975 7q.425 0 .713.288t.287.712v5.125Zm3.05 5.75q.375-.225.813-.2t.762.35q.3.3.25.713t-.4.637q-.8.5-1.675.863t-1.8.562q-.4.075-.7-.175t-.3-.675q0-.425.263-.75t.687-.45q.55-.15 1.075-.375t1.025-.5ZM13.975 4.2q-.425-.125-.7-.45T13 3q0-.425.288-.675t.687-.175q.95.2 1.838.562T17.5 3.6q.35.225.375.625t-.25.7q-.3.325-.738.35t-.812-.2q-.5-.275-1.025-.5T13.975 4.2Zm5.75 9.75q.125-.425.463-.7t.762-.275q.425 0 .688.325t.162.725q-.2.95-.575 1.812t-.9 1.663q-.225.35-.625.35t-.675-.275q-.3-.3-.337-.725t.187-.8q.275-.5.488-1.012t.362-1.088Zm-.85-6.075q-.225-.375-.188-.788t.338-.712q.275-.275.675-.263t.625.338q.55.8.925 1.688t.575 1.837q.075.4-.187.7t-.688.3q-.425 0-.762-.287t-.463-.713q-.15-.55-.363-1.075t-.487-1.025Z"/></svg>
                                <svg v-else xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="2"><path stroke-dasharray="2 4" stroke-dashoffset="6" d="M12 3C16.9706 3 21 7.02944 21 12C21 16.9706 16.9706 21 12 21"><animate attributeName="stroke-dashoffset" dur="0.6s" repeatCount="indefinite" values="6;0"/></path><path stroke-dasharray="30" stroke-dashoffset="30" d="M12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3"><animate fill="freeze" attributeName="stroke-dashoffset" begin="0.1s" dur="0.3s" values="30;0"/></path><path stroke-dasharray="10" stroke-dashoffset="10" d="M12 8v7.5"><animate fill="freeze" attributeName="stroke-dashoffset" begin="0.5s" dur="0.2s" values="10;0"/></path><path stroke-dasharray="6" stroke-dashoffset="6" d="M12 15.5l3.5 -3.5M12 15.5l-3.5 -3.5"><animate fill="freeze" attributeName="stroke-dashoffset" begin="0.7s" dur="0.2s" values="6;0"/></path></g></svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <h2 v-else class="text-gray-700 text-[16px] mb-1 px-2">{{ parameters.header.text }}</h2>
        <p class="px-2" v-html="formatText(parameters.body.text)"></p>
        <div class="text-[#8c8c8c] mt-1 px-2">
            <span class="text-[13px]">{{ parameters.footer.text }}</span>
            <span class="text-right text-xs leading-none float-right" :class="parameters.footer.text ? 'mt-2' : ''">9:15</span>
        </div>
    </div>
    <div v-if="parameters?.buttons?.length > 0" class="mr-auto text-sm text-[#00a5f4] flex flex-col relative max-w-[25em]">
        <div v-for="(item, index) in parameters.buttons" :key="index" class="flex justify-center items-center space-x-2 rounded-lg bg-white h-10 my-[0.1em]">
            <span>
                <svg v-if="item.type === 'COPY_CODE'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M19 21H8V7h11m0-2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2m-3-4H4a2 2 0 0 0-2 2v14h2V3h12V1Z"/></svg>
                <svg v-else-if="item.type === 'PHONE_NUMBER'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><g fill="none"><path fill="currentColor" d="M20 16v4c-2.758 0-5.07-.495-7-1.325c-3.841-1.652-6.176-4.63-7.5-7.675C4.4 8.472 4 5.898 4 4h4l1 4l-3.5 3c1.324 3.045 3.659 6.023 7.5 7.675L16 15l4 1z"/><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 18.675c1.93.83 4.242 1.325 7 1.325v-4l-4-1l-3 3.675zm0 0C9.159 17.023 6.824 14.045 5.5 11m0 0C4.4 8.472 4 5.898 4 4h4l1 4l-3.5 3z"/></g></svg>
                <img v-else-if="item.type === 'URL'" :src="'/images/icons/link.png'" class="h-4">
                <img v-else :src="'/images/icons/reply.png'" class="h-4">
            </span>
            <span>{{ item.text }}</span>
        </div>
    </div>
</template>