<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import PublicLightbox from './PublicLightbox.vue'

// Media showcase: one tab per public collection (photos / videos / plans),
// presented as a swipeable snap carousel (long galleries stopped being a
// scroll marathon — owner request 2026-07-12), full-screen lightbox on click.

const props = defineProps({
  media: { type: Array, required: true },
  // Compact mode drops the section header (used inside the unit page).
  compact: { type: Boolean, default: false },
})

const COLLECTIONS = ['photos', 'videos', 'plans']

const byCollection = computed(() => {
  const groups = {}
  for (const c of COLLECTIONS) {
    const items = props.media.filter((m) => m.collection === c)
    if (items.length) groups[c] = items
  }
  return groups
})

const active = ref(null)
const activeCollection = computed(() => active.value ?? Object.keys(byCollection.value)[0])
const items = computed(() => byCollection.value[activeCollection.value] ?? [])

const lightboxIndex = ref(null)

// ── Carousel mechanics ──────────────────────────────────────────────────────
// scrollIntoView(inline) + IntersectionObserver keep the arrows, counter and
// swipe in sync without any scrollLeft math (which flips sign in RTL).
const track = ref(null)
const slideEls = ref([])
const current = ref(0)
let observer = null

function observe() {
  observer?.disconnect()
  if (!track.value) return
  observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          current.value = Number(entry.target.dataset.index)
        }
      }
    },
    { root: track.value, threshold: 0.6 },
  )
  slideEls.value.forEach((el) => el && observer.observe(el))
}

watch(items, async () => {
  current.value = 0
  slideEls.value = []
  await nextTick()
  observe()
  track.value?.scrollTo({ left: 0 })
}, { immediate: true })

onBeforeUnmount(() => observer?.disconnect())

function goTo(index) {
  const el = slideEls.value[Math.max(0, Math.min(index, items.value.length - 1))]
  el?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' })
}

function duration(seconds) {
  if (!seconds) return null
  const m = Math.floor(seconds / 60)
  const s = String(Math.round(seconds % 60)).padStart(2, '0')
  return `${m}:${s}`
}
</script>

<template>
  <section v-if="Object.keys(byCollection).length" id="gallery" class="scroll-mt-20">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div v-if="!compact">
        <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
          {{ $t('showcase.gallery.kicker') }}
        </p>
        <h2 class="mt-2 text-2xl font-bold text-ink">{{ $t('showcase.gallery.title') }}</h2>
      </div>
      <h3 v-else class="text-lg font-semibold text-ink">{{ $t('showcase.gallery.title') }}</h3>

      <div v-if="Object.keys(byCollection).length > 1" class="flex gap-1 rounded-full border border-line bg-card p-1">
        <button
          v-for="(group, collection) in byCollection"
          :key="collection"
          type="button"
          class="rounded-full px-4 py-1.5 text-sm font-medium transition-colors"
          :class="collection === activeCollection ? 'bg-primary-500 text-primary-contrast' : 'text-mute hover:text-ink'"
          @click="active = collection"
        >
          {{ $t(`showcase.gallery.${collection}`) }}
          <span class="num ms-1 opacity-70">{{ group.length }}</span>
        </button>
      </div>
    </div>

    <div class="relative mt-6">
      <!-- Snap track -->
      <div
        ref="track"
        class="showcase-carousel flex snap-x snap-mandatory gap-3 overflow-x-auto scroll-smooth pb-2"
      >
        <button
          v-for="(item, i) in items"
          :key="item.id"
          :ref="(el) => (slideEls[i] = el)"
          :data-index="i"
          type="button"
          class="group relative aspect-[4/3] w-[82%] shrink-0 snap-center overflow-hidden rounded-2xl bg-surface-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 sm:w-[55%] lg:w-[42%] dark:bg-surface-800"
          @click="lightboxIndex = i"
        >
          <img
            v-if="item.thumb_url"
            :src="item.thumb_url"
            alt=""
            class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
            :loading="i < 3 ? 'eager' : 'lazy'"
          />
          <span v-else class="flex h-full w-full items-center justify-center text-surface-400">
            <i class="pi pi-image text-3xl" aria-hidden="true" />
          </span>

          <!-- Video affordances -->
          <span
            v-if="item.type === 'video'"
            class="absolute inset-0 flex items-center justify-center bg-black/25 transition-colors group-hover:bg-black/40"
          >
            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white/90 text-surface-900 shadow-pop">
              <i class="pi pi-play ms-0.5" aria-hidden="true" />
            </span>
          </span>
          <span
            v-if="item.type === 'video' && duration(item.duration_seconds)"
            class="num absolute bottom-2 end-2 rounded bg-black/70 px-1.5 py-0.5 text-xs text-white"
          >{{ duration(item.duration_seconds) }}</span>

          <!-- Expand hint -->
          <span
            class="absolute end-2 top-2 flex h-8 w-8 items-center justify-center rounded-full bg-black/40 text-white opacity-0 transition-opacity group-hover:opacity-100"
            aria-hidden="true"
          ><i class="pi pi-expand text-sm" /></span>
        </button>
      </div>

      <!-- Arrows (desktop; swipe carries mobile) -->
      <template v-if="items.length > 1">
        <button
          type="button"
          class="absolute start-2 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-card/90 text-ink shadow-pop backdrop-blur transition-opacity hover:bg-card sm:flex"
          :class="current === 0 ? 'pointer-events-none opacity-30' : ''"
          :aria-label="$t('showcase.gallery.prev')"
          @click="goTo(current - 1)"
        >
          <i class="pi pi-chevron-left rtl:rotate-180" aria-hidden="true" />
        </button>
        <button
          type="button"
          class="absolute end-2 top-1/2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-card/90 text-ink shadow-pop backdrop-blur transition-opacity hover:bg-card sm:flex"
          :class="current >= items.length - 1 ? 'pointer-events-none opacity-30' : ''"
          :aria-label="$t('showcase.gallery.next')"
          @click="goTo(current + 1)"
        >
          <i class="pi pi-chevron-right rtl:rotate-180" aria-hidden="true" />
        </button>

        <!-- Position counter -->
        <span class="num absolute bottom-5 end-4 rounded-full bg-black/60 px-2.5 py-1 text-xs font-medium text-white">
          {{ current + 1 }} / {{ items.length }}
        </span>
      </template>
    </div>

    <PublicLightbox
      v-if="lightboxIndex !== null"
      :items="items"
      :start="lightboxIndex"
      @close="lightboxIndex = null"
    />
  </section>
</template>

<style scoped>
/* The snap track hides its scrollbar — arrows + swipe are the affordance. */
.showcase-carousel {
  scrollbar-width: none;
}
.showcase-carousel::-webkit-scrollbar {
  display: none;
}
</style>
