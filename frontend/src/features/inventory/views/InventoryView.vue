<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'

const auth = useAuthStore()

// Sub-navigation for the Inventory area. Units, Boxes and the Stacking plan are
// added as their Phase 2 slices ship; each tab is permission-gated.
const tabs = computed(() =>
  [
    { to: { name: 'inventory.locations' }, label: 'Projects', permission: 'units.view' },
    { to: { name: 'inventory.units' }, label: 'Units', permission: 'units.view' },
  ].filter((t) => !t.permission || auth.can(t.permission)),
)
</script>

<template>
  <div class="space-y-4">
    <nav class="flex flex-wrap gap-1 border-b border-border">
      <RouterLink
        v-for="tab in tabs"
        :key="tab.label"
        :to="tab.to"
        class="px-4 py-2 text-sm hover:text-primary"
        active-class="-mb-px border-b-2 border-primary font-medium text-primary"
      >
        {{ tab.label }}
      </RouterLink>
    </nav>
    <RouterView />
  </div>
</template>
