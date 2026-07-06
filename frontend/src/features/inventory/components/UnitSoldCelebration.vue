<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import { useAnnouncementsStore } from '@/features/inventory/announcementsStore'
import { formatMoney } from '@/features/payments/money'
import { initials } from '@/utils/format'

// The full-screen "congratulations" that fires for EVERY logged-in user the
// moment a unit is sold (driven by the announcements store's celebration state).
// Stays up until dismissed. Sized with viewport-relative units so it fits a
// laptop screen without scrolling; a bright card that reads on the dark scrim in
// both light and dark app themes.
const store = useAnnouncementsStore()
const router = useRouter()

const sale = computed(() => store.celebration)

// Marketing falls back to the deal's owner when nobody was explicitly credited.
const marketing = computed(() => {
  const s = sale.value
  if (!s) return []
  return s.sale_agents?.length ? s.sale_agents : s.agent ? [s.agent] : []
})
const insite = computed(() => sale.value?.insite_agents ?? [])
const others = computed(() => sale.value?.other_agents ?? [])

const creditGroups = computed(() =>
  [
    { icon: 'pi pi-megaphone', label: 'Marketing', names: marketing.value, tone: 'tone-sale' },
    { icon: 'pi pi-map-marker', label: 'In-site', names: insite.value, tone: 'tone-insite' },
    { icon: 'pi pi-users', label: 'Others', names: others.value, tone: 'tone-other' },
  ].filter((g) => g.names.length),
)

// The sold unit's spec, shown as chips under the address.
const details = computed(() => {
  const s = sale.value
  if (!s) return []
  return [
    s.type ? { icon: 'pi pi-home', text: s.type } : null,
    s.room_number ? { icon: 'pi pi-th-large', text: s.room_number } : null,
    s.floor ? { icon: 'pi pi-building', text: s.floor } : null,
    s.area_sqm ? { icon: 'pi pi-expand', text: `${s.area_sqm} m²` } : null,
  ].filter(Boolean)
})

const CONFETTI_COLORS = ['#ffd43b', '#ff6ea9', '#4dabf7', '#69db7c', '#b197fc', '#ffa94d']
const confetti = Array.from({ length: 48 }, (_, i) => ({
  left: `${(i * 137.5) % 100}%`,
  delay: `${(i % 12) * 0.22}s`,
  duration: `${2.6 + (i % 7) * 0.4}s`,
  color: CONFETTI_COLORS[i % CONFETTI_COLORS.length],
  size: 7 + (i % 4) * 3,
}))

function openUnit() {
  const id = sale.value?.unit_id
  store.dismissCelebration()
  if (id) router.push({ name: 'inventory.unit', params: { id } })
}
</script>

<template>
  <Teleport to="body">
    <Transition name="celebrate">
      <div
        v-if="sale"
        class="celebration-overlay"
        role="dialog"
        aria-modal="true"
        aria-label="Unit sold"
      >
        <span
          v-for="(c, i) in confetti"
          :key="i"
          class="confetti"
          :style="{
            left: c.left,
            animationDelay: c.delay,
            animationDuration: c.duration,
            background: c.color,
            width: `${c.size}px`,
            height: `${c.size * 1.6}px`,
          }"
          aria-hidden="true"
        />

        <div class="stage">
          <div class="rays" aria-hidden="true" />
          <div class="glow" aria-hidden="true" />

          <div class="card">
            <div class="emoji">🎉</div>
            <p class="eyebrow">CONGRATULATIONS</p>
            <h2 class="headline">Unit sold!</h2>

            <p class="unit-ref">{{ sale.reference }}</p>
            <p v-if="sale.location || sale.address" class="unit-place">
              <span v-if="sale.location" class="place-name">
                <i class="pi pi-building" aria-hidden="true" /> {{ sale.location }}
              </span>
              <span v-if="sale.address" class="place-addr">{{ sale.address }}</span>
            </p>

            <div v-if="details.length" class="details">
              <span v-for="d in details" :key="d.text" class="detail">
                <i :class="d.icon" aria-hidden="true" /> {{ d.text }}
              </span>
            </div>

            <div v-if="sale.price" class="price-pill">
              <i class="pi pi-money-bill" aria-hidden="true" />
              {{ formatMoney(sale.price) }}
            </div>

            <!-- Who earned it: a compact label + modern avatar chips per group -->
            <div v-if="creditGroups.length" class="credits">
              <div v-for="g in creditGroups" :key="g.label" class="credit-row" :class="g.tone">
                <span class="credit-tag"><i :class="g.icon" aria-hidden="true" /> {{ g.label }}</span>
                <div class="credit-people">
                  <span v-for="n in g.names" :key="n" class="person">
                    <span class="avatar">{{ initials(n) }}</span>
                    <span class="person-name">{{ n }}</span>
                  </span>
                </div>
              </div>
            </div>

            <div class="actions">
              <Button label="See the unit" icon="pi pi-external-link" rounded @click="openUnit" />
              <Button
                label="Close"
                icon="pi pi-times"
                rounded
                severity="secondary"
                text
                @click="store.dismissCelebration()"
              />
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.celebration-overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  overflow: hidden;
  background: radial-gradient(circle at 50% 40%, rgba(80, 40, 120, 0.55), rgba(10, 12, 30, 0.82));
  backdrop-filter: blur(4px);
}

