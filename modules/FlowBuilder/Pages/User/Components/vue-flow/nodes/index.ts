export interface LLMNodeData {
  title: string
}

export interface LLMNodeEvents {}

export interface ActionNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface ActionNodeEvents {
  'update:data': (data: ActionNodeData) => void
}

export interface AddToGroupNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface AddToGroupNodeEvents {
  'update:data': (data: AddToGroupNodeData) => void
}

export interface RemoveFromGroupNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface RemoveFromGroupNodeEvents {
  'update:data': (data: RemoveFromGroupNodeData) => void
}

export interface UpdateContactNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface UpdateContactNodeEvents {
  'update:data': (data: UpdateContactNodeData) => void
}

export interface SendEmailNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface SendEmailNodeEvents {
  'update:data': (data: SendEmailNodeData) => void
}

export interface DelayNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface DelayNodeEvents {
  'update:data': (data: DelayNodeData) => void
}

export interface WebhookNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface WebhookNodeEvents {
  'update:data': (data: WebhookNodeData) => void
}

export interface ConditionalNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface ConditionalNodeEvents {
  'update:data': (data: ConditionalNodeData) => void
}

export interface AIResponseNodeData {
  actionType: string
  name: string
  config: Record<string, any>
  is_active: boolean
}

export interface AIResponseNodeEvents {
  'update:data': (data: AIResponseNodeData) => void
}

// Export all action nodes
export * from './actions'
