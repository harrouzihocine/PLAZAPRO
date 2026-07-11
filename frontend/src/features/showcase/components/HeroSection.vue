<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import { useShowcaseStore } from '../store'

// Full-viewport landing hero. Uses the first published project's cover as the
// backdrop (the owner curates covers anyway); a branded gradient carries the
// page when nothing is published yet. A <video> slot is the Phase-2 hook.

const props = defineProps({
  projects: { type: Array, default: () => [] },
})

const showcase = useShowcaseStore()

const backdrop = computed(() => props.projects.find((p) => p.cover)?.cover ?? null)
// The owner-picked hero backdrop — photo or video (config re-validates it is
// publicly streamable before emitting URLs).
const hero = computed(() => showcase.config?.hero ?? null)
const stats = computed(() => showcase.stats)

const statItems = computed(() => {
  if (!stats.value) return []
  return [
    { value: stats.value.projects, key: 'showcase.hero.statProjects' },
    { value: stats.value.available_units, key: 'showcase.hero.statUnits' },
    { value: stats.value.wilayas, key: 'showcase.hero.statWilayas' },
  ].filter((s) => s.value > 0)
})
</script>

<template>
  <section class="relative flex min-h-[92vh] items-center justify-center overflow-hidden bg-surface-950">
    <!-- Cinematic backdrop: owner's pick (video or photo) > best cover > gradient -->
    <video
      v-if="hero?.type === 'video'"
      :src="hero.video_url"
      :poster="hero.poster_url ?? backdrop?.file_url ?? undefined"
      autoplay
      muted
      loop
      playsinline
      class="absolute inset-0 h-full w-full object-cover"
    />
    <img
      v-else-if="hero?.type === 'photo'"
      :src="hero.image_url"
      alt=""
      class="absolute inset-0 h-full w-full object-cover"
    />
    <img
      v-else-if="backdrop"
      :src="backdrop.file_url"
      :style="{ objectPosition: `${backdrop.focus_x}% ${backdrop.focus_y}%` }"
      alt=""
      class="absolute inset-0 h-full w-full object-cover"
    />
    <div
      v-else
      class="absolute inset-0 bg-gradient-to-br from-surface-950 via-surface-900 to-primary-950"
      aria-hidden="true"
    />
    <!-- Cinematic scrim: readable type on any photo. -->
    <div class="absolute inset-0 bg-gradient-to-b from-black/60 via-black/40 to-black/70" aria-hidden="true" />

    <div class="relative mx-auto max-w-4xl px-6 py-28 text-center">
      <p class="text-sm font-semibold uppercase tracking-[0.25em] text-primary-400">
        {{ showcase.company.name || 'PLAZA PRO' }}
      </p>
      <h1 class="mt-4 text-4xl font-bold leading-tight text-white sm:text-5xl lg:text-6xl">
        {{ $t('showcase.hero.title') }}
      </h1>
      <p class="mx-auto mt-5 max-w-2xl text-base leading-relaxed text-white/80 sm:text-lg">
        {{ $t('showcase.hero.subtitle') }}
      </p>

      <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
        <RouterLink :to="{ name: 'showcase.projects' }">
          <Button :label="$t('showcase.hero.ctaProjects')" icon="pi pi-arrow-right" icon-pos="right" size="large" rounded />
        </RouterLink>
        <a href="#contact">
          <Button :label="$t('showcase.hero.ctaContact')" severity="secondary" outlined size="large" rounded class="!border-white/40 !text-white hover:!bg-white/10" />
        </a>
      </div>

      <dl v-if="statItems.length" class="mx-auto mt-14 flex max-w-xl items-stretch justify-center divide-x divide-white/20 rtl:divide-x-reverse">
        <div v-for="item in statItems" :key="item.key" class="flex-1 px-6">
          <dt class="sr-only">{{ $t(item.key) }}</dt>
          <dd class="num text-3xl font-bold text-white">{{ item.value }}</dd>
          <dd class="mt-1 text-xs font-medium uppercase tracking-wider text-white/70">{{ $t(item.key) }}</dd>
        </div>
      </dl>
    </div>

    <!-- Scroll cue -->
    <a
      href="#featured"
      class="absolute bottom-6 left-1/2 -translate-x-1/2 text-white/70 transition-colors hover:text-white"
      :aria-label="$t('showcase.hero.scroll')"
    >
      <i class="pi pi-chevron-down animate-bounce text-xl" aria-hidden="true" />
    </a>
  </section>
</template>
