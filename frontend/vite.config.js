import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: true, // listen on 0.0.0.0 inside the container
    port: 5173,
    proxy: {
      // Same-origin API + Sanctum cookie endpoints, proxied to nginx (Docker).
      '/api': { target: 'http://nginx:80', changeOrigin: true },
      '/sanctum': { target: 'http://nginx:80', changeOrigin: true },
      // Broadcast channel auth (Reverb private channels) — cookie-authed via Laravel.
      '/broadcasting': { target: 'http://nginx:80', changeOrigin: true },
    },
  },
})
