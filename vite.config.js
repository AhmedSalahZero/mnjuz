import VueI18nPlugin from '@intlify/unplugin-vue-i18n/vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import i18n from 'laravel-vue-i18n/vite'
import path from 'path'
import { defineConfig } from 'vite'

export default defineConfig({
    server: {
        cors: true,
		proxy: {
            '/fonts': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
            },
        },
    },
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
