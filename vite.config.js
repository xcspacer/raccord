import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { google } from 'laravel-vite-plugin/fonts';

export default defineConfig({
    build: {
        // The 3D map (three.js) and the spreadsheet library of the OTDR acceptance (ExcelJS) are separate chunks, loaded only
        // when a page needs them.
        chunkSizeWarningLimit: 1000,
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/fibre-code-standalone.css', 'resources/js/fibre-code/standalone.js'],
            refresh: true,
            // Fonts are cut by alphabet and the browser only fetches the alphabets a page uses: preloading all of them only raises console warnings.
            fonts: [
                google('Oswald', { weights: [400, 500, 600, 700], subsets: ['latin', 'latin-ext', 'cyrillic'], preload: false }),
                google('Inter', { weights: [400, 500, 600, 700], subsets: ['latin', 'latin-ext', 'cyrillic'], preload: false }),
                google('IBM Plex Mono', { weights: [400, 500], subsets: ['latin', 'latin-ext', 'cyrillic'], preload: false }),
            ],
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
