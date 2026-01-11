<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { MoreHorizontalIcon } from 'lucide-vue-next'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'

import { Menubar, MenubarMenu, MenubarTrigger, MenubarContent, MenubarItem } from '@modules/FlowBuilder/Pages/User/Components/ui/menubar'

import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import type { NodeProps } from '@vue-flow/core'
import FlowMedia from '@modules/FlowBuilder/Pages/User/Components/vue-flow/FlowMedia.vue'

const props = defineProps<NodeProps>()
const title = ref(props.data.title)
const uuid = ref(props.data.uuid)
const textAreaRef = ref(null);
const input1 = ref(props.data.metadata?.fields["mediaType"] || '')
const textInput = ref(props.data.metadata?.fields["caption"] || '')
const media = ref(props.data.metadata?.fields["media"] || [])
const characterLimit = ref('1098');
const characterCount = ref('0');
const isEditTitle = ref(false)
const options = ref([
  { value: 'image', label: 'Image' },
  { value: 'video', label: 'Video' },
  { value: 'audio', label: 'Audio' },
  { value: 'document', label: 'Document' },
]);
const node = useNode()
const { removeNodes, nodes, addNodes, removeEdges, edges } = useVueFlow()

watch(input1, (newValue) => {
  node.node.data.metadata.fields = {
    ...node.node.data.metadata.fields,
    type: 'media',
    mediaType: newValue,
    caption: textInput,
    media: media
  }
})

watch(textInput, (newValue) => {
  node.node.data.metadata.fields = {
    ...node.node.data.metadata.fields,
    type: 'media',
    mediaType: input1,
    caption: newValue,
    media: media
  }
})

watch(media, (newValue) => {
  node.node.data.metadata.fields = {
    ...node.node.data.metadata.fields,
    type: 'media',
    mediaType: input1,
    caption: textInput,
    media: newValue
  }
})

function handleClickDeleteBtn() {
  // Find and remove all edges connected to the node
  edges.value
    .filter(edge => edge.source === node.id || edge.target === node.id)
    .forEach(edge => removeEdges(edge.id));

  removeNodes(node.id)
}

function handleClickDuplicateBtn() {
  const { type, position, label, data } = node.node
  const newNode = {
    id: (nodes.value.length + 1).toString(),
    type,
    position: {
      x: position.x + 100,
      y: position.y + 100
    },
    label,
    data
  }
  addNodes(newNode)
}

const countCharacters = (type) => {
    let limit = parseInt(characterLimit.value);
    let count = parseInt(textInput.value.length);

    if (count <= limit) {
        characterCount.value = count;
    } else {
        textInput.value = textInput.value.slice(0, limit);
        characterCount.value = limit;
    }
};

const addVariable = (variable) => {
  const textarea = textAreaRef.value;

  // Check if textarea is defined and get the cursor position
  if (textarea && textInput.value.indexOf(variable) === -1) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;

    // Insert variable at the cursor position
    textInput.value = textInput.value.slice(0, start) + variable + textInput.value.slice(end);

    // Update cursor position after the inserted variable
    setTimeout(() => {
      textarea.setSelectionRange(start + variable.length, start + variable.length);
      textarea.focus();
    }, 0);
  }
}

const updateText = () => {
  countCharacters();
}

const format = (type) => {
    const textarea = textAreaRef.value;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const selectedText = textInput.value.slice(start, end);
    let newText = '';

    if(type == 'bold'){
        newText = textInput.value.slice(0, start) + '*' + selectedText + '*' + textInput.value.slice(end);
    } else if(type == 'italic'){
        newText = textInput.value.slice(0, start) + '_' + selectedText + '_' + textInput.value.slice(end);
    } else if(type == 'strike-through'){
        newText = textInput.value.slice(0, start) + '~' + selectedText + '~' + textInput.value.slice(end);
    } else if(type == 'monospace'){
        newText = textInput.value.slice(0, start) + '```' + selectedText + '```' + textInput.value.slice(end);
    }

    textInput.value = newText;
    countCharacters();

    // Set selection to highlight the content between the asterisks
    setTimeout(() => {
        if(type == 'monospace'){
            textarea.setSelectionRange(start + 3, end + 3);
        } else {
            textarea.setSelectionRange(start + 1, end + 1);
        }
        textarea.focus();
    }, 0);
};

onMounted(() => {
  countCharacters()
})
</script>

