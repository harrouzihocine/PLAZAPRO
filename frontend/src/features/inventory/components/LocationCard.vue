<script setup>
// A development (project) card for the grid: a cover-image header with the GTM
// badge overlaid, then name/code and the key facts, plus a hover action row.
// Pure presentation — the parent owns the data and the edit/archive/remove verbs.
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import { mediaFileUrl } from '@/features/inventory/api'
import { formatDate } from '@/utils/format'

defineProps({
  loc: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
})
defineEmits(['edit', 'archive', 'remove'])
</script>

<template>
  <div
    class="group relative flex flex-col overflow-hidden rounded-2xl border border-line bg-card shadow-card transition-all hover:border-primary-300 hover:shadow-pop"
  >
    <!-- Cover -->
    <div class="relative h-36 overflow-hidden bg-ground">
      <img
        v-if="loc.cover_media_id"
        :src="mediaFileUrl(loc.cover_media_id)"
        :alt="loc.name"
        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
        :style="{ objectPosition: `${loc.cover_focus_x ?? 50}% ${loc.cover_focus_y ?? 50}%` }"
      />
      <div
        v-else
        class="flex h-full w-full items-center justify-center bg-gradient-to-br from-primary-100 to-primary-50 dark:from-primary-950 dark:to-highlight"
      >
        <i class="pi pi-building text-4xl text-primary-400/70" aria-hidden="true" />
      </div>
      <!-- Scrim for legibility + overlaid priority -->
      <div class="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/40 to-transparent" />
      <GtmPriorityBadge
        v-if="loc.gtm_priority"
        :priority="loc.gtm_priority"
        class="absolute end-2 top-2"
      />
      <!-- Live on the public showcase -->
      <span
        v-if="loc.is_published"
        class="absolute start-2 top-2 flex items-center gap-1 rounded-full bg-black/50 px-2 py-0.5 text-[11px] font-medium text-white backdrop-blur-sm"
      >
        <i class="pi pi-globe text-[10px]" aria-hidden="true" />
        {{ $t('inventory.websitePublished') }}
      </span>
    </div>

    <div class="flex flex-1 flex-col p-4">
      <RouterLink
        :to="{ name: 'inventory.location', params: { id: loc.id } }"
        class="min-w-0 after:absolute after:inset-0"
      >
        <span class="block truncate font-semibold text-ink group-hover:underline">{{ loc.name }}</span>
        <span class="num mt-0.5 block text-xs text-mute">{{ loc.code }}</span>
      </RouterLink>

      <dl class="mt-3 space-y-1.5 text-sm">
        <div v-if="loc.wilaya" class="flex items-center gap-2 text-mute">
          <i class="pi pi-map-marker text-xs" aria-hidden="true" />
          <span class="truncate">
            {{ loc.wilaya.name }}<template v-if="loc.commune"> · {{ loc.commune.name }}</template>
          </span>
        </div>
        <div v-if="loc.contract_type" class="flex items-center gap-2 text-mute">
          <i class="pi pi-file text-xs" aria-hidden="true" />
          <span class="truncate">{{ loc.contract_type }}</span>
        </div>
        <div v-if="loc.expected_delivery_date" class="flex items-center gap-2 text-mute">
          <i class="pi pi-flag text-xs" aria-hidden="true" />
          <span>Delivery {{ formatDate(loc.expected_delivery_date) }}</span>
        </div>
      </dl>

      <div
        v-if="canManage"
        class="relative z-10 mt-auto flex justify-end gap-1 border-t border-line pt-2.5 opacity-0 transition-opacity focus-within:opacity-100 group-hover:opacity-100 [@media(hover:none)]:opacity-100"
      >
        <Button
          icon="pi pi-pencil"
          text
          rounded
          size="small"
          severity="secondary"
          :aria-label="$t('inventory.editProject')"
          @click.prevent="$emit('edit', loc)"
        />
        <Button
          icon="pi pi-inbox"
          text
          rounded
          size="small"
          severity="secondary"
          :aria-label="$t('inventory.archiveProject')"
          @click.prevent="$emit('archive', loc)"
        />
        <Button
          icon="pi pi-trash"
          text
          rounded
          size="small"
          severity="danger"
          :aria-label="$t('inventory.removeProject')"
          @click.prevent="$emit('remove', loc)"
        />
      </div>
      <div v-else class="mt-2" />
    </div>
  </div>
</template>
