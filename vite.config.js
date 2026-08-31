import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';

// BoothPOS ships as static assets served by Laravel from the same local
// machine (PRD §9) — no separate frontend server, no cloud deploy. The
// laravel-vite-plugin wires the manifest so resources/views/app.blade.php
// can pull in the right hashed bundle via @vite(), same as any other
// Laravel app; `npm run build` outputs to public/build.
export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/main.js'],
      refresh: true,
    }),
    vue(),
    tailwindcss(),
  ],
  resolve: {
    alias: {
      '@': '/resources/js',
    },
  },
  server: {
    // Dev convenience only — the shipped runtime is `php artisan serve` +
    // the built assets from the same origin (PRD §9), so axios always
    // calls a relative `/api/v1/...` and needs no base URL config. This
    // proxy just lets `npm run dev` (Vite on its own port) reach the
    // Laravel dev server without a CORS dance while iterating.
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./qa-tests/setup.js'],
  },
});