<template>
  <div class="rounded-sm border border-gray-200 bg-white p-3 shadow-md">
    <Handle type="target" :position="Position.Left" />
    <div class="flex flex-col gap-y-2">
      <div class="flex justify-between items-center">
        <div class="flex gap-x-2">
          <img src="~@/assets/images/icon_LLM.png" class="mt-1 h-4 w-4" alt="LLM icon" />
          <div class="flex flex-col gap-y-1">
            <FormInput v-if="isEditTitle" v-model="title" :name="''" :type="'text'" :class="'col-span-4'" @blur="() => (isEditTitle = false)"/>
            <h3 class="text-base capitalize" v-else>{{ title }}</h3>
          </div>
        </div>

        <Menubar class="border-none">
          <menubar-menu>
            <menubar-trigger>
              <more-horizontal-icon />
            </menubar-trigger>
            <menubar-content>
              <menubar-item @click="handleClickDuplicateBtn"> Duplicated </menubar-item>
              <menubar-item @click="handleClickDeleteBtn"> Delete </menubar-item>
              <menubar-item @click="isEditTitle = true"> Rename </menubar-item>
            </menubar-content>
          </menubar-menu>
        </Menubar>
      </div>

      <div v-if="input1 == '' || media.length == 0" class="flex items-center gap-x-2 bg-red-500 text-white rounded-md px-2 py-2">
        <span>
          <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><g fill="none"><path d="m12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035q-.016-.005-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427q-.004-.016-.017-.018m.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093q.019.005.029-.008l.004-.014l-.034-.614q-.005-.018-.02-.022m-.715.002a.02.02 0 0 0-.027.006l-.006.014l-.034.614q.001.018.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01z"/><path fill="currentColor" d="m13.299 3.148l8.634 14.954a1.5 1.5 0 0 1-1.299 2.25H3.366a1.5 1.5 0 0 1-1.299-2.25l8.634-14.954c.577-1 2.02-1 2.598 0M12 15a1 1 0 1 0 0 2a1 1 0 0 0 0-2m0-7a1 1 0 0 0-.993.883L11 9v4a1 1 0 0 0 1.993.117L13 13V9a1 1 0 0 0-1-1"/></g></svg>
        </span>
        <span class="text-sm">Please fill all the required fields</span>
      </div>

      <span class="text-sm text-gray-500">Respond with an image, video, audio or document.</span>

        <div class="rounded-md bg-muted">
        <div class="mb-4">
          <label class="text-sm mb-2"><span class="text-red-500">*</span>  Media Type</label>
          <FormSelect v-model="input1" :name="''" :type="'text'" :optionClassName="'h-32'"  :options="options" :class="'col-span-4'"/>
        </div>
        <div>
          <label class="text-sm mb-2">Caption (Optional)</label>
          <textarea 
              ref="textAreaRef"
              class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6 ring-gray-300"
              v-model="textInput"
              @input="updateText"
              :rows="'1'"
              placeholder="Enter text"
              >
          </textarea>
        </div>
        <div class="flex items-center justify-between mt-2 mb-2 space-x-4">
            <span class="text-xs">{{ $t('Characters') }}: {{ characterCount + '/' + characterLimit }}</span>
            <div class="flex items-center space-x-3">
                <button @click="format('bold')" class="hover:bg-slate-100 rounded p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3h8c1.06 0 2.078.474 2.828 1.318C16.578 5.162 17 6.307 17 7.5c0 1.193-.421 2.338-1.172 3.182C15.078 11.526 14.061 12 13 12H5zm0 9h10.039a4.44 4.44 0 0 1 3.154 1.318A4.52 4.52 0 0 1 19.5 16.5a4.52 4.52 0 0 1-1.307 3.182A4.442 4.442 0 0 1 15.038 21H5z"/></svg>
                </button>
                <button @click="format('italic')" class="hover:bg-slate-100 rounded p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M10 4.75a.75.75 0 0 1 .75-.75h8.5a.75.75 0 0 1 0 1.5h-3.514l-5.828 13h3.342a.75.75 0 0 1 0 1.5h-8.5a.75.75 0 0 1 0-1.5h3.514l5.828-13H20.75a.75.75 0 0 1-.75-.75Z"/></svg>
                </button>
                <button @click="format('strike-through')" class="hover:bg-slate-100 rounded p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="m16.533 12.5l.054.043c.93.75 1.538 1.77 1.538 3.066a4.13 4.13 0 0 1-1.479 3.177c-1.058.904-2.679 1.464-4.974 1.464c-2.35 0-4.252-.837-5.318-1.865a.75.75 0 1 1 1.042-1.08c.747.722 2.258 1.445 4.276 1.445c2.065 0 3.296-.504 3.999-1.105a2.63 2.63 0 0 0 .954-2.036c0-.764-.337-1.38-.979-1.898c-.649-.523-1.598-.931-2.76-1.211H3.75a.75.75 0 0 1 0-1.5h26.5a.75.75 0 0 1 0 1.5ZM12.36 5C9.37 5 8.105 6.613 8.105 7.848c0 .411.072.744.193 1.02a.75.75 0 0 1-1.373.603a3.988 3.988 0 0 1-.32-1.623c0-2.363 2.271-4.348 5.755-4.348c1.931 0 3.722.794 4.814 1.5a.75.75 0 1 1-.814 1.26c-.94-.607-2.448-1.26-4-1.26Z"/></svg>
                </button>
                <button @click="format('monospace')" class="hover:bg-slate-100 rounded p-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 6L10 18.5m-3.5-10L3 12l3.5 3.5m11-7L21 12l-3.5 3.5"/></svg>
                </button>
                <Menubar class="border-none">
                <menubar-menu>
                  <menubar-trigger>
                    <button type="button" class="hover:bg-slate-100 bg-slate-100 rounded p-1 text-sm">{{ $t('Add variable') }}</button>
                  </menubar-trigger>
                  <menubar-content>
                    <menubar-item @click="addVariable('{first_name}')"> {first_name} </menubar-item>
                    <menubar-item @click="addVariable('{last_name}')"> {last_name} </menubar-item>
                    <menubar-item @click="addVariable('{full_name}')"> {full_name} </menubar-item>
                    <menubar-item @click="addVariable('{phone_number}')"> {phone_number} </menubar-item>
                    <menubar-item @click="addVariable('{email}')"> {email} </menubar-item>
                    <menubar-item @click="addVariable('{city}')"> {city} </menubar-item>
                    <menubar-item @click="addVariable('{country}')"> {country} </menubar-item>
                  </menubar-content>
                </menubar-menu>
              </Menubar>
            </div>
        </div>
        <FlowMedia v-model="media" :type="input1" :uuid="uuid" :nodeId="node.id"/>
      </div>
    </div>
    <Handle type="source" :position="Position.Right" />
  </div>
</template>
