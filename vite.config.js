import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/pwa.js'],
            refresh: true,
        }),
        vue(),
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.js',
            outDir: 'public',
            buildBase: '/',
            base: '/',
            scope: '/',
            registerType: 'prompt',
            injectRegister: false,
            manifestFilename: 'manifest.webmanifest',
            manifest: {
                name: 'Family Hub',
                short_name: 'Family Hub',
                start_url: '/',
                scope: '/',
                display: 'standalone',
                theme_color: '#3b6ea8',
                background_color: '#fbfbfa',
                icons: [
                    { src: '/icons/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/icons/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
                    { src: '/icons/maskable-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
            },
            injectManifest: {
                globDirectory: 'public',
                globPatterns: ['build/assets/**/*.{js,css,woff2}', 'icons/*.{png,svg}', 'offline.html'],
            },
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
