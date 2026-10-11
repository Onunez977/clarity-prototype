import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    build: {
        chunkSizeWarningLimit: 750,
    },
    plugins: [
        tailwindcss(),
        vue(),
        laravel({
            input: [
                'resources/js/app.js',
                'resources/js/components/kit-user-path.js',
                'resources/js/components/filament-map-widget.js',
                'resources/js/components/zone-map-display.js',
                'resources/js/components/zone-manager.js',
                'resources/css/filament/app/theme.css',
                'resources/js/clarity-scout/main.js',
                'resources/js/clarity-scout/birdseye.js',
                'resources/js/otto/filament.js',
            ],
            refresh: true,
        }),
    ],
    // Lerd terminates TLS at nginx and proxies HTTP to Vite at /@lerd-vite/.
    // Do not set server.https — it makes Vite speak TLS while nginx speaks plain
    // HTTP upstream, which surfaces as ERR_SSL_VERSION_OR_CIPHER_MISMATCH /
    // "upstream prematurely closed connection". Prefer: lerd worker start vite
    // (not raw yarn/npm run dev, which bypasses the lerd wrapper and rewrites
    // public/hot to a broken https://[::1]:port URL).
    server: {
        watch: {
            // Virtiofs / some mounts miss inotify; polling keeps HMR reliable under lerd.
            usePolling: true,
        },
    },
});
