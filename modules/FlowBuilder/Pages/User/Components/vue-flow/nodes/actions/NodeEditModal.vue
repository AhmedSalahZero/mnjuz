<template>
  <!--
    نافذة تعديل عقدة الـ action.

    تُرسم في body لا داخل العقدة: vue-flow يضع transform على كل .vue-flow__node،
    وأي عنصر position: fixed داخل عنصر له transform يصير مرجعه ذلك العنصر لا
    الشاشة. فكانت النافذة «الكاملة» بحجم العقدة (المستطيلان الداكنان على جانبي
    الفورم)، تُقصّ ويقصّها overflow القماش، وتتكبّر وتتصغّر مع زوم القماش.
  -->
  <Teleport to="body">
    <!--
      nokey: vue-flow يحذف العقدة المحدّدة عند Backspace إلا إن كان الهدف حقل
      إدخال أو داخل .nokey. وقائمة الاختيار زرٌّ لا حقل، فكان Backspace عليها
      يحذف العقدة والنافذة معها — وهذا هو «الزرّ يختفي عند Edit».
    -->
    <div
      v-if="open"
      class="nokey nodrag nowheel nopan fixed inset-0 z-[9999] flex items-center justify-center bg-black/50 p-4"
      role="dialog"
      aria-modal="true"
      @mousedown.self="close"
    >
      <div
        ref="panel"
        tabindex="-1"
        class="bg-white rounded-lg w-full max-w-md max-h-[90vh] overflow-y-auto shadow-xl outline-none"
      >
        <slot />
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
  open: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const panel = ref(null)

const close = () => emit('close')

// Escape يغلق النافذة، إلا إن كان قد عُولج قبلها: قائمة اختيار مفتوحة تأخذه
// لنفسها (preventDefault) فتُغلَق هي وحدها وتبقى النافذة.
const onKeydown = (event) => {
  if (event.key === 'Escape' && !event.defaultPrevented) {
    close()
  }
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      document.addEventListener('keydown', onKeydown)
      // التركيز داخل النافذة: يبقى على الزرّ داخل العقدة فتصله ضغطات المفاتيح.
      nextTick(() => panel.value?.focus())
    } else {
      document.removeEventListener('keydown', onKeydown)
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
})
</script>
