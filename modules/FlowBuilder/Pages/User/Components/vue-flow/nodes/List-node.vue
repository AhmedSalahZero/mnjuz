<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { MoreHorizontalIcon } from 'lucide-vue-next'
import { Handle, Position, useVueFlow, useNode } from '@vue-flow/core'

import { Menubar, MenubarMenu, MenubarTrigger, MenubarContent, MenubarItem } from '@modules/FlowBuilder/Pages/User/Components/ui/menubar'

import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextArea from '@/Components/FormTextArea.vue';
import FlowMedia from '@modules/FlowBuilder/Pages/User/Components/vue-flow/FlowMedia.vue'

import type { NodeProps } from '@vue-flow/core'

const props = defineProps<NodeProps>()

const title = ref(props.data.title)
const uuid = ref(props.data.uuid)
const isEditTitle = ref(false)

const fields = ref({
  type: 'interactive list',
  headerType: props.data.metadata?.fields?.headerType || 'none',
  headerText: props.data.metadata?.fields?.headerText || '', 
  headerMedia: props.data.metadata?.fields?.headerMedia || [], 
  body: props.data.metadata?.fields?.body || '',
  footer: props.data.metadata?.fields?.footer || '',
  buttonLabel: props.data.metadata?.fields?.buttonLabel || '',
  sections: props.data.metadata?.fields?.sections || [{ rows: [{}] }],
})

const options = ref([
  { value: 'none', label: 'None' },
  { value: 'text', label: 'Text' },
  { value: 'image', label: 'Image' },
  { value: 'video', label: 'Video' },
  { value: 'audio', label: 'Audio' },
  { value: 'document', label: 'Document' },
]);

const node = useNode()
const { removeNodes, nodes, addNodes, removeEdges, edges } = useVueFlow()

// Helper function to remove edges for a given handle ID
function removeEdgesForHandle(handleId) {
  const edgesToRemove = edges.value.filter(edge => edge.sourceHandle === handleId)
  edgesToRemove.forEach(edge => removeEdges(edge.id))
}

watch(
  fields,
  (newFields) => {
    node.node.data.metadata.fields = { ...newFields }
  },
  { deep: true }
)

function addSection() {
  if (fields.value.sections.length < 10) {
    fields.value.sections.push({ rows: [{}] })
  }
}

function addRow(sectionIndex) {
  if (fields.value.sections[sectionIndex].rows.length < 10) {
    fields.value.sections[sectionIndex].rows.push({})
  }
}

function removeSection(sectionIndex) {
  if (fields.value.sections.length > 1) {
    fields.value.sections[sectionIndex].rows.forEach((row, rowIndex) => {
      const handleId = 'a' + sectionIndex + rowIndex
      removeEdgesForHandle(handleId)
    })
    
    fields.value.sections.splice(sectionIndex, 1)
  }
}

function removeRow(sectionIndex, rowIndex) {
  // Check that there is more than one row to avoid deleting the last row in a section
  if (fields.value.sections[sectionIndex].rows.length > 1) {
    // Remove the specific row by creating a fresh array without it
    fields.value.sections[sectionIndex].rows.splice(rowIndex, 1);

    // Reset the remaining rows to ensure correct ordering and clear out any leftover data
    fields.value.sections[sectionIndex].rows = fields.value.sections[sectionIndex].rows.map((row, index) => {
      // Re-index each row and reset its data structure if needed
      return {
        ...row, // Retain existing properties if you want or reset if needed
        id: row.id, // Optional: Set an `id` or index if applicable
        title: row.title || "", // Clear out existing data or retain if needed
        description: row.description || "" // Adjust other fields accordingly
      };
    });

    removeEdgesForHandle('a' + sectionIndex + rowIndex)
  }
}

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

const shouldShowWarning = computed(() => {
  const hasEmptySections = fields.value.sections.some(section => {
    if (!section.title) return true

    return section.rows.every(row => !row.title || !row.id) // Check if all rows in a section are empty
  });

  return (
    (fields.value.headerType !== '' && fields.value.headerType === 'text' && fields.value.headerText === '') ||
    (fields.value.headerType !== '' && fields.value.headerType !== 'text' && fields.value.headerType !== 'none' && fields.value.headerMedia.length === 0) ||
    fields.value.body === '' || fields.value.buttonLabel === '' ||
    hasEmptySections
  );
});

