<script setup>
import { RouterLink } from 'vue-router'

// Standard page top: optional back link, title row with badges, subtitle and
// a right-aligned actions area. Keeps every page opening visually identical.
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: null },
  back: { type: [String, Object], default: null }, // route target for the back chevron
})
</script>

<template>
  <header class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
      <RouterLink
        v-if="back"
        :to="back"
        class="mb-1 inline-flex items-center gap-1 text-sm text-mute transition-colors hover:text-ink"
      >
        <i class="pi pi-arrow-left text-xs" aria-hidden="true" />
        <slot name="back-label">Back</slot>
      </RouterLink>

      <div class="flex flex-wrap items-center gap-2.5">
        <h1 class="truncate text-2xl font-semibold tracking-tight text-ink">{{ title }}</h1>
        <slot name="badges" />
      </div>

      <p v-if="subtitle || $slots.subtitle" class="mt-1 text-sm text-mute">
        <slot name="subtitle">{{ subtitle }}</slot>
      </p>
    </div>

    <div v-if="$slots.actions" class="flex shrink-0 flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>
