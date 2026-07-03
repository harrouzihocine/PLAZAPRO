<script setup>
import { computed } from 'vue'

// A real SVG country flag (flag-icons), keyed by ISO 3166-1 alpha-2 code.
// Used instead of emoji flags, which don't render on Windows (they fall back
// to the two-letter code, e.g. "DZ"). The CSS ships with the app — no CDN.
const props = defineProps({
  // ISO code, any case (e.g. "DZ" / "dz").
  iso: { type: String, default: '' },
})

const code = computed(() => props.iso.trim().toLowerCase())
</script>

<template>
  <!-- `fi`/`fi-xx` come from flag-icons (background-image + 4:3 sizing); the rest
       just rounds the corners and adds a hairline so the flag reads as a chip. -->
  <span
    v-if="code"
    :class="`fi fi-${code}`"
    class="shrink-0 rounded-[2px] align-middle shadow-[inset_0_0_0_1px_rgba(0,0,0,0.12)]"
    role="img"
    :aria-label="code"
  />
</template>
