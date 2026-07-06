<script setup>
import { RouterView } from 'vue-router'
import SettingsNav from '@/features/settings/components/SettingsNav.vue'

// Settings shell: a section rail on the left (collapses to a top strip on
// mobile) and the active section on the right. Each section owns its own
// PageHeader, so the shell stays a thin frame.
</script>

<template>
  <div class="grid grid-cols-1 gap-5 md:grid-cols-[15rem_minmax(0,1fr)]">
    <aside class="md:sticky md:top-5 md:self-start">
      <SettingsNav />
    </aside>
    <main class="min-w-0">
      <RouterView />
    </main>
  </div>
</template>
