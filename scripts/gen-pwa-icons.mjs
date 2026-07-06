// scripts/gen-pwa-icons.mjs — regenerate the PWA icon set from the brand logo.
// Run-once tool (icons are committed); rerun only when the logo changes:
//
//   docker run --rm -u "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm \
//     -v "$PWD":/repo -w /tmp node:20-alpine \
//     sh -c "npm i -s sharp && cp /repo/scripts/gen-pwa-icons.mjs . && node gen-pwa-icons.mjs /repo/frontend/public/logo.png /repo/frontend/public/icons"
//
// Produces (all on the brand-navy field so the icon reads on any launcher):
//   icon-192.png / icon-512.png  — "any" purpose, logo at ~68%
//   maskable-512.png             — logo at ~56%, inside Android's mask safe zone
//   apple-touch-icon.png         — 180px, opaque (iOS rounds the corners itself)
import { mkdirSync } from 'node:fs'
import sharp from 'sharp'

const [SRC = 'frontend/public/logo.png', OUT = 'frontend/public/icons'] = process.argv.slice(2)
const BG = { r: 0x1e, g: 0x21, b: 0x4b, alpha: 1 } // brand navy #1e214b (index.html theme-color)
const GOLD = { r: 0xc9, g: 0xa2, b: 0x27, alpha: 1 } // brand gold 500 (theme/preset.js)

mkdirSync(OUT, { recursive: true })

async function icon(size, fill, name) {
  const inner = Math.round(size * fill)
  const logo = await sharp(SRC)
    .resize(inner, inner, { fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .toBuffer()
  // The source mark is navy-on-transparent — invisible on the navy field. Tint
  // it brand gold by keeping a solid gold layer only where the logo has pixels.
  const goldMark = await sharp({ create: { width: inner, height: inner, channels: 4, background: GOLD } })
    .composite([{ input: logo, blend: 'dest-in' }])
    .png()
    .toBuffer()
  const pad = Math.round((size - inner) / 2)
  await sharp({ create: { width: size, height: size, channels: 4, background: BG } })
    .composite([{ input: goldMark, top: pad, left: pad }])
    .png()
    .toFile(`${OUT}/${name}`)
  console.log('wrote', `${OUT}/${name}`)
}

await icon(192, 0.68, 'icon-192.png')
await icon(512, 0.68, 'icon-512.png')
await icon(512, 0.56, 'maskable-512.png')
await icon(180, 0.7, 'apple-touch-icon.png')