function getCharsCount(input: string): number {
  return input.length;
}
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
            <h3 class="text-base" v-else>{{ title }}</h3>
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

      <div v-if="shouldShowWarning" class="flex items-center gap-x-2 bg-red-500 text-white rounded-md px-2 py-2">
        <span>
          <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><g fill="none"><path d="m12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035q-.016-.005-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427q-.004-.016-.017-.018m.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093q.019.005.029-.008l.004-.014l-.034-.614q-.005-.018-.02-.022m-.715.002a.02.02 0 0 0-.027.006l-.006.014l-.034.614q.001.018.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01z"/><path fill="currentColor" d="m13.299 3.148l8.634 14.954a1.5 1.5 0 0 1-1.299 2.25H3.366a1.5 1.5 0 0 1-1.299-2.25l8.634-14.954c.577-1 2.02-1 2.598 0M12 15a1 1 0 1 0 0 2a1 1 0 0 0 0-2m0-7a1 1 0 0 0-.993.883L11 9v4a1 1 0 0 0 1.993.117L13 13V9a1 1 0 0 0-1-1"/></g></svg>
        </span>
        <span class="text-sm">Please fill all the required fields</span>
      </div>

      <span class="text-sm text-gray-500"
        >Send interactive list message to your recipients.</span
      >

      <div class="mb-4">
          <label class="text-sm mb-2">Header (Optional)</label>
          <FormSelect v-model="fields.headerType" :name="''" :type="'text'" :optionClassName="'h-32'"  :options="options" :class="'col-span-4'"/>
      </div>

      <div v-if="fields.headerType == 'text'" class="mb-4">
        <label class="text-sm mb-2"><span class="text-red-500">*</span> Header Text</label>
        <FormInput v-model="fields.headerText" :name="''" :placeholder="'Enter header text'" :type="'text'" :class="'col-span-4'"/>
      </div>
      
      <FlowMedia v-if="fields.headerType != 'text' && fields.headerType != 'none'" v-model="fields.headerMedia" :type="fields.headerType" :uuid="uuid" :nodeId="node.id"/>

      <div class="mb-4">
        <label class="text-sm mb-2"><span class="text-red-500">*</span> Body</label>
        <FormTextArea v-model="fields.body" :placeholder="'Enter the main message for this message type'" :name="''" :type="'text'" :class="'col-span-4'"/>
      </div>

      <div class="mb-4">
        <label class="text-sm mb-2">Footer Text (Optional)</label>
        <FormInput v-model="fields.footer" :name="''" :placeholder="'Enter footer text'" :type="'text'" :class="'col-span-4'"/>
      </div>

      <div class="mb-4">
        <label class="text-sm mb-2"><span class="text-red-500">*</span> Button Label</label>
        <FormInput v-model="fields.buttonLabel" :name="''" :placeholder="'Enter footer text'" :type="'text'" :class="'col-span-4'"/>
      </div>

      <div class="flex justify-between items-center mb-4">
          <label class="text-sm">Sections (Atleast one section)</label>
          <button @click="addSection" class="bg-slate-100 p-1 rounded-md text-sm px-2">Add Section</button>
        </div>

      <div v-for="(section, sectionIndex) in fields.sections" :key="sectionIndex" class="border rounded p-3 mb-4">
        <div class="flex justify-between items-center mb-2">
          <label class="text-sm">Section {{ sectionIndex + 1 }}</label>
          <button v-if="sectionIndex > 0" @click="removeSection(sectionIndex)" class="text-red-500 text-sm">
            Remove Section
          </button>
        </div>
        
        <div class="mb-4">
          <label class="text-sm mb-2"><span class="text-red-500">*</span> Title</label>
          <FormInput v-model="section.title" :name="''" :placeholder="'Enter section title'" :type="'text'" :class="'col-span-4'"/>
        </div>

        <div class="flex justify-between items-center mb-4">
          <label class="text-sm">Rows (Atleast one row)</label>
          <button @click="addRow(sectionIndex)" class="bg-slate-100 p-1 rounded-md text-sm px-2">Add Row</button>
        </div>

        <div v-for="(row, rowIndex) in section.rows" :key="rowIndex" class="relative border rounded p-3 bg-slate-50 mb-2">
          <div class="flex justify-between items-center mb-2">
            <label class="text-sm">Row {{ rowIndex + 1 }}</label>
            <button v-if="rowIndex > 0" @click="removeRow(sectionIndex, rowIndex)" class="text-red-500 text-sm">
              Remove Row
            </button>
          </div>

          <div class="grid grid-cols-2 gap-x-6">
            <div class="mb-4">
              <label class="text-sm mb-2"><span class="text-red-500">*</span> ID</label>
              <FormInput v-model="row.id" maxLength="200" :name="''" :placeholder="'Enter ID'" :type="'text'" :class="'col-span-4'"/>
              <span class="text-sm">{{ getCharsCount(row?.id ?? '') }}/200</span>
            </div>

            <div class="mb-2">
              <label class="text-sm mb-2"><span class="text-red-500">*</span> Title</label>
              <FormInput v-model="row.title" maxLength="24" :name="''" :placeholder="'Enter row title'" :type="'text'" :class="'col-span-4'"/>
              <span class="text-sm">{{ getCharsCount(row?.title ?? '') }}/24</span>
            </div>
          </div>

          <div class="mb-2">
            <label class="text-sm mb-2"><span class="text-red-500">*</span> Description</label>
            <FormInput v-model="row.description" maxLength="72" :name="''" :placeholder="'Enter description'" :type="'text'" :class="'col-span-4'"/>
            <span class="text-sm">{{ getCharsCount(row?.description ?? '') }}/72</span>
          </div>

          <Handle :id="'a' + sectionIndex + rowIndex" type="source" :position="Position.Right" style="right: -25px"/>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Customize the appearance of the radio button */
.form-radio:checked {
  background-color: #3b82f6; /* Tailwind's blue-500 */
  border-color: #3b82f6;
}
</style>
