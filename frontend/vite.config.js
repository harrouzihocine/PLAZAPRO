import { createHash } from 'node:crypto'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Backend origin the dev server proxies to. 'nginx' resolves only inside the
// Docker network; for a host run set VITE_PROXY_TARGET=http://127.0.0.1:8000.
const proxyTarget = process.env.VITE_PROXY_TARGET || 'http://nginx:80'

// Inject the built asset list into dist/sw.js (public/sw.js's
// __PLAZA_PRECACHE__ / __PLAZA_BUILD__ placeholders) so the service worker
// precaches the WHOLE build at install. Without this the worker only cached
// chunks as pages loaded them — and since a deploy renames every hashed chunk,
// the APK's offline boot broke after each deploy until every screen had been
// revisited online. Code + styles + fonts only: the flag SVGs (140+ files,
// three ever used) stay on the runtime backfill path.
function swPrecache() {
  let outDir = 'dist'
  let urls = []
  return {
    name: 'plaza-sw-precache',
    apply: 'build',
    configResolved(config) {
      outDir = config.build.outDir
    },
    generateBundle(_, bundle) {
      urls = Object.keys(bundle)
        .filter((f) => /\.(js|css|woff2)$/.test(f))
        .map((f) => '/' + f)
        .sort()
    },
    closeBundle() {
      // Runs after Vite copies public/ into the build output.
      const file = resolve(outDir, 'sw.js')
      if (!existsSync(file) || !urls.length) return
      const build = createHash('sha256').update(urls.join('\n')).digest('hex').slice(0, 12)
      writeFileSync(
        file,
        readFileSync(file, 'utf8')
          .replace('__PLAZA_BUILD__', build)
          .replace('/*__PLAZA_PRECACHE__*/ []', JSON.stringify(urls)),
      )
    },
  }
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), swPrecache()],
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
