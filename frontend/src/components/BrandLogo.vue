<script setup>
import { computed } from 'vue'

/**
 * PLAZA PRO brand lockup: the building mark (logo.png) plus, optionally, the
 * "PLAZA PRO" wordmark. The mark is a single navy asset — on dark surfaces it is
 * flipped to white with a CSS filter, so one file serves both themes.
 *
 * The full raster logo (logo-full.png) is used directly on the login page where
 * there is room for the designed lockup; this component covers the compact app
 * chrome (sidebar, drawers, top bar) where a crisp, themeable wordmark reads best.
 */
const props = defineProps({
  // 'full' = mark + wordmark, 'mark' = icon only (collapsed rail, small spots).
  variant: { type: String, default: 'full' },
  // 'auto' = navy on light / white on dark. 'light' = always white (dark panels).
  tone: { type: String, default: 'auto' },
  // Optional second line under the wordmark, e.g. "Real-estate CRM".
  subtitle: { type: String, default: '' },
  // Tailwind sizing for the mark; height drives the whole lockup's scale.
  iconClass: { type: String, default: 'h-9 w-auto' },
})

// The navy mark is flipped to white on dark surfaces (see .brand-mark-* in
// tailwind.css). 'light' inverts always; 'auto' only in dark mode.
const markFilter = computed(() => (props.tone === 'light' ? 'brand-mark-invert' : 'brand-mark-auto'))
</script>

<template>
  <span class="inline-flex items-center gap-2.5">
    <img
      src="/logo.png"
      :class="[iconClass, markFilter]"
      class="shrink-0 select-none object-contain"
      :alt="variant === 'mark' ? 'PLAZA PRO' : ''"
      :aria-hidden="variant === 'full' ? 'true' : undefined"
      draggable="false"
    />
    <span v-if="variant === 'full'" class="min-w-0">
      <span
        class="block truncate text-[15px] font-bold leading-5 tracking-wide"
        :class="tone === 'light' ? 'text-white' : 'text-ink'"
      >
        PLAZA<span :class="tone === 'light' ? 'text-primary-400' : 'text-primary-600 dark:text-primary-400'">
          PRO</span>
      </span>
      <span
        v-if="subtitle"
        class="block truncate text-[11px] leading-4"
        :class="tone === 'light' ? 'text-surface-300' : 'text-mute'"
      >
        {{ subtitle }}
      </span>
    </span>
  </span>
</template>
