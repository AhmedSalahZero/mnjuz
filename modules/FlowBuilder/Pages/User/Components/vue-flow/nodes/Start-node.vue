<script lang="ts" setup>
import { ref, watch } from 'vue'
import { Handle, Position, useNode } from '@vue-flow/core'
import FormSelect from '@/Components/FormSelect.vue';
import FormTextArea from '@/Components/FormTextArea.vue';
import type { NodeProps } from '@vue-flow/core'

const props = defineProps<NodeProps>()
const input1 = ref(props.data.metadata?.fields["type"] || '')
const input2 = ref(props.data.metadata?.fields["keywords"] || '')
const options = ref([
  { value: 'new_contact', label: 'New contact' },
  { value: 'keywords', label: 'Text contains specific keywords' }
]);

watch(input1, (newValue) => {
  node.node.data.metadata.fields = {
    ...node.node.data.metadata.fields,
    type: newValue,
    keywords: null
  }
})

watch(input2, (newValue) => {
  node.node.data.metadata.fields = {
    ...node.node.data.metadata.fields,
    type: input1,
    keywords: newValue
  }
})

const handleConnectable: HandleConnectableFunc = (node, connectedEdges) => {
  // only allow connections if the node has 0 connections
  return connectedEdges.length <= 0
}

const node = useNode()
</script>

<template>
  <div class="max-w-[520px] rounded-sm border border-gray-200 bg-white p-3 shadow-md">
    <div class="flex flex-col gap-y-4">
      <div class="flex justify-between">
        <div class="flex items-center gap-x-2">
          <img src="~@/assets/images/icon_Start.png" class="h-4 w-4" alt="Start icon" />
          <div class="flex flex-col gap-y-1">
            <p class="text-sm text-gray-500">Trigger</p>
          </div>
        </div>
      </div>

      <div v-if="input1 === 'keywords' && input2 == ''" class="flex items-center gap-x-2 bg-red-500 text-white rounded-md px-2 py-2">
        <span>
          <svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><g fill="none"><path d="m12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035q-.016-.005-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427q-.004-.016-.017-.018m.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093q.019.005.029-.008l.004-.014l-.034-.614q-.005-.018-.02-.022m-.715.002a.02.02 0 0 0-.027.006l-.006.014l-.034.614q.001.018.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01z"/><path fill="currentColor" d="m13.299 3.148l8.634 14.954a1.5 1.5 0 0 1-1.299 2.25H3.366a1.5 1.5 0 0 1-1.299-2.25l8.634-14.954c.577-1 2.02-1 2.598 0M12 15a1 1 0 1 0 0 2a1 1 0 0 0 0-2m0-7a1 1 0 0 0-.993.883L11 9v4a1 1 0 0 0 1.993.117L13 13V9a1 1 0 0 0-1-1"/></g></svg>
        </span>
        <span class="text-sm">Please fill all the required fields</span>
      </div>

      <span class="text-sm text-gray-500">Define the input parameters to trigger the workflow, to ensure that <br> accurate information is captured in the conversation flow.</span>

      <div class="rounded-md bg-muted">
        <div class="mb-4">
          <label class="text-sm mb-2">Starting Step</label>
          <FormSelect v-model="input1" :name="''" :type="'text'" :optionClassName="'h-32'"  :options="options" :class="'col-span-4'"/>
        </div>
        <div v-if="input1 === 'keywords'">
          <label class="text-sm mb-2"><span class="text-red-500">*</span> Trigger keywords</label>
          <FormTextArea v-model="input2" :name="''" :placeholder="'Enter keywords separated by a comma'" :type="'text'" :class="'col-span-4'"/>
        </div>
      </div>
    </div>
    <Handle type="source" :position="Position.Right" :connectable="handleConnectable" />
  </div>
</template>
