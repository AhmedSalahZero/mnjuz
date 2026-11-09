<script setup lang="ts">
import { markRaw, nextTick, ref, watch, onMounted, h, provide } from 'vue'
import axios from 'axios'
import { ConnectionMode, VueFlow, useVueFlow } from '@vue-flow/core'
import { Background } from '@vue-flow/background'
import { Controls } from '@vue-flow/controls'

import StartNode from '@modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Start-node.vue'
import ButtonsNode from '@modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Buttons-node.vue'
import ListNode from '@modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/List-node.vue'
import MediaNode from '@modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Media-node.vue'
import TextNode from '@modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Text-node.vue'
import ActionNode from '@modules/FlowBuilder/Pages/User/Components/vue-flow/nodes/Action-node.vue'
import { Test_data } from '@/lib/constant'
import { nextNodeId } from '@modules/FlowBuilder/Pages/User/Components/vue-flow/flowNodes.js'

import type { Dimensions, Elements } from '@vue-flow/core'

const props = defineProps(['uuid', 'flow', 'contactGroups', 'contactFields', 'invalidNodeIds'])

// Provide contact groups to child components
provide('contactGroups', props.contactGroups || [])
// Provide contact fields to child components
provide('contactFields', props.contactFields || [])

const elements = ref<Elements>()

// آخر معرّف عقدة صدر في هذا الـ flow، محفوظًا معه: معرّف عقدةٍ محذوفة لا
// يُعاد لعقدة جديدة (انظر nextNodeId).
const lastNodeId = ref(0)

const issueNodeId = () => {
	const id = nextNodeId(nodes.value, lastNodeId.value)
	lastNodeId.value = Number(id)
	return id
}

// زرّ «نسخ» في العقد يأخذ معرّفه من هنا.
provide('nextFlowNodeId', issueNodeId)

// عقد الرسائل والإجراءات التي في القائمة الجانبية وحدها تُنشأ بالإفلات.
const MESSAGE_NODE_TYPES = ['text', 'media', 'buttons', 'list']

/**
 * البداية لا تُحذف: كان Backspace يحذفها ولا سبيل لإعادتها، فيحفظ المسار
 * بلا محفّز ويسقط الحفظ. والتحديد لا يُحمَّل: عقدةٌ حُفظت محدّدة كانت تُحذف
 * بأوّل Backspace بعد فتح المحرّر.
 */
const prepareNode = (node: any) => {
	const { selected, ...rest } = node ?? {}
	return rest.type === 'start' ? { ...rest, deletable: false } : rest
}

const nodeTypes = {
	start: markRaw(StartNode),
	list: markRaw(ListNode),
	buttons: markRaw(ButtonsNode),
	media: markRaw(MediaNode),
	text: markRaw(TextNode),
	action: markRaw(ActionNode),
}

const {
	findNode,
	nodes,
	edges,
	viewport,
	addNodes,
	addEdges,
	project,
	vueFlowRef,
	onConnect,
	onNodeDragStop,
	setNodes,
	setEdges,
	setViewport,
	onViewportChange,
	fitView,
} = useVueFlow()

onMounted(() => {
	if (props.flow.metadata) {
		const savedData = JSON.parse(props.flow.metadata)

		// Debug: Check for action nodes
		if (savedData.nodes) {
			const actionNodes = savedData.nodes.filter((node) => node.type === 'action')
		}

		const loadedNodes = (savedData.nodes || []).map(prepareNode)
		lastNodeId.value = Number(nextNodeId(loadedNodes, savedData.lastNodeId ?? 0)) - 1

		setNodes(loadedNodes)
		setEdges(savedData.edges || [])
		setViewport(savedData.viewport || [])
	} else {
		lastNodeId.value = 1
		setNodes([
			{
				id: '1',
				type: 'start',
				deletable: false,
				label: 'start',
				position: { x: 25, y: 10 },
				data: {
					uuid: props.uuid,
					title: 'start',
					metadata: {
						fields: [],
					},
				},
			},
		])
	}
})

// Watch for changes in all nodes to save data
watch(
	[nodes, edges],
	() => {
		// Debug: Check for action node changes
		const actionNodes = nodes.value.filter((node) => node.type === 'action')
		if (actionNodes.length > 0) {
		}

		saveNodesAndEdges()
	},
	{ deep: true },
)

onConnect((params) => {
	// Check if this is an exit connection (source handle)
	// For all nodes except the first one, exit handles should only connect to one target
	const sourceNode = findNode(params.source)
	const targetNode = findNode(params.target)

	// Skip constraint for the first node (start node)
	if (sourceNode && sourceNode.type === 'start') {
		addEdges(params)
		return
	}

	// For all other nodes, ensure exit handles only connect to one target
	if (params.source && params.target) {
		// Remove any existing edges that start from the same source handle
		const existingEdges = edges.value.filter(
			(edge) => edge.source === params.source && edge.sourceHandle === params.sourceHandle,
		)

		// Remove existing connections from the same source
		if (existingEdges.length > 0) {
			const edgesToRemove = existingEdges.map((edge) => edge.id)
			setEdges(edges.value.filter((edge) => !edgesToRemove.includes(edge.id)))
		}

		// Add the new connection
		addEdges(params)
	} else {
		// Fallback to normal connection behavior
		addEdges(params)
	}
})

onViewportChange(() => {
	saveNodesAndEdges()
})

