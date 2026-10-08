import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    server: {
        watch: {
            usePolling: true,
        },
    },
    optimizeDeps: {
        include: [
            'recharts',
            'react-is',
            'boneyard-js/react',
        ],
    },
    assetsInclude: ['**/*.geojson'],
    json: {
        stringify: false, // Parse JSON files instead of stringifying them
    },
    plugins: [
        laravel({
            input: 'resources/js/app.tsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
});
