<script setup>
// The standard content card: hairline border, soft shadow, optional titled
// header with an actions area. `flush` drops body padding (tables, lists).
defineProps({
  title: { type: String, default: null },
  icon: { type: String, default: null },
  flush: { type: Boolean, default: false },
})
</script>

<template>
  <section class="overflow-hidden rounded-xl border border-line bg-card shadow-card">
    <header
      v-if="title || $slots.header || $slots.actions"
      class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3 sm:px-5"
    >
      <slot name="header">
        <h2 class="flex items-center gap-2 text-sm font-semibold text-ink">
          <i v-if="icon" :class="icon" class="text-mute" aria-hidden="true" />
          {{ title }}
        </h2>
      </slot>
      <div v-if="$slots.actions" class="flex items-center gap-2">
        <slot name="actions" />
      </div>
    </header>

    <div :class="flush ? '' : 'p-4 sm:p-5'">
      <slot />
    </div>
  </section>
</template>
