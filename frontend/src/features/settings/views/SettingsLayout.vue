<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'

const auth = useAuthStore()

// Sub-navigation for the Settings area. Each tab is gated by a permission so a
// user only sees the screens they can use.
const tabs = computed(() =>
  [
    { to: { name: 'settings.lists' }, label: 'Lists', permission: 'settings.manage' },
    { to: { name: 'settings.departments' }, label: 'Departments', permission: 'settings.manage' },
    { to: { name: 'settings.roles' }, label: 'Roles', permission: 'roles.manage' },
    { to: { name: 'settings.users' }, label: 'Users', permission: 'users.manage' },
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
