<script setup>
import { computed, ref } from 'vue'
import PublicLightbox from './PublicLightbox.vue'

// Media showcase: one tab per public collection (photos / videos / plans),
// WebP thumbs from the pipeline, full-screen lightbox on click.

const props = defineProps({
  media: { type: Array, required: true },
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
      <div>
        <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
          {{ $t('showcase.gallery.kicker') }}
        </p>
        <h2 class="mt-2 text-2xl font-bold text-ink">{{ $t('showcase.gallery.title') }}</h2>
      </div>

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

    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      <button
        v-for="(item, i) in items"
        :key="item.id"
        type="button"
        class="group relative overflow-hidden rounded-xl bg-surface-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-surface-800"
        :class="i === 0 ? 'col-span-2 row-span-2 aspect-square sm:aspect-[4/3]' : 'aspect-[4/3]'"
        @click="lightboxIndex = i"
      >
        <img
          v-if="item.thumb_url"
          :src="item.thumb_url"
          alt=""
          class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
          loading="lazy"
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
      </button>
    </div>

    <PublicLightbox
      v-if="lightboxIndex !== null"
      :items="items"
      :start="lightboxIndex"
      @close="lightboxIndex = null"
    />
  </section>
</template>
