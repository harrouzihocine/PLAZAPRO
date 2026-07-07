<script setup>
import { ref } from 'vue'
import { useIsPhone } from '@/composables/useNativeMode'

// Phone-only collapsible wrapper for a page's filter controls. On a phone the
// filters open COLLAPSED behind one "Filters" row (with a count of the active
// ones), so every list page starts with content instead of a wall of selects;
// on ≥ tablet the slot renders exactly as before. Purely presentational — the
// page keeps owning its filter state and passes how many are active.
//
// Single root div on purpose: parents pass layout classes (mb-4 …) and Vue
// only auto-inherits attrs onto a single root.
defineProps({
  // Number of filters currently applied (badge on the toggle). null hides it.
  activeCount: { type: Number, default: null },
  label: { type: String, default: 'Filters' },
  // Standalone filter bars (not already inside a SectionCard) get card chrome.
  card: { type: Boolean, default: false },
})

const isPhone = useIsPhone()
const open = ref(false)
</script>

<template>
  <div
    :class="isPhone && card && 'overflow-hidden rounded-xl border border-line bg-card shadow-card'"
  >
    <template v-if="!isPhone">
      <slot />
    </template>

    <template v-else>
      <button
        type="button"
        data-filter-toggle
        class="flex min-h-[44px] w-full items-center gap-2 px-4 py-2.5 text-sm font-medium text-ink"
        :aria-expanded="open"
        @click="open = !open"
      >
        <i class="pi pi-sliders-h text-mute" aria-hidden="true" />
        {{ label }}
        <span
          v-if="activeCount"
          class="num rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold leading-none text-primary-contrast"
        >
          {{ activeCount }}
        </span>
        <i
          class="pi pi-chevron-down ml-auto text-xs text-mute transition-transform"
          :class="open && 'rotate-180'"
          aria-hidden="true"
        />
      </button>

      <div v-show="open" class="border-t border-line">
        <slot />
      </div>
    </template>
  </div>
</template>
