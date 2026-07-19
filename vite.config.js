import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import path from 'path'
import { defineConfig } from 'vite'

export default defineConfig({
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/vendor/**',
                '**/node_modules/**',
                '**/storage/**',
                '**/public/build/**',
                '**/.git/**',
            ],
        },
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
    build: {
        sourcemap: false,
        reportCompressedSize: false,
        target: 'es2020',
    },
    esbuild: {
        legalComments: 'none',
    },
    plugins: [
        vue(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
})
