<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'

const auth = useAuthStore()

// Grouped section rail for the Settings area (Stripe/Linear pattern). Each item
// is gated by a permission so a user only sees the screens they can use; a whole
// group hides when it has no visible items. On small screens the rail collapses
// to a horizontal, scrollable strip (group labels drop out).
const groups = computed(() =>
  [
    {
      label: 'Workspace',
      items: [
        { to: { name: 'settings.general' }, label: 'General', icon: 'pi pi-cog', permission: 'settings.manage' },
        { to: { name: 'settings.lists' }, label: 'Lists', icon: 'pi pi-list', permission: 'settings.manage' },
        { to: { name: 'settings.wilayas' }, label: 'Geography', icon: 'pi pi-map', permission: 'settings.manage' },
        { to: { name: 'settings.departments' }, label: 'Departments', icon: 'pi pi-sitemap', permission: 'settings.manage' },
      ],
    },
    {
      label: 'Access',
      items: [
        { to: { name: 'settings.roles' }, label: 'Roles', icon: 'pi pi-shield', permission: 'roles.manage' },
        { to: { name: 'settings.users' }, label: 'Users', icon: 'pi pi-users', permission: 'users.manage' },
      ],
    },
  ]
    .map((g) => ({ ...g, items: g.items.filter((i) => !i.permission || auth.can(i.permission)) }))
    .filter((g) => g.items.length),
)
</script>

<template>
  <nav
    class="flex gap-1 overflow-x-auto rounded-xl border border-line bg-card p-2 shadow-card md:flex-col md:gap-0.5"
    aria-label="Settings sections"
  >
    <template v-for="(group, gi) in groups" :key="group.label">
      <p
        class="hidden shrink-0 px-3 pb-1 pt-2 text-[0.68rem] font-semibold uppercase tracking-wider text-mute md:block"
        :class="{ 'md:!pt-1': gi === 0 }"
      >
        {{ group.label }}
      </p>
      <RouterLink
        v-for="item in group.items"
        :key="item.label"
        :to="item.to"
        class="group flex shrink-0 items-center gap-3 rounded-lg px-3 py-2 text-sm text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
        active-class="!bg-highlight font-semibold !text-ink"
      >
        <i
          :class="item.icon"
          class="text-base text-mute transition-colors group-hover:text-ink group-[.router-link-active]:text-primary"
          aria-hidden="true"
        />
        <span class="whitespace-nowrap">{{ item.label }}</span>
      </RouterLink>
    </template>
  </nav>
</template>
