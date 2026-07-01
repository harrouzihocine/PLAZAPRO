<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/features/settings/store'
import NotificationBell from '@/features/collaboration/components/NotificationBell.vue'

const { isNight, toggle } = useTheme()
const auth = useAuthStore()
const router = useRouter()

// Nav is permission-filtered: entries with a `permission` only show to users who
// hold it (the Settings admin is gated by settings.manage).
const nav = computed(() =>
  [
    { to: '/', label: 'Dashboard', icon: '▦' },
    { to: '/inventory', label: 'Inventory', icon: '▢' },
    { to: '/clients', label: 'Clients', icon: '☺', permission: 'clients.view' },
    { to: '/tasks', label: 'Tasks', icon: '✓', permission: 'tasks.manage' },
    { to: '/payments', label: 'Payments', icon: '$', permission: 'versements.view' },
    { to: '/settings', label: 'Settings', icon: '⚙', permission: 'settings.manage' },
  ].filter((i) => !i.permission || auth.can(i.permission)),
)

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-bg text-ink">
    <!-- Top bar -->
    <header class="flex h-14 items-center justify-between border-b border-border bg-surface px-4">
      <span class="font-semibold text-primary">PLAZA PRO</span>
      <div class="flex items-center gap-3">
        <NotificationBell v-if="auth.can('notifications.view')" />
        <button
          class="min-h-[44px] min-w-[44px]"
          :aria-label="isNight ? 'Switch to day theme' : 'Switch to night theme'"
          @click="toggle"
        >
          {{ isNight ? '☀' : '☾' }}
        </button>
        <button class="text-sm" @click="logout">Logout</button>
      </div>
    </header>

    <div class="md:flex">
      <!-- Sidebar: desktop only -->
      <aside
        class="hidden min-h-[calc(100vh-3.5rem)] w-60 border-r border-border bg-surface p-3 md:block"
      >
        <nav class="flex flex-col gap-1">
          <RouterLink
            v-for="item in nav"
            :key="item.to"
            :to="item.to"
            class="rounded-token px-3 py-2 hover:bg-bg"
            active-class="bg-bg text-primary"
          >
            {{ item.label }}
          </RouterLink>
        </nav>
      </aside>

      <!-- Routed content -->
      <main class="flex-1 p-4 pb-24 md:pb-4">
        <RouterView />
      </main>
    </div>

    <!-- Bottom nav: mobile only -->
    <nav
      class="fixed inset-x-0 bottom-0 flex h-16 items-center justify-around border-t border-border bg-surface md:hidden"
    >
      <RouterLink
        v-for="item in nav"
        :key="item.to"
        :to="item.to"
        class="flex min-h-[44px] min-w-[44px] flex-col items-center justify-center text-xs"
        active-class="text-primary"
      >
        <span aria-hidden="true">{{ item.icon }}</span>
        {{ item.label }}
      </RouterLink>
    </nav>
  </div>
</template>
