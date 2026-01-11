import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import i18n from 'laravel-vue-i18n/vite'; 
import VueI18nPlugin from '@intlify/unplugin-vue-i18n/vite';
import vue from '@vitejs/plugin-vue'
import path from 'path';

export default defineConfig({
    resolve: {
        alias: {
            '@modules': path.resolve(__dirname, 'modules'),
        },
    },

    plugins: [
        vue(),
        VueI18nPlugin({
            include: path.resolve(__dirname, 'lang/**')
        }),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        i18n(),
    ],
});
