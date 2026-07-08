<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'
import { t } from '@/i18n'

const auth = useAuthStore()
const route = useRoute()

// Sub-navigation for the Inventory area; each tab is permission-gated. Tabs
// hide on detail pages (they have their own back navigation).
const tabs = computed(() =>
  [
    {
      to: { name: 'inventory.locations' },
      label: t('inventory.projects'),
      icon: 'pi pi-building',
      permission: 'units.view',
    },
    {
      to: { name: 'inventory.units' },
      label: t('nav.units'),
      icon: 'pi pi-th-large',
      permission: 'units.view',
    },
  ].filter((t) => !t.permission || auth.can(t.permission)),
)

const showTabs = computed(() => ['inventory.locations', 'inventory.units'].includes(route.name))
</script>

<template>
  <div>
    <nav
      v-if="showTabs"
      class="mb-5 inline-flex rounded-lg border border-line bg-card p-1 shadow-card"
    >
      <RouterLink
        v-for="tab in tabs"
        :key="tab.label"
        :to="tab.to"
        class="flex items-center gap-2 rounded-md px-4 py-2 text-sm text-mute transition-colors hover:text-ink"
        active-class="!bg-highlight font-semibold !text-ink"
      >
        <i :class="tab.icon" aria-hidden="true" />
        {{ tab.label }}
      </RouterLink>
    </nav>
    <RouterView />
  </div>
</template>
