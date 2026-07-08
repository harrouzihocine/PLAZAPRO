<script setup>
// The "you left this unfinished" banner shown inside a form whose draft was
// restored. Informational only: a draft is never discarded by hand — it clears
// when the form is saved (or an oversight admin removes it).
defineProps({ visible: { type: Boolean, default: false } })
</script>

<template>
  <div
    v-if="visible"
    class="flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm dark:border-amber-500/40 dark:bg-amber-500/10"
  >
    <i class="pi pi-history text-amber-600 dark:text-amber-400" aria-hidden="true" />
    <span class="text-ink">{{ $t('drafts.restored') }}</span>
  </div>
</template>
