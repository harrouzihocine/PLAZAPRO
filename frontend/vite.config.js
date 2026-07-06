import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Backend origin the dev server proxies to. 'nginx' resolves only inside the
// Docker network; for a host run set VITE_PROXY_TARGET=http://127.0.0.1:8000.
const proxyTarget = process.env.VITE_PROXY_TARGET || 'http://nginx:80'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // Pre-bundle heavy libraries that only appear inside lazily-imported route
  // chunks (Leaflet on the location page, Chart.js on analytics). Without this
  // Vite first discovers them when you navigate there, re-optimises, and forces
  // a full page reload — the multi-second stall on first open of those pages.
  optimizeDeps: {
    include: ['leaflet', 'chart.js'],
  },
  server: {
    host: true, // listen on 0.0.0.0 inside the container
    port: 5173,
    proxy: {
      // Same-origin API + Sanctum cookie endpoints. Defaults to the Docker nginx
      // service; override with VITE_PROXY_TARGET for a host run (e.g. artisan serve).
      '/api': { target: proxyTarget, changeOrigin: true },
      '/sanctum': { target: proxyTarget, changeOrigin: true },
      // Broadcast channel auth (Reverb private channels) — cookie-authed via Laravel.
      '/broadcasting': { target: proxyTarget, changeOrigin: true },
    },
  },
})
