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
    // Overridable via VITE_API_PROXY_TARGET (used by the Docker `node`
    // service to reach the `app` container by service name); unset natively,
    // so the fallback below keeps the exact original behavior.
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
    // BUG YANG DITEMUKAN & DIPERBAIKI (015-dockerize-dev-environment) —
    // di dalam Docker, Vite dijalankan dengan `--host 0.0.0.0` supaya bisa
    // diakses dari luar container, tapi laravel-vite-plugin menulis file
    // `public/hot` memakai host itu APA ADANYA — browser di HOST lalu
    // mencoba connect ke `http://0.0.0.0:5173/@vite/client` (bukan alamat
    // yang valid dari luar container) dan gagal total (ERR_EMPTY_RESPONSE),
    // sehingga seluruh app.js/app.css tidak pernah termuat. Ditemukan lewat
    // `docker compose up` + verifikasi browser sungguhan, bukan dari
    // membaca dokumentasi Vite. `server.origin` memberi tahu Vite alamat
    // publik yang BENAR untuk ditulis ke `public/hot`, terlepas dari host
    // bind internalnya — di native ini undefined (perilaku asli tidak
    // berubah), di Docker di-set via VITE_DEV_SERVER_ORIGIN.
    origin: process.env.VITE_DEV_SERVER_ORIGIN || undefined,
    // Explicit `true` (reflect the requesting origin) rather than Vite's
    // default — combined with a fixed `server.origin` above, the default
    // CORS behavior echoed that fixed origin instead of the browser's
    // actual page origin (localhost:8000), so the browser rejected the
    // cross-origin @vite/client/module script loads outright. Native
    // behavior is unaffected: without VITE_DEV_SERVER_ORIGIN set, this
    // was never a problem there, and `cors: true` is a strict widening,
    // never a narrowing, of what's already allowed.
    cors: true,
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./qa-tests/setup.js'],
  },
});
