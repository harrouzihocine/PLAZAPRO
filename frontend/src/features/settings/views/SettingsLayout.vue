<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'

const auth = useAuthStore()

// Sub-navigation for the Settings area. Each tab is gated by a permission so a
// user only sees the screens they can use.
const tabs = computed(() =>
  [
    {
      to: { name: 'settings.lists' },
      label: 'Lists',
      icon: 'pi pi-list',
      permission: 'settings.manage',
    },
    {
      to: { name: 'settings.wilayas' },
      label: 'Wilayas & Communes',
      icon: 'pi pi-map',
      permission: 'settings.manage',
    },
    {
      to: { name: 'settings.departments' },
      label: 'Departments',
      icon: 'pi pi-sitemap',
      permission: 'settings.manage',
    },
    {
      to: { name: 'settings.roles' },
      label: 'Roles',
      icon: 'pi pi-shield',
      permission: 'roles.manage',
    },
    {
      to: { name: 'settings.users' },
      label: 'Users',
      icon: 'pi pi-users',
      permission: 'users.manage',
    },
  ].filter((t) => !t.permission || auth.can(t.permission)),
)
</script>

<template>
  <div>
    <nav
      class="mb-5 flex max-w-full flex-wrap gap-1 overflow-x-auto rounded-lg border border-line bg-card p-1 shadow-card sm:inline-flex sm:flex-nowrap"
    >
      <RouterLink
        v-for="tab in tabs"
        :key="tab.label"
        :to="tab.to"
        class="flex shrink-0 items-center gap-2 rounded-md px-3.5 py-2 text-sm text-mute transition-colors hover:text-ink"
        active-class="!bg-highlight font-semibold !text-ink"
      >
        <i :class="tab.icon" aria-hidden="true" />
        {{ tab.label }}
      </RouterLink>
    </nav>
    <RouterView />
  </div>
</template>
