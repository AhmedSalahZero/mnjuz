<template>
    <AppLayout>
        <div class="px-4 md:px-0 flex flex-col bg-white border-l py-4 text-[#000] md:overflow-y-hidden">
            <div class="flex justify-between md:px-8 border-b pb-4">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('New template') }}</h2>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Create template for review') }}</span>
                    </p>
                </div>
                <div class="space-x-2 flex items-center">
                    <Link href="/templates" class="rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                    <button @click="submitForm()" type="button" class="capitalize rounded-md px-3 py-2 float-right text-sm text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" :class="isFormValid === true ? 'bg-indigo-600 hover:bg-indigo-500 shadow-sm' : 'bg-gray-200'" :disabled="!isFormValid || isLoading">
                        <span v-if="!isLoading">{{ $t('Create template') }}</span>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                    </button>
                </div>
            </div>
            <div class="md:flex md:flex-grow-1 md:h-[88vh] mt-4 md:mt-0">
                <div class="md:w-[50%] md:p-8 overflow-y-auto">
                    <div v-if="!settings?.whatsapp" class="p-4 md:p-8 overflow-y-auto p-4">
                        <div class="bg-slate-50 border border-primary shadow rounded-md p-4 py-8">
                            <div class="flex justify-center mb-4">
                                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 48 48"><path fill="black" d="M43.634 4.366a1.25 1.25 0 0 1 0 1.768l-4.913 4.913a9.253 9.253 0 0 1-.744 12.244l-3.343 3.343a1.25 1.25 0 0 1-1.768 0l-11.5-11.5a1.25 1.25 0 0 1 0-1.768l3.343-3.343a9.25 9.25 0 0 1 12.244-.743l4.913-4.914a1.25 1.25 0 0 1 1.768 0m-7.611 7.425a6.75 6.75 0 0 0-9.546 0l-2.46 2.459l9.733 9.732l2.46-2.459a6.75 6.75 0 0 0 0-9.546zM9.28 36.953l-4.914 4.913a1.25 1.25 0 0 0 1.768 1.768l4.913-4.913a9.253 9.253 0 0 0 12.244-.744l3.343-3.343a1.25 1.25 0 0 0 0-1.768L25.268 31.5l3.366-3.366a1.25 1.25 0 0 0-1.768-1.768L23.5 29.732L18.268 24.5l3.366-3.366a1.25 1.25 0 0 0-1.768-1.768L16.5 22.732l-1.366-1.366a1.25 1.25 0 0 0-1.768 0l-3.343 3.343a9.25 9.25 0 0 0-.743 12.244m2.51-10.476l2.46-2.46l9.732 9.733l-2.459 2.46a6.75 6.75 0 0 1-9.546 0l-.186-.187a6.75 6.75 0 0 1 0-9.546"/></svg>
                            </div>
                            <h3 class="text-center text-lg font-medium mb-4">{{ $t('Connect your whatsapp account') }}</h3>
                            <h4 class="text-center mb-4">{{ $t('You need to connect your WhatsApp account first before you can create a template.') }}</h4>
                            <div class="flex justify-center">
                                <Link href="/settings/whatsapp" class="rounded-md px-3 py-2 text-sm hover:shadow-md text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 bg-primary" :disabled="isLoading">
                                    <span v-if="!isLoading">{{ $t('Connect Whatsapp account') }}</span>
                                </Link>
                            </div>
                        </div>
                    </div>
                    <div v-else>
                        <div class="grid gap-x-6 gap-y-4 sm:grid-cols-6 mb-8 capitalize">
                            <FormInput v-model="form.name" :name="$t('Name')" :type="'text'" @input="handleNameInput" @keydown.space.prevent="addUnderscore" :class="'sm:col-span-6'"/>
                            <FormSelect v-model="form.category" :options="categoryOptions" :name="$t('Category')" :class="'sm:col-span-3'" :placeholder="$t('Select category')"/>
                            <FormSelect v-model="form.language" :options="langOptions" :name="$t('Language')" :class="'sm:col-span-3'" :placeholder="$t('Select language')"/>
                        </div>
                        <div v-if="form.category === 'UTILITY' || form.category === 'MARKETING'">
                            <h2 class="text-slate-600">{{ $t('Header') }} <span class="text-xs">({{ $t('Optional') }})</span></h2>
                            <span class="text-slate-600 text-xs">{{ $t('Add a title or choose which type of media you\'ll use for this header') }}</span>
                            <div class="grid grid-cols-4 mt-2 bg-[#f9f9fa] rounded-lg mb-4">
                                <button @click="changeHeaderType('TEXT')" class="text-center py-2 text-sm text-slate-800 m-1" :class="form.header.format === 'TEXT' ? 'bg-white shadow rounded-lg' : ''">{{ $t('Text') }}</button>
                                <button @click="changeHeaderType('IMAGE')" class="text-center py-2 text-sm text-slate-800 m-1" :class="form.header.format === 'IMAGE' ? 'bg-white shadow rounded-lg' : ''">{{ $t('Image') }}</button>
                                <button @click="changeHeaderType('VIDEO')" class="text-center py-2 text-sm text-slate-800 m-1" :class="form.header.format === 'VIDEO' ? 'bg-white shadow rounded-lg' : ''">{{ $t('Video') }}</button>
                                <button @click="changeHeaderType('DOCUMENT')" class="text-center py-2 text-sm text-slate-800 m-1" :class="form.header.format === 'DOCUMENT' ? 'bg-white shadow rounded-lg' : ''">{{ $t('Document') }}</button>
                            </div>
                            <div class="mb-8">
                                <div :class="form.header.format === 'TEXT' ? '' : 'hidden'">
                                    <HeaderTextArea v-model="form.header.text" :customValues="form.header.example" @updateExamples="updateHeaderExamples"/>
                                </div>
                                <div v-if="form.header.format === 'IMAGE'">
                                    <div class="flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                                        <input
                                            type="file"
                                            class="sr-only"
                                            accept=".jpg, .png"
                                            ref="fileInput"
                                            id="file-upload"
                                            @change="handleFileUpload($event)"
                                        />
                                        <div class="text-center">
                                            <div>
                                                <div v-if="form.header.format === 'IMAGE' && form.header.example" class="flex justify-center items-center">
                                                    <div class="flex justify-center items-center space-x-3 py-1 border bg-slate-100 rounded-lg mb-2 w-fit px-2">
                                                        <div>
                                                            <svg class="mx-auto h-6 w-6 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M14 9a1.5 1.5 0 1 1 3 0a1.5 1.5 0 0 1-3 0Z"/><path fill="currentColor" fill-rule="evenodd" d="M7.268 4.658a54.647 54.647 0 0 1 9.465 0l1.51.132a3.138 3.138 0 0 1 2.831 2.66a30.604 30.604 0 0 1 0 9.1a3.138 3.138 0 0 1-2.831 2.66l-1.51.131c-3.15.274-6.316.274-9.465 0l-1.51-.131a3.138 3.138 0 0 1-2.832-2.66a30.601 30.601 0 0 1 0-9.1a3.138 3.138 0 0 1 2.831-2.66l1.51-.132Zm9.335 1.495a53.147 53.147 0 0 0-9.206 0l-1.51.131A1.638 1.638 0 0 0 4.41 7.672a29.101 29.101 0 0 0-.311 5.17L7.97 8.97a.75.75 0 0 1 1.09.032l3.672 4.13l2.53-.844a.75.75 0 0 1 .796.21l3.519 3.91a29.101 29.101 0 0 0 .014-8.736a1.638 1.638 0 0 0-1.478-1.388l-1.51-.131Zm2.017 11.435l-3.349-3.721l-2.534.844a.75.75 0 0 1-.798-.213l-3.471-3.905l-4.244 4.243c.049.498.11.996.185 1.491a1.638 1.638 0 0 0 1.478 1.389l1.51.131c3.063.266 6.143.266 9.206 0l1.51-.131c.178-.016.35-.06.507-.128Z" clip-rule="evenodd"/></svg>
                                                        </div>
                                                        <div class="flex items-center space-x-2">
                                                            <span class="text-sm">{{ form.header.example.name }}</span>
                                                            <button @click="removeFile()">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <label v-else for="file-upload">
                                                    <svg class="mx-auto h-12 w-12 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M14 9a1.5 1.5 0 1 1 3 0a1.5 1.5 0 0 1-3 0Z"/><path fill="currentColor" fill-rule="evenodd" d="M7.268 4.658a54.647 54.647 0 0 1 9.465 0l1.51.132a3.138 3.138 0 0 1 2.831 2.66a30.604 30.604 0 0 1 0 9.1a3.138 3.138 0 0 1-2.831 2.66l-1.51.131c-3.15.274-6.316.274-9.465 0l-1.51-.131a3.138 3.138 0 0 1-2.832-2.66a30.601 30.601 0 0 1 0-9.1a3.138 3.138 0 0 1 2.831-2.66l1.51-.132Zm9.335 1.495a53.147 53.147 0 0 0-9.206 0l-1.51.131A1.638 1.638 0 0 0 4.41 7.672a29.101 29.101 0 0 0-.311 5.17L7.97 8.97a.75.75 0 0 1 1.09.032l3.672 4.13l2.53-.844a.75.75 0 0 1 .796.21l3.519 3.91a29.101 29.101 0 0 0 .014-8.736a1.638 1.638 0 0 0-1.478-1.388l-1.51-.131Zm2.017 11.435l-3.349-3.721l-2.534.844a.75.75 0 0 1-.798-.213l-3.471-3.905l-4.244 4.243c.049.498.11.996.185 1.491a1.638 1.638 0 0 0 1.478 1.389l1.51.131c3.063.266 6.143.266 9.206 0l1.51-.131c.178-.016.35-.06.507-.128Z" clip-rule="evenodd"/></svg>
                                                </label>
                                                <div class="flex text-sm text-gray-600">
                                                    <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                                        <span>{{ $t('Provide examples of the variables or media in the header') }}</span>
                                                    </label>
                                                </div>
                                                <p class="text-xs text-gray-500">{{ $t('PNG or JPG files only') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="form.header.format === 'VIDEO'">
                                    <div class="flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                                        <input
                                            type="file"
                                            class="sr-only"
                                            accept=".mp4"
                                            ref="fileInput"
                                            id="file-upload2"
                                            @change="handleFileUpload($event)"
                                        />
                                        <div class="text-center">
                                            <div>
                                                <div v-if="form.header.format === 'VIDEO' && form.header.example" class="flex justify-center items-center">
                                                    <div class="flex justify-center items-center space-x-3 py-1 border bg-slate-100 rounded-lg mb-2 w-fit px-2">
                                                        <div>
                                                            <svg class="mx-auto h-6 w-6 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.5" d="M2 11.5c0-3.287 0-4.931.908-6.038a4 4 0 0 1 .554-.554C4.57 4 6.212 4 9.5 4c3.287 0 4.931 0 6.038.908a4 4 0 0 1 .554.554C17 6.57 17 8.212 17 11.5v1c0 3.287 0 4.931-.908 6.038a4.001 4.001 0 0 1-.554.554C14.43 20 12.788 20 9.5 20c-3.287 0-4.931 0-6.038-.908a4 4 0 0 1-.554-.554C2 17.43 2 15.788 2 12.5v-1Zm15-2l.658-.329c1.946-.973 2.92-1.46 3.63-1.02c.712.44.712 1.528.712 3.703v.292c0 2.176 0 3.263-.711 3.703c-.712.44-1.685-.047-3.63-1.02L17 14.5v-5Z"/></svg>
                                                        </div>
                                                        <div class="flex items-center space-x-2">
                                                            <span class="text-sm">{{ form.header.example.name }}</span>
                                                            <button @click="removeFile()">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <label v-else for="file-upload2">
                                                    <svg class="mx-auto h-12 w-12 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.5" d="M2 11.5c0-3.287 0-4.931.908-6.038a4 4 0 0 1 .554-.554C4.57 4 6.212 4 9.5 4c3.287 0 4.931 0 6.038.908a4 4 0 0 1 .554.554C17 6.57 17 8.212 17 11.5v1c0 3.287 0 4.931-.908 6.038a4.001 4.001 0 0 1-.554.554C14.43 20 12.788 20 9.5 20c-3.287 0-4.931 0-6.038-.908a4 4 0 0 1-.554-.554C2 17.43 2 15.788 2 12.5v-1Zm15-2l.658-.329c1.946-.973 2.92-1.46 3.63-1.02c.712.44.712 1.528.712 3.703v.292c0 2.176 0 3.263-.711 3.703c-.712.44-1.685-.047-3.63-1.02L17 14.5v-5Z"/></svg>
                                                </label>
                                                <div class="flex text-sm text-gray-600">
                                                    <label for="file-upload2" class="relative cursor-pointer bg-white rounded-md font-medium hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                                        <span>{{ $t('Provide examples of the variables or media in the header') }}</span>
                                                    </label>
                                                </div>
                                                <p class="text-xs text-gray-500">{{ $t('MP4 files only') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="form.header.format === 'DOCUMENT'">
                                    <div class="flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                                        <input
                                            type="file"
                                            class="sr-only"
                                            accept=".pdf"
                                            ref="fileInput"
                                            id="file-upload3"
                                            @change="handleFileUpload($event)"
                                        />
                                        <div class="text-center">
                                            <div>
                                                <div v-if="form.header.format === 'DOCUMENT' && form.header.example" class="flex justify-center items-center">
                                                    <div class="flex justify-center items-center space-x-3 py-1 border bg-slate-100 rounded-lg mb-2 w-fit px-2">
                                                        <div>
                                                            <svg class="mx-auto h-6 w-6 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M18.53 9L13 3.47a.75.75 0 0 0-.53-.22H8A2.75 2.75 0 0 0 5.25 6v12A2.75 2.75 0 0 0 8 20.75h8A2.75 2.75 0 0 0 18.75 18V9.5a.75.75 0 0 0-.22-.5Zm-5.28-3.19l2.94 2.94h-2.94ZM16 19.25H8A1.25 1.25 0 0 1 6.75 18V6A1.25 1.25 0 0 1 8 4.75h3.75V9.5a.76.76 0 0 0 .75.75h4.75V18A1.25 1.25 0 0 1 16 19.25Z"/><path fill="currentColor" d="M13.49 14.85a3.15 3.15 0 0 1-1.31-1.66a4.44 4.44 0 0 0 .19-2a.8.8 0 0 0-1.52-.19a5 5 0 0 0 .25 2.4A29 29 0 0 1 9.83 16c-.71.4-1.68 1-1.83 1.69c-.12.56.93 2 2.72-1.12a18.58 18.58 0 0 1 2.44-.72a4.72 4.72 0 0 0 2 .61a.82.82 0 0 0 .62-1.38c-.42-.43-1.67-.31-2.29-.23Zm-4.78 3a4.32 4.32 0 0 1 1.09-1.24c-.68 1.08-1.09 1.27-1.09 1.25Zm2.92-6.81c.26 0 .24 1.15.06 1.46a3.07 3.07 0 0 1-.06-1.45Zm-.87 4.88a14.76 14.76 0 0 0 .88-1.92a3.88 3.88 0 0 0 1.08 1.26a12.35 12.35 0 0 0-1.96.67Zm4.7-.18s-.18.22-1.33-.28c1.25-.08 1.46.21 1.33.29Z"/></svg>
                                                        </div>
                                                        <div class="flex items-center space-x-2">
                                                            <span class="text-sm">{{ form.header.example.name }}</span>
                                                            <button @click="removeFile()">
                                                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                                <label v-else for="file-upload3">
                                                    <svg class="mx-auto h-12 w-12 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M18.53 9L13 3.47a.75.75 0 0 0-.53-.22H8A2.75 2.75 0 0 0 5.25 6v12A2.75 2.75 0 0 0 8 20.75h8A2.75 2.75 0 0 0 18.75 18V9.5a.75.75 0 0 0-.22-.5Zm-5.28-3.19l2.94 2.94h-2.94ZM16 19.25H8A1.25 1.25 0 0 1 6.75 18V6A1.25 1.25 0 0 1 8 4.75h3.75V9.5a.76.76 0 0 0 .75.75h4.75V18A1.25 1.25 0 0 1 16 19.25Z"/><path fill="currentColor" d="M13.49 14.85a3.15 3.15 0 0 1-1.31-1.66a4.44 4.44 0 0 0 .19-2a.8.8 0 0 0-1.52-.19a5 5 0 0 0 .25 2.4A29 29 0 0 1 9.83 16c-.71.4-1.68 1-1.83 1.69c-.12.56.93 2 2.72-1.12a18.58 18.58 0 0 1 2.44-.72a4.72 4.72 0 0 0 2 .61a.82.82 0 0 0 .62-1.38c-.42-.43-1.67-.31-2.29-.23Zm-4.78 3a4.32 4.32 0 0 1 1.09-1.24c-.68 1.08-1.09 1.27-1.09 1.25Zm2.92-6.81c.26 0 .24 1.15.06 1.46a3.07 3.07 0 0 1-.06-1.45Zm-.87 4.88a14.76 14.76 0 0 0 .88-1.92a3.88 3.88 0 0 0 1.08 1.26a12.35 12.35 0 0 0-1.96.67Zm4.7-.18s-.18.22-1.33-.28c1.25-.08 1.46.21 1.33.29Z"/></svg>
                                                </label>
                                                <div class="flex text-sm text-gray-600">
                                                    <label for="file-upload3" class="relative cursor-pointer bg-white rounded-md font-medium hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                                        <span>{{ $t('Provide examples of the variables or media in the header') }}</span>
                                                    </label>
                                                </div>
                                                <p class="text-xs text-gray-500">{{ $t('PDF files only') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <h2 class="text-slate-600">{{ $t('Body') }} <span class="text-xs">({{ $t('Required') }})</span></h2>
                            <span class="text-slate-600 text-xs">{{ $t('Enter the text for your message in the language that you\'ve selected') }}</span>
                            <div class="mb-8">
                                <div>
                                    <BodyTextArea v-model="form.body.text" @updateExamples="updateBodyExamples"/>
                                </div>
                            </div>

                            <h2 class="text-slate-600">{{ $t('Footer description') }} <span class="text-xs">({{ $t('Optional') }})</span></h2>
                            <span class="text-slate-600 text-xs">{{ $t('Add a short line of text to the bottom of your message template') }}</span>
                            <div class="mb-8">
                                <div>
                                    <FormTextArea v-model="form.footer.text" @input="characterCount('footer')" :name="$t('Footer text')" :showLabel="false" :type="'text'" :textAreaRows="2" :class="'sm:col-span-6'"/>
                                    <span class="text-xs">{{ $t('Characters') }}: {{ footerCharacterCount }}/{{ footerCharacterLimit }}</span>
                                </div>
                            </div>

                            <h2 class="text-slate-600">{{ $t('Buttons') }} <span class="text-xs">({{ $t('Optional') }})</span></h2>
                            <span class="text-slate-600 text-xs">{{ $t('Create buttons that let customers respond to your message or take action') }}</span>
                            <div class="grid grid-cols-2 mt-3 mb-2">
                                <button @click="addButton('call')" class="flex items-center justify-center text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4 mr-2">
                                    <span>{{ $t('Call phone number (1)') }}</span>
                                </button>
                                <button @click="addButton('website')" class="flex items-center justify-center text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4">
                                    <span>{{ $t('Visit website (2)') }}</span>
                                </button>
                            </div>
                            <div class="grid grid-cols-2 mt-3 mb-2">
                                <button @click="addButton('offer')" class="flex items-center justify-center text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4 mr-2">
                                    <span>{{ $t('Copy offer code (1)') }}</span>
                                </button>
                                <button @click="addButton('custom')" class="flex items-center justify-center text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4">
                                    <span>{{ $t('Custom button (6)') }}</span>
                                </button>
                            </div>
                            <div v-if="form.buttons.length > 0" class="mt-3 mb-8">
                                <div v-for="(button, index) in form.buttons" :key="index" class="bg-[#f9f9fa] p-3 rounded-lg mb-3">
                                    <div class="flex items-center justify-between pb-1">
                                        <span class="text-sm">{{ $t(formatText(button.type)) }}</span>
                                        <button @click="removeButton(index)" class="bg-slate-200 hover:shadow rounded-full p-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                                        </button>
                                    </div>
                                    <div class="flex space-x-1 border-t pt-2">
                                        <FormInput v-model="button.text" :name="$t('Button text')" :type="'text'" :class="button.type === 'QUICK_REPLY' ? 'w-full' :'sm:col-span-2'" :labelClass="'mb-0'"/>
                                        <FormInput v-model="button.url" v-if="button.type === 'URL'" :name="$t('Website url')" :type="'url'" :class="'w-full'" :labelClass="'mb-0'"/>
                                        <FormInput v-model="button.country" v-if="button.type === 'PHONE_NUMBER'" :name="$t('Country')" :type="'text'" :class="'sm:col-span-2'" :labelClass="'mb-0'"/>
                                        <FormInput v-model="button.phone_number" v-if="button.type === 'PHONE_NUMBER'" :name="$t('Phone number')" :type="'text'" :class="'sm:col-span-2'" :labelClass="'mb-0'"/>
                                        <FormInput v-model="button.example" v-if="button.type === 'copy_code'" :name="$t('Sample code')" :type="'text'" :class="'w-full'" :labelClass="'mb-0'"/>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-if="form.category === 'AUTHENTICATION'">
                            <div class="mt-4 bg-gray-50 rounded-md p-3">
                                <h2 class="text-slate-600">{{ $t('Code delivery setup') }}</h2>
                                <span class="text-slate-600 text-xs">{{ $t('Choose how customers send the code from WhatsApp to your app.') }}</span>

                                <div class="mt-4">
                                    <div v-for="option in codeDeliveryOptions" :key="option.value" class="relative flex mb-2">
                                    <div class="flex h-6 items-center mr-2">
                                        <label class="flex items-center cursor-pointer">
                                        <input
                                            type="radio"
                                            class="hidden"
                                            :checked="form.authentication_button.otp_type === option.value"
                                            @change="form.authentication_button.otp_type = option.value;"
                                        />
                                        <!-- Outer Circle -->
                                        <div
                                            class="w-5 h-5 rounded-full border-2 border-gray-400 flex items-center justify-center transition"
                                        >
                                            <!-- Inner Dot -->
                                            <div
                                            class="w-2.5 h-2.5 rounded-full bg-primary transition"
                                            v-if="form.authentication_button.otp_type === option.value"
                                            ></div>
                                        </div>
                                        </label>
                                    </div>
                                    <div class="text-sm leading-6 cursor-pointer" @click="form.authentication_button.otp_type = option.value">
                                        <label class="text-gray-900 cursor-pointer">{{ $t(option.label) }}</label>
                                        <div class="text-[11px] leading-tight text-gray-500">
                                        {{ $t(option.description) }}
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="form.authentication_button.otp_type != 'copy_code'" class="mt-6 bg-gray-50 rounded-md p-3">
                                <h2 class="text-slate-600">{{ $t('App setup') }}</h2>
                                <p class="text-slate-600 leading-tight text-xs mb-4">{{ $t('You can add up to 5 apps.') }}</p>

                                <div v-for="(item, index) in form.authentication_button.supported_apps" class="flex gap-x-2 rounded-lg mb-3">
                                    <FormInput v-model="form.authentication_button.supported_apps[index].package_name" :name="$t('Package name')" :type="'text'" :class="'w-3/5'" :labelClass="'mb-0'"/>
                                    <FormInput v-model="form.authentication_button.supported_apps[index].signature_hash" :name="$t('App signature hash')" :type="'text'" :class="'w-2/5'" :labelClass="'mb-0'"/>
                                    <span v-if="form.authentication_button.supported_apps.length > 1" @click="removeSupportedApp(index)" class="mt-7 cursor-pointer bg-slate-100 rounded-full w-6 h-6 flex items-center hover:shadow-md">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="#000" d="M7.404 7.404a.5.5 0 0 1 .707 0L12 11.293l3.89-3.89a.5.5 0 1 1 .706.708L12.707 12l3.89 3.89a.5.5 0 1 1-.708.706L12 12.707l-3.89 3.89a.5.5 0 1 1-.706-.708L11.293 12l-3.89-3.89a.5.5 0 0 1 0-.706"/></svg>
                                    </span>
                                </div>
                                
                                <div class="bg-slate-200 text-[11px] p-3 rounded-md leading-tight mb-4 text-gray-500">
                                    {{ $t('It is recommended to only include different builds of the same app (which would not coexist on a production user\'s phone), rather than entirely different apps.') }}
                                </div>

                                <button v-if="form.authentication_button.supported_apps.length < 5" @click="addSupportedApp()" type="button" class="bg-white text-sm rounded-md shadow-sm border px-3 py-2">{{ $t('Add another app') }}</button>
                            </div>
                            <div class="mt-6 bg-gray-50 rounded-md p-3">
                                <h2 class="text-slate-600">{{ $t('Content') }}</h2>
                                <p class="text-slate-600 leading-tight text-xs ">{{ $t('Content for authentication message templates can\'t be edited. You can add additional content from the options below.') }}</p>

                                <div class="mt-4">
                                    <div class="relative flex mb-2">
                                        <div class="flex h-6 items-center mr-2">
                                            <input 
                                                v-model="form.body.add_security_recommendation"
                                                type="checkbox" 
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                            >
                                        </div>
                                        <div class="text-sm leading-6 cursor-pointer" @click="form.body.add_security_recommendation = !form.body.add_security_recommendation">
                                            <label :for="name" class="text-gray-900 cursor-pointer">{{ $t('Add security recommendation') }}</label>
                                        </div>
                                    </div>
                                    <div class="relative flex mb-2">
                                        <div class="flex h-6 items-center mr-2">
                                            <input 
                                                v-model="form.code_expiration"
                                                type="checkbox" 
                                                class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-600"
                                            >
                                        </div>
                                        <div class="text-sm leading-tight cursor-pointer" @click="form.code_expiration = !form.code_expiration">
                                            <label :for="name" class="text-gray-900 cursor-pointer">{{ $t('Add expiry time for the code') }}</label>
                                            <div class="text-[11px] text-slate-600">{{ $t('After the code has expired, the auto-fill button will be disabled.') }}</div>
                                        </div>
                                    </div>
                                    <div v-if="form.code_expiration" class="relative flex mb-2">
                                        <div class="bg-white border rounded-md w-full p-2">
                                            <h2 class="mb-2">{{ $t('Expires In') }}</h2>
                                            <div class="w-1/3 flex items-center gap-x-2">
                                                <input type="number" v-model="form.footer.code_expiration_minutes" step="any"
                                                class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
                                                :class="error ? 'ring-[#b91c1c]' : 'ring-gray-300'"/>
                                                <span class="text-sm">{{ $t('Minutes') }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 bg-gray-50 rounded-md p-3">
                                <h2 class="text-slate-600 mb-1">{{ $t('Buttons') }}</h2>
                                <p class="text-slate-600 leading-tight text-xs mb-4">{{ $t('You can customise the button text for both auto-fill and copy code. Even when zero-tap is turned on, buttons are still needed for the backup code delivery method.') }}</p>

                                <div v-if="form.authentication_button.otp_type == 'copy_code'">
                                    <FormInput v-model="form.authentication_button.text" :name="$t('Copy code')" :type="'text'" :class="''" :labelClass="'mb-0'"/>
                                </div>
                                <div v-else class="flex gap-x-2">
                                    <FormInput v-model="form.authentication_button.autofill_text" :name="$t('Auto-fill')" :type="'text'" :class="'w-1/2'" :labelClass="'mb-0'"/>
                                    <FormInput v-model="form.authentication_button.text" :name="$t('Copy code')" :type="'text'" :class="'w-1/2'" :labelClass="'mb-0'"/>
                                </div>
                            </div>
                        </div>

                        <div v-if="form.category == 'UTILITY' || form.category == 'AUTHENTICATION'" class="mt-6 bg-gray-50 rounded-md p-3">
                            <h2 class="text-slate-600 mb-1">{{ $t('Message validity period') }}</h2>
                            <p class="text-slate-600 leading-tight text-xs ">{{ $t('It\'s recommended to set a custom validity period that your authentication message must be delivered by before it expires. If a message is not delivered within this time frame, you will not be charged and your customer will not see the message.') }}</p>

                            <div class="mt-4">
                                <h2 class="text-slate-600 mb-1">{{ $t('Set custom validity period for your message') }}</h2>
                                <div class="flex w-full mb-4">
                                    <p class="w-3/4 text-slate-600 text-[11px]">{{ $t('If you don\'t set a custom validity period, the standard 10 minutes WhatsApp message validity period will be applied.') }}</p>
                                    <div class="w-1/4">
                                        <FormToggleSwitch v-model="form.customize_ttl" />
                                    </div>
                                </div>
                                <FormSelect v-if="form.customize_ttl" v-model="form.message_send_ttl_seconds" :options="form.category == 'UTILITY' ? utilityTTLOptions : authTTLOptions" :name="$t('Validity period')" :class="'sm:col-span-3'" :placeholder="$t('Select validity period')"/>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="md:w-[50%] py-20 px-4 md:px-20 overflow-y-auto" style="background-image: url('/images/whatsapp-bg-02.png');">
                    <div class="mr-auto rounded-lg rounded-tl-none my-1 p-1 text-sm bg-white flex flex-col relative speech-bubble-left w-[25em]">
                        <div v-if="form.header.format != 'TEXT'" class="mb-4 bg-[#ccd0d5] flex justify-center py-8 rounded">
                            <img v-if="form.header.format === 'IMAGE'" :src="'/images/image-placeholder.png'">
                            <img v-if="form.header.format === 'VIDEO'" :src="'/images/video-placeholder.png'">
                            <img v-if="form.header.format === 'DOCUMENT'" :src="'/images/document-placeholder.png'">
                        </div>
                        <h2 v-else class="text-gray-700 text-sm mb-1 px-2 normal-case whitespace-pre-wrap">{{ form.header.text }}</h2>
                        <p v-if="form.category != 'AUTHENTICATION'" class="px-2 normal-case whitespace-pre-wrap" v-html="formattedMessage"></p>
                        <p v-else class="px-2 normal-case whitespace-pre-wrap" v-html="form.body.add_security_recommendation === true ? '{{1}} is your verification code. For your security, do not share this code.' : '{{1}} is your verification code.'"></p>
                        <div class="text-[#8c8c8c] mt-1 px-2">
                            <span v-if="form.category != 'AUTHENTICATION'" class="text-[13px]">{{ form.footer.text }}</span>
                            <span v-else-if="form.category === 'AUTHENTICATION' && form.code_expiration == true">{{ $t('This code expires in') }} {{ form.footer.code_expiration_minutes }} {{ 'minutes' }}</span>
                            <span class="text-right text-xs leading-none float-right" :class="form.footer.text ? 'mt-2' : ''">9:15</span>
                        </div>
                    </div>
                    <div v-if="form.buttons.length > 0" class="mr-auto text-sm text-[#00a5f4] flex flex-col relative max-w-[25em]">
                        <div v-for="(item, index) in form.buttons" :key="index" class="flex justify-center items-center space-x-2 rounded-lg bg-white h-10 my-[0.1em]">
                            <span>
                                <svg v-if="item.type === 'copy_code'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M19 21H8V7h11m0-2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2m-3-4H4a2 2 0 0 0-2 2v14h2V3h12V1Z"/></svg>
                                <svg v-else-if="item.type === 'PHONE_NUMBER'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><g fill="none"><path fill="currentColor" d="M20 16v4c-2.758 0-5.07-.495-7-1.325c-3.841-1.652-6.176-4.63-7.5-7.675C4.4 8.472 4 5.898 4 4h4l1 4l-3.5 3c1.324 3.045 3.659 6.023 7.5 7.675L16 15l4 1z"/><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 18.675c1.93.83 4.242 1.325 7 1.325v-4l-4-1l-3 3.675zm0 0C9.159 17.023 6.824 14.045 5.5 11m0 0C4.4 8.472 4 5.898 4 4h4l1 4l-3.5 3z"/></g></svg>
                                <img v-else-if="item.type === 'URL'" :src="'/images/icons/link.png'" class="h-4">
                                <img v-else :src="'/images/icons/reply.png'" class="h-4">
                            </span>
                            <span>{{ item.text }}</span>
                        </div>
                    </div>
                    <div v-if="form.category == 'AUTHENTICATION' && form.authentication_button.otp_type != 'zero_tap'" class="mr-auto text-sm text-[#00a5f4] flex flex-col relative max-w-[25em]">
                        <div class="flex justify-center items-center space-x-2 rounded-lg bg-white h-10 my-[0.1em]">
                            <span v-if="form.authentication_button.otp_type == 'copy_code'" class="flex gap-x-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M19 21H8V7h11m0-2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2m-3-4H4a2 2 0 0 0-2 2v14h2V3h12V1Z"/></svg>
                                {{ form.authentication_button.text }}
                            </span>
                            <span v-if="form.authentication_button.otp_type == 'one_tap'">{{ form.authentication_button.autofill_text }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <Modal :label="''" :isOpen=isModalOpen :showHeader="false">
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <div class="text-center">
                    <div v-if="error != null" class="bg-[#fae6e6] text-[darkred] rounded text-sm p-2 mb-4">
                        <div>{{ $t('Error') }}: </div>
                        <div>{{ error }}</div>
                        <button type="button" @click="closeModal" class="mt-4 inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">Close</button>
                    </div>
                    <div v-else>
                        <h2 class="text-xl capitalize mt-6">{{ $t('Your template is being uploaded!') }}</h2>
                        <div class="flex justify-center mt-4">
                            <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-dasharray="15" stroke-dashoffset="15" stroke-linecap="round" stroke-width="2" d="M12 3C16.9706 3 21 7.02944 21 12"><animate fill="freeze" attributeName="stroke-dashoffset" dur="0.3s" values="15;0"/><animateTransform attributeName="transform" dur="1.5s" repeatCount="indefinite" type="rotate" values="0 12 12;360 12 12"/></path></svg>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>

        
    </AppLayout>
</template>
<script setup>
    import axios from "axios";
    import AppLayout from "./../Layout/App.vue";
    import Dropdown from '@/Components/Dropdown.vue';
    import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
    import DropdownItem from '@/Components/DropdownItem.vue';
    import FormCheckbox from '@/Components/FormCheckbox.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';
    import BodyTextArea from '@/Components/Template/BodyTextArea.vue';
    import HeaderTextArea from '@/Components/Template/HeaderTextArea.vue';
    import { ref, computed, watch } from 'vue';
    import { Link } from "@inertiajs/vue3";
    import Modal from '@/Components/Modal.vue';
    import { toast } from 'vue3-toastify';
    import 'vue3-toastify/dist/index.css';
    import { router } from '@inertiajs/vue3';

    const props = defineProps(['languages', 'settings']);
    const headerCharacterLimit = ref('60');
    const headerCharacterCount = ref('0');
    const bodyCharacterLimit = ref('1098');
    const bodyCharacterCount = ref('0');
    const footerCharacterLimit = ref('60');
    const footerCharacterCount = ref('0');
    const isLoading = ref(false);
    const imageUrl = ref(null);
    const isModalOpen = ref(false);
    const error = ref(null);
    const bodyExamples = ref([]);
    const form = ref({
        'name' : null,
        'category' : 'UTILITY',
        'language' : null,
        'message_send_ttl_seconds' : null,
        'customize_ttl': false,
        'header' : {
            'format' : 'TEXT',
            'text' : null,
            'example' : [],
        },
        'body' : {
            'text' : null,
            'variables' : null,
            'add_security_recommendation' : false,
            'example' : []
        },
        'footer' : {
            'text' : null,
            "code_expiration_minutes": 5,
        },
        'code_expiration' : false,
        'set_custom_validity_period' : false,
        'buttons' : [],
        'authentication_button' : {
            'type' : 'OTP',
            "otp_type": "zero_tap",
            'text' : "Copy Code",
            "autofill_text": "Autofill",
            "zero_tap_terms_accepted": true,
            "supported_apps": [
                {
                    "package_name": null,
                    "signature_hash": null,
                },
            ]
        }
    });
    const config = ref(props.settings.metadata);
    const settings = ref(config.value ? JSON.parse(config.value) : null);

    const formattedMessage = ref('');

    const formatContent = (text) => {
        const boldRegex = /\*(.*?)\*/g;
        const italicRegex = /_(.*?)_/g;
        const strikethroughRegex = /~(.*?)~/g;
        const monospaceRegex = /```(.*?)```/g;

        return text
            .replace(boldRegex, "<b>$1</b>")
            .replace(italicRegex, "<i>$1</i>")
            .replace(strikethroughRegex, "<del>$1</del>")
            .replace(monospaceRegex, "<code>$1</code>");
    };

    watch(() => form.value.body.text, (newValue) => {
        formattedMessage.value = newValue ? formatContent(newValue) : null;
    });

    const headerType = ref('text');
    const langOptions = ref(props.languages);
    const categoryOptions = ref([
        { value: 'UTILITY', label: 'Utility' },
        { value: 'MARKETING', label: 'Marketing' },
        { value: 'AUTHENTICATION', label: 'Authentication' },
    ])

    const previousExamples = ref({});

    const changeHeaderType = (value) => {
        const currentType = form.value.header.format;

        if (
            !(currentType in previousExamples.value) || 
            JSON.stringify(previousExamples.value[currentType]) !== JSON.stringify(form.value.header.example)
        ) {
            // Store the example if it has changed
            previousExamples.value[currentType] = form.value.header.example;
        }

        form.value.header.format = value;
    
        if (previousExamples.value[value] !== undefined) {
            // Restore the example if switching back
            form.value.header.example = previousExamples.value[value];
        } else {
            // Set it to null explicitly to avoid fallback issues
            form.value.header.example = null;
        }
    }

    const handleNameInput = (event) => {
        const value = event.target.value.toLowerCase();
        form.value.name = value.replace(/[^a-zA-Z0-9_]/g, '');
    };

    const handleFileUpload = (event) => {
        const file = event.target.files[0];
        const reader = new FileReader();
        reader.onload = (e) => {
            imageUrl.value = e.target.result;
            form.value.header.example = file; // Update header example with the uploaded image
        };
        reader.readAsDataURL(file);
    };

    const removeFile = () => {
        form.value.header.example = '';
    };

    const addUnderscore = (event) => {
        event.preventDefault();
        form.value.name += '_';
    };

    const characterCount = (type) => {
        let limit = 0;
        let count = 0;
        switch (type) {
            case 'header':
            limit = headerCharacterLimit.value;
            count = form.value.header.text.length;
            if (count <= limit) {
                headerCharacterCount.value = count;
            } else {
                form.value.header.text = form.value.header.text.slice(0, limit);
                headerCharacterCount.value = limit;
            }
            break;

            case 'body':
            limit = bodyCharacterLimit.value;
            count = form.value.body.text.length;
            if (count <= limit) {
                bodyCharacterCount.value = count;
            } else {
                form.value.body.text = form.value.body.text.slice(0, limit);
                bodyCharacterCount.value = limit;
            }
            break;

            case 'footer':
            limit = footerCharacterLimit.value;
            count = form.value.footer.text.length;
            if (count <= limit) {
                footerCharacterCount.value = count;
            } else {
                form.value.footer.text = form.value.footer.text.slice(0, limit);
                footerCharacterCount.value = limit;
            }
            break;
        }
    };

    const addButton = ($type) => {
        if($type === 'call'){
            const buttonsCount = form.value.buttons.filter(button => button.type === 'PHONE_NUMBER').length;

            if(buttonsCount < 1){
                form.value.buttons.push({
                    'type' : 'PHONE_NUMBER',
                    'country' : null,
                    'text' : null,
                    'phone_number' : null,
                });
            }
        } else if($type === 'website'){
            const buttonsCount = form.value.buttons.filter(button => button.type === 'URL').length;

            if(buttonsCount < 2){
                form.value.buttons.push({
                    'type' : 'URL',
                    'text' : null,
                    'url' : null,
                });
            }
        } else if($type === 'custom'){
            const buttonsCount = form.value.buttons.filter(button => button.type === 'QUICK_REPLY').length;

            if(buttonsCount < 6){
                form.value.buttons.push({
                    'type' : 'QUICK_REPLY',
                    'text' : null,
                });
            }
        } else if($type === 'offer'){
            const buttonsCount = form.value.buttons.filter(button => button.type === 'copy_code').length;

            if(buttonsCount < 1){
                form.value.buttons.push({
                    'type' : 'copy_code',
                    'example' : null,
                });
            }
        }
    }

    const addSupportedApp = () => {
        const appsCount = form.value.authentication_button.supported_apps.length;

        if(appsCount < 5){
            form.value.authentication_button.supported_apps.push({
                'package_name' : null,
                'signature_hash' : null,
            });
        }
    }

    const removeSupportedApp = (index) => {
        form.value.authentication_button.supported_apps.splice(index, 1);
    }

    const removeButton = (index) => {
        if (index >= 0 && index < form.value.buttons.length) {
            form.value.buttons.splice(index, 1);
        }
    };

    const isFormValid = computed(() => {
        if (
            form.value.name === null ||
            form.value.name.trim() === "" ||
            form.value.language === null ||
            form.value.language.trim() === "" ||
            form.value.category === null ||
            form.value.category.trim() === "" ||
            form.value.buttons.some(button => {
                return (
                    (button.name === null || button.name === '') ||
                    (button.type === null || button.type === '') ||
                    (button.country === null || button.country === '') ||
                    (button.text === null || button.text === '') ||
                    (button.phone_number === null || button.phone_number === '')
                );
            })
        ) {
            return false;
        } 
        
        // Exclude body.text validation if category is 'authentication'
        if (form.value.category !== 'AUTHENTICATION') {
            if (form.value.body.text === null || form.value.body.text.trim() === "") {
                return false;
            }

            if (form.value.body.example.length > 0) {
                const allKeysHaveValues = Object.keys(form.value.body.example).every(key => {
                    const value = form.value.body.example[key];
                    return value !== undefined && value !== null && value !== '';
                });

                if (!allKeysHaveValues) {
                    return false;
                }
            }

            // Exclude header validation if category is 'authentication'
            if (
                form.value.header.example.length > 0 &&
                !Object.keys(form.value.header.example).every(key => {
                    const value = form.value.header.example[key];
                    return value !== undefined && value !== null && value !== '';
                })
            ) {
                return false;
            }
        }
        
        // Additional validation for authentication category
        if (form.value.category === 'AUTHENTICATION') {
            const authButton = form.value.authentication_button;

            // Ensure otp_type has a value
            if (!authButton.otp_type || authButton.otp_type.trim() === "") {
                return false;
            }

            // Ensure text value based on otp_type
            if (
                authButton.otp_type === 'copy_code' ||
                authButton.otp_type === 'one_tap' ||
                authButton.otp_type === 'zero_tap'
            ) {
                if (!authButton.text || authButton.text.trim() === "") {
                    return false;
                }
            }

            // If otp_type is one_tap or zero_tap, autofill_text must also have a value
            if (
                (authButton.otp_type === 'one_tap' || authButton.otp_type === 'zero_tap') &&
                (!authButton.autofill_text || authButton.autofill_text.trim() === "")
            ) {
                return false;
            }

            // Ensure supported_apps is required **only for one_tap and zero_tap**
            if (['one_tap', 'zero_tap'].includes(authButton.otp_type)) {
                if (
                    !Array.isArray(authButton.supported_apps) ||
                    authButton.supported_apps.length === 0
                ) {
                    return false;
                }

                // Validate each supported_app entry
                const allAppsValid = authButton.supported_apps.every(app => {
                    return (
                        app.package_name && app.package_name.trim() !== "" &&
                        app.signature_hash && app.signature_hash.trim() !== ""
                    );
                });

                if (!allAppsValid) {
                    return false;
                }
            }
        }

        return true;
    });

    const updateBodyExamples = (value) => {
        form.value.body.example = value;
    }

    const updateHeaderExamples = (value) => {
        form.value.header.example = value;
    }

    const submitForm = () => {
        isLoading.value = true;
        isModalOpen.value = true;
        axios.post('/templates/create', form.value, {
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        })
        .then(response => {
            if(response.data.success === false){
                isLoading.value = false;
                error.value = response.data.data.error.message;
            } else {
                router.visit('/templates', {
                    method: 'get',
                });
            }
        })
        .catch(error => {
            // Handle any errors that occur during the request
            //console.error('An error occurred:', error);
        });
    }

    const codeDeliveryOptions = [
        {
            value: "zero_tap",
            label: "Zero-tap auto-fill",
            description:
            "This is recommended as the easiest option for your customers. Zero-tap will automatically send code without requiring your customer to tap a button. An auto-fill or copy code message will be sent if zero-tap and auto-fill aren’t possible.",
        },
        {
            value: "one_tap",
            label: "One-tap auto-fill",
            description:
            "The code sends to your app when customers tap the button. A copy code message will be sent if auto-fill isn’t possible.",
        },
        {
            value: "copy_code",
            label: "Copy code",
            description:
            "Basic authentication with quick setup. Your customers copy and paste the code into your app.",
        },
    ];

    const authTTLOptions = ref([
        { value: '30', label: '30 seconds' },
        { value: '60', label: '1 minute' },
        { value: '120', label: '2 minutes' },
        { value: '180', label: '3 minutes' },
        { value: '300', label: '5 minutes' },
        { value: '600', label: '10 minutes' },
        { value: '800', label: '15 minutes' },
    ]);

    const utilityTTLOptions = ref([
        { value: '30', label: '30 seconds' },
        { value: '60', label: '1 minute' },
        { value: '120', label: '2 minutes' },
        { value: '300', label: '5 minutes' },
        { value: '600', label: '10 minutes' },
        { value: '800', label: '15 minutes' },
        { value: '1600', label: '30 minutes' },
        { value: '3200', label: '1 hour' },
        { value: '9600', label: '3 hours' },
        { value: '19200', label: '6 hours' },
        { value: '38400', label: '12 hours' },
    ]);

    function formatText(text) {
        return text
            .toLowerCase()
            .replace(/_/g, ' ')
            .replace(/^\w/, (c) => c.toUpperCase());
    }

    const closeModal = () => {
        isModalOpen.value = false; 

        setTimeout(() => {
            error.value = null;
        }, 500);
    }

    watch(form, () => {
        isFormValid.value;
    });
</script>