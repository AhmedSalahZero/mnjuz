<template>
  <component 
    :is="actionComponent" 
    :id="id" 
    :data="data" 
    :selected="selected"
  />
</template>

<script setup>
import { computed } from 'vue'
import AddToGroupNode from './actions/AddToGroup-node.vue'
import RemoveFromGroupNode from './actions/RemoveFromGroup-node.vue'
import UpdateContactNode from './actions/UpdateContact-node.vue'
import SendEmailNode from './actions/SendEmail-node.vue'
import DelayNode from './actions/Delay-node.vue'
import WebhookNode from './actions/Webhook-node.vue'
import AIResponseNode from './actions/AIResponse-node.vue'
import ConditionalNode from './actions/Conditional-node.vue'

const props = defineProps({
  id: String,
  data: Object,
  selected: Boolean,
})

const actionType = computed(() => {
  const rawType = props.data?.actionType || 'add_to_group'
  // Normalize action type by converting hyphens to underscores
  return rawType.replace(/-/g, '_')
})

const actionComponent = computed(() => {
  switch (actionType.value) {
    case 'add_to_group':
      return AddToGroupNode
    case 'remove_from_group':
      return RemoveFromGroupNode
    case 'update_contact':
      return UpdateContactNode
    case 'send_email':
      return SendEmailNode
    case 'delay':
      return DelayNode
    case 'webhook':
      return WebhookNode
    case 'ai_response':
      return AIResponseNode
    case 'conditional':
      return ConditionalNode
    default:
      return AddToGroupNode // Default fallback
  }
})
</script> 