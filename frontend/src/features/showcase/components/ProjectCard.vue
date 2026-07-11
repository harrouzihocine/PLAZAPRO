<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { formatMoney } from '@/features/payments/money'
import { formatDate } from '@/utils/format'
import { pickLocalized } from '../localized'

const props = defineProps({
  project: { type: Object, required: true },
})

const tagline = computed(() => pickLocalized(props.project.tagline))

const coverStyle = computed(() => {
  const cover = props.project.cover
  if (!cover) return null
  return { objectPosition: `${cover.focus_x}% ${cover.focus_y}%` }
})
</script>

<template>
  <RouterLink
    :to="{ name: 'showcase.project', params: { id: project.id } }"
    class="group block overflow-hidden rounded-2xl border border-line bg-card shadow-card transition-all duration-300 hover:-translate-y-1 hover:shadow-pop"
  >
    <div class="relative aspect-[4/3] overflow-hidden bg-surface-200 dark:bg-surface-800">
      <img
        v-if="project.cover"
        :src="project.cover.thumb_url"
        :alt="project.name"
        :style="coverStyle"
        class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
        loading="lazy"
      />
      <div v-else class="flex h-full w-full items-center justify-center">
        <i class="pi pi-building text-5xl text-surface-400" aria-hidden="true" />
      </div>

      <div class="absolute inset-x-0 bottom-0 h-24 bg-gradient-to-t from-black/70 to-transparent" aria-hidden="true" />

      <span
        v-if="project.type"
        class="absolute start-3 top-3 rounded-full bg-black/50 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm"
      >{{ project.type }}</span>

      <span
        v-if="project.show_availability && project.available_count > 0"
        class="absolute end-3 top-3 rounded-full bg-primary-500 px-3 py-1 text-xs font-semibold text-primary-contrast"
      >{{ $t('showcase.projects.available', { n: project.available_count }) }}</span>

      <div class="absolute inset-x-0 bottom-0 p-4">
        <h3 class="text-lg font-semibold text-white">{{ project.name }}</h3>
        <p v-if="project.wilaya" class="mt-0.5 flex items-center gap-1.5 text-sm text-white/80">
          <i class="pi pi-map-marker text-xs" aria-hidden="true" />
          {{ [project.commune, project.wilaya].filter(Boolean).join(', ') }}
        </p>
      </div>
    </div>

    <div class="p-4">
      <p v-if="tagline" class="line-clamp-2 text-sm leading-relaxed text-mute">{{ tagline }}</p>

      <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
        <span v-if="project.show_prices && project.price_from" class="text-sm font-semibold text-primary-600 dark:text-primary-400">
          {{ $t('showcase.projects.from') }} <span class="num ltr-data">{{ formatMoney(project.price_from) }}</span>
        </span>
        <span v-else class="text-sm font-medium text-mute">{{ $t('showcase.projects.priceOnRequest') }}</span>

        <span v-if="project.expected_delivery_date" class="flex items-center gap-1.5 text-xs text-mute">
          <i class="pi pi-calendar" aria-hidden="true" />
          {{ $t('showcase.projects.delivery') }} {{ formatDate(project.expected_delivery_date) }}
        </span>
      </div>

      <!-- Construction advancement (only when the owner publishes it) -->
      <div v-if="project.construction_progress !== null && project.construction_progress !== undefined" class="mt-3">
        <div class="flex items-center justify-between text-xs">
          <span class="text-mute">{{ $t('showcase.project.progress') }}</span>
          <span class="num font-semibold text-primary-600 dark:text-primary-400">{{ project.construction_progress }}%</span>
        </div>
        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surface-200 dark:bg-surface-800">
          <div class="h-full rounded-full bg-primary-500" :style="{ width: `${project.construction_progress}%` }" />
        </div>
      </div>
    </div>
  </RouterLink>
</template>
