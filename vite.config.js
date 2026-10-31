import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.js'],
            refresh: true,
        }),
        vue({
            // <altcha-widget> = web component ALTCHA (anti-bot), bukan komponen Vue
            template: { compilerOptions: { isCustomElement: (tag) => tag === 'altcha-widget' } },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    build: {
        rolldownOptions: {
            output: {
                // Runtime framework jarang berubah → chunk sendiri yang tetap ter-cache antar deploy.
                // Ikon lucide sengaja tidak digrup: tree-shaken per halaman, jadi tiap halaman memuat ikonnya saja.
                codeSplitting: {
                    groups: [
                        { name: 'vendor-vue', test: /node_modules[\/](@vue|vue|vue-router|pinia)[\/]/, priority: 10 },
                    ],
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