const emit = defineEmits(['updateStatus', 'updatePayload'])

const getDefaultConfig = (actionType: string) => {
	switch (actionType) {
		case 'add_to_group':
		case 'remove_from_group':
			return { group_id: '' }
		case 'update_contact':
			return {
				target_field: '', // The field to save the user's message to
				// فارغ = الرسالة الافتراضية المترجمة على الخادم. النصّ الإنجليزي هنا كان يصل لعملاء عرب.
				invalid_email_message: '',
				fields: {
					// Keep for backward compatibility
					first_name: '',
					last_name: '',
					email: '',
					phone: '',
					address: {
						street: '',
						city: '',
						state: '',
						zip: '',
						country: '',
					},
					metadata: {},
				},
			}
		case 'send_email':
			return {
				subject: '',
				body: '',
				smtp_host: '',
				smtp_port: 587,
				smtp_username: '',
				smtp_password: '',
				smtp_encryption: 'tls',
				from_name: '',
			}
		case 'delay':
			return { duration: 5 }
		case 'webhook':
			return { url: '', method: 'POST' }
		case 'ai_response':
			return {
				prompt: '',
				proceed_condition: 'always',
				confidence_threshold: 0.8,
				low_confidence_message: '',
			}
		case 'conditional':
			return {
				condition_type: 'message_contains',
				field_name: '',
				field_operator: 'equals',
				variable_name: '',
				variable_operator: 'equals',
				conditions: [],
			}
		default:
			return {}
	}
}

const saveNodesAndEdges = () => {
	emit(
		'updatePayload',
		JSON.stringify({ nodes: nodes.value, edges: edges.value, viewport: viewport.value, lastNodeId: lastNodeId.value }),
	)
}

// الخطوات التي رُفض الحفظ أو النشر بسببها تُحدَّد ويُقرَّب إليها.
watch(
	() => props.invalidNodeIds,
	(ids) => {
		const invalid = new Set((ids || []).map(String))
		if (invalid.size === 0) return

		nodes.value.forEach((node) => {
			node.selected = invalid.has(String(node.id))
		})
		nextTick(() => fitView({ nodes: [...invalid], padding: 0.3, duration: 400 }))
	},
)

function handleOnDrop(event: DragEvent) {
	// بلا preventDefault يفتح المتصفح الملف المُفلَت (صورة من سطح المكتب) ويغادر
	// المحرّر، وكان يُنشئ قبلها عقدةً بلا نوع لا يُنشر المسار بوجودها.
	event.preventDefault()

	const type = event.dataTransfer?.getData('application/vueflow')
	if (type === 'workflow') {
		const { nodes, edges, position, zoom } = Test_data
		const [x = 0, y = 0] = position
		setNodes(nodes)
		setEdges(edges)
		setViewport({ x, y, zoom: zoom || 0 })
		return
	}

	const { left, top } = vueFlowRef.value!.getBoundingClientRect()

	const position = project({
		x: event.clientX - left,
		y: event.clientY - top,
	})


	if (!type || (!type.startsWith('action-') && !MESSAGE_NODE_TYPES.includes(type))) {
		return
	}

	// معرّف متّصل بلا فجوات: «الأكبر + 2» كان يترك ثقوبًا في التسلسل يمشي
	// فيها زر Duplicate فيتصادم المعرّفان.
	const newNodeId = issueNodeId()

	let newNode: any

	// Handle action nodes differently
	if (type && type.startsWith('action-')) {
		const actionType = type.replace('action-', '').replace(/-/g, '_')

		newNode = {
			id: newNodeId,
			type: 'action', // Always use 'action' type for backend compatibility
			position,
			label: `${actionType} action`,
			data: {
				uuid: props.uuid,
				title: actionType,
				actionType: actionType,
				config: getDefaultConfig(actionType),
				is_active: true,
			},
		}
	} else {
		newNode = {
			id: newNodeId,
			type,
			position,
			label: `${type} node`,
			data: {
				uuid: props.uuid,
				title: type,
				metadata: {
					fields: [],
				},
			},
		}
	}

	addNodes([newNode])

	nextTick(() => {
		const node = findNode(newNode.id)
		const stop = watch(
			() => node!.dimensions,
			(dimensions: Dimensions) => {
				if (dimensions.width > 0 && dimensions.height > 0 && node) {
					node.position = {
						x: node.position.x - node.dimensions.width / 2,
						y: node.position.y - node.dimensions.height / 2,
					}
					stop()
				}
			},
			{ deep: true, flush: 'post' },
		)
	})
}
function handleOnDragOver(event: DragEvent) {
	event.preventDefault()

	if (event.dataTransfer) {
		event.dataTransfer.dropEffect = 'move'
	}
}
</script>
<template>
	<div class="relative h-full w-full" id="main-canvas" @drop="handleOnDrop" @dragover="handleOnDragOver">
		<VueFlow v-model="elements" :node-types="nodeTypes" :connection-mode="ConnectionMode.Strict">
			<Controls />
			<Background />
		</VueFlow>
	</div>
	<!--<div class="h-1/2">
    {{ nodes }}
  </div>-->
</template>
<style>
@import '@vue-flow/core/dist/style.css';
@import '@vue-flow/core/dist/theme-default.css';
@import '@vue-flow/controls/dist/style.css';

#main-canvas {
	--vf-handle: hsl(var(--primary));
}

.vue-flow__handle {
	width: 18px;
	height: 18px;
}
</style>