.stage {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  max-width: 100%;
}

/* Rotating sunburst — larger than the card, rays poke past the edges so the
   silhouette reads as a badge, not a rectangle. */
.rays {
  position: absolute;
  width: 132%;
  aspect-ratio: 1;
  border-radius: 50%;
  background: conic-gradient(
    from 0deg,
    #ffd43b 0deg 18deg, transparent 18deg 36deg,
    #ff6ea9 36deg 54deg, transparent 54deg 72deg,
    #4dabf7 72deg 90deg, transparent 90deg 108deg,
    #69db7c 108deg 126deg, transparent 126deg 144deg,
    #b197fc 144deg 162deg, transparent 162deg 180deg,
    #ffd43b 180deg 198deg, transparent 198deg 216deg,
    #ff6ea9 216deg 234deg, transparent 234deg 252deg,
    #4dabf7 252deg 270deg, transparent 270deg 288deg,
    #69db7c 288deg 306deg, transparent 306deg 324deg,
    #b197fc 324deg 342deg, transparent 342deg 360deg
  );
  opacity: 0.4;
  -webkit-mask-image: radial-gradient(circle, transparent 36%, #000 45%, #000 57%, transparent 68%);
  mask-image: radial-gradient(circle, transparent 36%, #000 45%, #000 57%, transparent 68%);
  animation: spin 22s linear infinite;
}
.glow {
  position: absolute;
  width: 108%;
  aspect-ratio: 1;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255, 214, 102, 0.38), transparent 62%);
  filter: blur(16px);
  animation: pulse 2.8s ease-in-out infinite;
}

