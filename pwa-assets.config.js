import { defineConfig, minimal2023Preset } from '@vite-pwa/assets-generator/config';

// Run once with `npx pwa-assets-generator`, then rename icons/maskable-icon-512x512.png to icons/maskable-512x512.png and move icons/favicon.ico to public/favicon.ico. Output is committed, not built in CI.
export default defineConfig({
    headLinkOptions: { preset: '2023' },
    preset: {
        ...minimal2023Preset,
        transparent: { sizes: [192, 512], favicons: [[48, 'favicon.ico']] },
        maskable: { sizes: [512], padding: 0.3, resizeOptions: { background: '#3b6ea8' } },
        apple: { sizes: [180], padding: 0.15, resizeOptions: { background: '#3b6ea8' } },
    },
    images: ['public/icons/icon.svg'],
});
