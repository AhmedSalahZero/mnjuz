<template>
    <div class="flex items-start justify-between">
        <div class="flex-grow">
            <div class="flex items-center space-x-3">
                <img :src="'/images/' + plugin.logo" :alt="plugin.name" class="w-8 h-8">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ plugin.name }}
                </h3>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                {{ plugin.description }}
            </p>
            <div class="mt-2 flex items-center space-x-4 text-sm text-gray-500">
                <span>Version {{ plugin.version }}</span>
            </div>
        </div>
        <div class="flex items-center space-x-4">
            <button
                @click="downloadPlugin(plugin)"
                :disabled="loadingStates[plugin.uuid]"
                class="inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:shadow-md hover:bg-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                <svg v-if="loadingStates[plugin.uuid]" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><g fill="none" stroke="#fff" stroke-linecap="round" stroke-width="1.5"><path d="M8 22h8c2.828 0 4.243 0 5.121-.878C22 20.242 22 18.829 22 16v-1c0-2.828 0-4.242-.879-5.121c-.768-.768-1.946-.865-4.121-.877m-10 0c-2.175.012-3.353.109-4.121.877C2 10.758 2 12.172 2 15v1c0 2.829 0 4.243.879 5.122c.3.3.662.497 1.121.627"/><path stroke-linejoin="round" d="M12 2v13m0 0l-3-3.5m3 3.5l3-3.5"/></g></svg>
                {{ loadingStates[plugin.uuid] ? 'Downloading...' : 'Download' }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
    plugin: {
        type: Object,
        required: true
    }
});

const loadingStates = ref({});

const configurePlugin = () => {
    window.location.href = '/settings/plugins/woocommerce';
}

const getPluginComponent = (pluginName) => {
    switch (pluginName.toLowerCase()) {
        case 'woocommerce':
            return WoocommerceIndex;
        default:
            return null;
    }
}

const downloadPlugin = async (plugin) => {
    // Set downloading state
    loadingStates.value[plugin.uuid] = true;
    
    try {
        // Use direct URL for downloading
        const downloadUrl = `/plugin/download/${plugin.name.toLowerCase()}`;
        
        // Trigger download using window.location
        window.location.href = downloadUrl;
        
        // Reset loading state after a short delay
        setTimeout(() => {
            loadingStates.value[plugin.uuid] = false;
        }, 1000);
    } catch (error) {
        console.error('Error downloading plugin:', error);
        loadingStates.value[plugin.uuid] = false;
    }
}
</script> 