.card {
  position: relative;
  z-index: 1;
  width: min(94vw, 40rem);
  max-height: 96vh;
  text-align: center;
  padding: clamp(1.1rem, 3.2vh, 2rem) clamp(1.25rem, 4vw, 2rem);
  border-radius: 2rem;
  background: linear-gradient(180deg, #ffffff, #fff6ec);
  color: #1f2430;
  box-shadow:
    0 0 0 5px rgba(255, 255, 255, 0.3),
    0 26px 64px -14px rgba(0, 0, 0, 0.6),
    0 0 54px rgba(255, 190, 80, 0.32);
  animation: pop 0.5s cubic-bezier(0.18, 1.3, 0.4, 1) both;
}

.emoji {
  font-size: clamp(2rem, 6.5vh, 3.25rem);
  line-height: 1;
  animation: bob 2.4s ease-in-out infinite;
}
.eyebrow {
  margin-top: 0.2rem;
  font-size: clamp(0.62rem, 1.4vh, 0.78rem);
  font-weight: 800;
  letter-spacing: 0.28em;
  color: #f08c00;
}
.headline {
  margin-top: 0.05rem;
  font-size: clamp(1.7rem, 5.6vh, 2.9rem);
  font-weight: 900;
  line-height: 1.02;
  letter-spacing: -0.02em;
  background: linear-gradient(92deg, #ff6ea9, #f76707 42%, #f59f00 72%, #ffd43b);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}

.unit-ref {
  margin-top: clamp(0.35rem, 1.4vh, 0.6rem);
  font-size: clamp(1.05rem, 2.3vh, 1.3rem);
  font-weight: 800;
  color: #1f2430;
}
.unit-place {
  margin-top: 0.12rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.05rem;
  line-height: 1.25;
}
.place-name {
  font-size: clamp(0.86rem, 1.9vh, 0.98rem);
  font-weight: 700;
  color: #6741d9;
}
.place-name i {
  font-size: 0.78rem;
  opacity: 0.8;
}
.place-addr {
  font-size: clamp(0.78rem, 1.7vh, 0.88rem);
  color: #6b7385;
}

.details {
  margin-top: clamp(0.45rem, 1.6vh, 0.85rem);
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.35rem;
}
.detail {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.28rem 0.72rem;
  border-radius: 999px;
  font-size: clamp(0.78rem, 1.7vh, 0.88rem);
  font-weight: 600;
  color: #475065;
  background: rgba(120, 130, 160, 0.13);
}
.detail i {
  font-size: 0.72rem;
  opacity: 0.7;
}

.price-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: clamp(0.5rem, 1.7vh, 0.95rem);
  padding: 0.5rem 1.3rem;
  border-radius: 999px;
  font-weight: 800;
  font-size: clamp(1.02rem, 2.3vh, 1.3rem);
  color: #1b5e20;
  background: linear-gradient(135deg, #d3f9d8, #b2f2bb);
  box-shadow: inset 0 0 0 1px rgba(45, 160, 80, 0.3);
}

/* Credits: one compact row per group — a coloured tag on the left, modern
   avatar chips flowing on the right (wrap under on narrow screens). */
.credits {
  margin-top: clamp(0.7rem, 2.2vh, 1.3rem);
  display: flex;
  flex-direction: column;
  gap: clamp(0.35rem, 1.2vh, 0.6rem);
  width: 100%;
}
.credit-row {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  flex-wrap: wrap;
}
.credit-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  flex-shrink: 0;
  padding: 0.32rem 0.7rem;
  border-radius: 0.7rem;
  font-size: 0.64rem;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: #fff;
  background: linear-gradient(135deg, var(--accent, #7048e8), var(--accent-2, #b197fc));
  box-shadow: 0 3px 10px -2px var(--accent, #7048e8);
}
.credit-people {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.person {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.2rem 0.8rem 0.2rem 0.2rem;
  border-radius: 999px;
  font-size: clamp(0.85rem, 1.9vh, 0.95rem);
  font-weight: 600;
  color: #262b38;
  background: rgba(255, 255, 255, 0.72);
  border: 1px solid rgba(20, 20, 40, 0.07);
  box-shadow: 0 2px 8px -2px rgba(0, 0, 0, 0.18);
  backdrop-filter: blur(6px);
  transition:
    transform 0.15s ease,
    box-shadow 0.15s ease;
}
.person:hover {
  transform: translateY(-1px);
  box-shadow: 0 5px 14px -3px rgba(0, 0, 0, 0.25);
}
.avatar {
  display: grid;
  place-items: center;
  width: 1.85rem;
  height: 1.85rem;
  border-radius: 50%;
  font-size: 0.72rem;
  font-weight: 800;
  color: #fff;
  background: linear-gradient(135deg, var(--accent, #7048e8), var(--accent-2, #f76707));
  box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.9);
}

.tone-sale {
  --accent: #e8590c;
  --accent-2: #ff6ea9;
}
.tone-insite {
  --accent: #1c7ed6;
  --accent-2: #22b8cf;
}
.tone-other {
  --accent: #7048e8;
  --accent-2: #b197fc;
}

.actions {
  margin-top: clamp(0.9rem, 2.4vh, 1.6rem);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
@keyframes pulse {
  0%,
  100% {
    transform: scale(0.96);
    opacity: 0.75;
  }
  50% {
    transform: scale(1.05);
    opacity: 1;
  }
}
@keyframes pop {
  0% {
    transform: scale(0.85) translateY(10px);
    opacity: 0;
  }
  100% {
    transform: scale(1) translateY(0);
    opacity: 1;
  }
}
@keyframes bob {
  0%,
  100% {
    transform: translateY(0) rotate(-4deg);
  }
  50% {
    transform: translateY(-7px) rotate(4deg);
  }
}

.confetti {
  position: absolute;
  top: -6vh;
  border-radius: 2px;
  opacity: 0.95;
  animation-name: fall;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}
@keyframes fall {
  0% {
    transform: translateY(-6vh) rotate(0deg);
    opacity: 1;
  }
  100% {
    transform: translateY(112vh) rotate(720deg);
    opacity: 0.9;
  }
}

.celebrate-enter-active,
.celebrate-leave-active {
  transition: opacity 0.3s ease;
}
.celebrate-enter-from,
.celebrate-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .confetti,
  .rays,
  .glow,
  .card,
  .emoji {
    animation: none;
  }
}
</style>
