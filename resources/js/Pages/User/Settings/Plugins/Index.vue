<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center mb-8">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-2">
                        <div class="flex px-4 pt-2 pb-4">
                            <div>
                                <h2 class="text-[17px]">{{ $t('Plugins Section') }}</h2>
                                <span class="flex items-center mt-1">
                                    <svg class="mr-2" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    {{ $t('Enable or disable plugins to enhance your business operations.') }}
                                </span>
                            </div>
                        </div>
                        <div class="max-w-7xl mx-auto">
                            <div class="overflow-hidden sm:rounded-lg">
                                <div class="p-6">
                                    <div v-if="plugins.length === 0" class="text-center py-12">
                                        <div class="mx-auto w-24 h-24 text-gray-300 mb-4">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
                                            </svg>
                                        </div>
                                        <h3 class="text-lg font-medium text-gray-900 mb-2">{{ $t('No Plugins Available') }}</h3>
                                        <p class="text-gray-500 max-w-sm mx-auto">
                                            {{ $t('There are currently no plugins available for your account. Check back later for new integrations.') }}
                                        </p>
                                    </div>
                                    
                                    <div v-else class="space-y-6">
                                        <div v-for="plugin in plugins" :key="plugin.uuid" 
                                            class="border rounded-lg p-4 hover:shadow-md transition-shadow">
                                            <component 
                                                :is="getPluginComponent(plugin.name)"
                                                :plugin="plugin"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SettingLayout>
</template>

<script setup>
import { ref, defineAsyncComponent } from 'vue';
import SettingLayout from "./../Layout.vue";

const props = defineProps(['modules', 'plugins']);

const getPluginComponent = (pluginName) => {
    try {
        return defineAsyncComponent(() => 
            import(`../../../../../../modules/${pluginName}/Pages/User/Index.vue`)
        );
    } catch (error) {
        console.warn(`Plugin component not found for ${pluginName}`);
        return null;
    }
};
</script> 