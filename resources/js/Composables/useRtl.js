import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

export function useRtl() {
    const page = usePage()
    
    const isRtl = computed(() => page.props.isRtl)
    
    const rtlClass = computed(() => ({
        'direction-rtl': isRtl.value,
        'direction-ltr': !isRtl.value
    }))
    
    return {
        isRtl,
        rtlClass
    }
} 