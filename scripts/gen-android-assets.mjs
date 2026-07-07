// scripts/gen-android-assets.mjs — regenerate the Capacitor Android icon/splash
// SOURCE images (frontend/assets/) from the brand logo. Run-once tool (outputs
// are committed); rerun only when the logo changes, then let @capacitor/assets
// paint them into android/app/src/main/res:
//
//   docker run --rm -u "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm \
//     -v "$PWD":/repo -w /tmp node:20-alpine \
//     sh -c "npm i -s sharp && cp /repo/scripts/gen-android-assets.mjs . && node gen-android-assets.mjs /repo/frontend/public/logo.png /repo/frontend/assets"
//   docker run --rm -u "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
//     -v "$PWD/frontend":/app -w /app node:20-alpine npx -y @capacitor/assets@3 generate --android
//
// Produces (the file names @capacitor/assets looks for):
//   icon-only.png        1024px — legacy/round launcher icon, logo at ~68%
//   icon-foreground.png  1024px — adaptive-icon foreground, logo at ~50%
//                        (inside the 66% mask safe zone), transparent field
//   icon-background.png  1024px — adaptive-icon background, solid brand navy
//   splash.png / splash-dark.png  2732px — navy field, logo at ~20%
import { mkdirSync } from 'node:fs'
import sharp from 'sharp'

const [SRC = 'frontend/public/logo.png', OUT = 'frontend/assets'] = process.argv.slice(2)
const BG = { r: 0x1e, g: 0x21, b: 0x4b, alpha: 1 } // brand navy #1e214b
const GOLD = { r: 0xc9, g: 0xa2, b: 0x27, alpha: 1 } // brand gold 500
const NONE = { r: 0, g: 0, b: 0, alpha: 0 }

mkdirSync(OUT, { recursive: true })

// The source mark is navy-on-transparent — invisible on the navy field. Tint
// it brand gold by keeping a solid gold layer only where the logo has pixels.
async function goldMark(size) {
  const logo = await sharp(SRC).resize(size, size, { fit: 'contain', background: NONE }).toBuffer()
  return sharp({ create: { width: size, height: size, channels: 4, background: GOLD } })
    .composite([{ input: logo, blend: 'dest-in' }])
    .png()
    .toBuffer()
}

async function field(size, fill, background, name) {
  const inner = Math.round(size * fill)
  const pad = Math.round((size - inner) / 2)
  const marks = inner > 0 ? [{ input: await goldMark(inner), top: pad, left: pad }] : []
  await sharp({ create: { width: size, height: size, channels: 4, background } })
    .composite(marks)
    .png()
    .toFile(`${OUT}/${name}`)
  console.log('wrote', `${OUT}/${name}`)
}

await field(1024, 0.68, BG, 'icon-only.png')
await field(1024, 0.5, NONE, 'icon-foreground.png')
await field(1024, 0, BG, 'icon-background.png') // solid navy, no mark
await field(2732, 0.2, BG, 'splash.png')
await field(2732, 0.2, BG, 'splash-dark.png')
