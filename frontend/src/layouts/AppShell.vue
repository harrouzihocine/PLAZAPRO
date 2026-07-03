<script setup>
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import Popover from 'primevue/popover'
import Tag from 'primevue/tag'
import { useTheme } from '@/composables/useTheme'
import { useAuthStore } from '@/features/settings/store'
import { initials } from '@/utils/format'
import NotificationBell from '@/features/collaboration/components/NotificationBell.vue'
import GlobalSearch from '@/components/shell/GlobalSearch.vue'

const { isNight, toggle } = useTheme()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const search = ref(null)
const userPanel = ref(null)
const mobileNav = ref(false)

// Collapsed icon-rail preference survives reloads.
const collapsed = ref(localStorage.getItem('plaza-nav-collapsed') === '1')
function toggleCollapsed() {
  collapsed.value = !collapsed.value
  localStorage.setItem('plaza-nav-collapsed', collapsed.value ? '1' : '0')
}

// Nav is permission-filtered; sections with no visible item disappear.
const SECTIONS = [
  {
    label: 'Overview',
    items: [{ to: '/', label: 'Dashboard', icon: 'pi pi-home' }],
  },
  {
    label: 'Sales',
    items: [
      { to: '/clients', label: 'Clients', icon: 'pi pi-users', permission: 'clients.view' },
      { to: '/desires/matches', label: 'Matches', icon: 'pi pi-heart', permission: 'clients.view' },
      { to: '/tasks', label: 'Tasks', icon: 'pi pi-check-square', permission: 'tasks.manage' },
      { to: '/dispatch', label: 'Dispatch', icon: 'pi pi-send', permission: 'visits.dispatch' },
      { to: '/chat', label: 'Chat', icon: 'pi pi-comments', permission: 'chat.use' },
    ],
  },
  {
    label: 'Inventory',
    items: [
      {
        to: '/inventory/locations',
        label: 'Locations',
        icon: 'pi pi-building',
        permission: 'units.view',
      },
      { to: '/inventory/units', label: 'Units', icon: 'pi pi-th-large', permission: 'units.view' },
    ],
  },
  {
    label: 'Finance',
    items: [
      { to: '/payments', label: 'Payments', icon: 'pi pi-wallet', permission: 'versements.view' },
    ],
  },
  {
    label: 'Insights',
    items: [
      { to: '/analytics', label: 'Reports', icon: 'pi pi-chart-line', permission: 'reports.view' },
      { to: '/audit', label: 'Audit', icon: 'pi pi-shield', permission: 'audit.view' },
    ],
  },
  {
    label: 'System',
    items: [
      { to: '/settings', label: 'Settings', icon: 'pi pi-cog', permission: 'settings.manage' },
    ],
  },
]

const sections = computed(() =>
  SECTIONS.map((s) => ({
    ...s,
    items: s.items.filter((i) => !i.permission || auth.can(i.permission)),
  })).filter((s) => s.items.length > 0),
)

function isActive(to) {
  if (to === '/') return route.path === '/'
  return route.path === to || route.path.startsWith(to + '/')
}

// Mobile bottom bar: the four most-used destinations + "More".
const bottomNav = computed(() => {
  const flat = sections.value.flatMap((s) => s.items)
  const order = ['/', '/clients', '/inventory/locations', '/tasks']
  return order.map((to) => flat.find((i) => i.to === to)).filter(Boolean)
})

async function logout() {
  userPanel.value?.hide()
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-ground text-ink">
    <!-- ══ Desktop sidebar ══ -->
    <aside
      class="fixed inset-y-0 left-0 z-30 hidden flex-col border-r border-line bg-card transition-[width] duration-200 lg:flex"
      :class="collapsed ? 'w-[76px]' : 'w-64'"
    >
      <!-- Brand -->
      <RouterLink
        to="/"
        class="flex h-16 shrink-0 items-center gap-3 border-b border-line px-4"
        :class="collapsed && 'justify-center px-0'"
      >
        <span
          class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary text-lg font-bold text-primary-contrast"
        >
          P
        </span>
        <span v-if="!collapsed" class="truncate">
          <span class="block text-[15px] font-bold leading-5 tracking-wide text-ink">
            PLAZA<span class="text-primary-600 dark:text-primary-400"> PRO</span>
          </span>
          <span class="block text-[11px] leading-4 text-mute">Real-estate CRM</span>
        </span>
      </RouterLink>

      <!-- Nav -->
      <nav class="flex-1 overflow-y-auto px-3 py-4">
        <div v-for="section in sections" :key="section.label" class="mb-5">
          <p
            v-if="!collapsed"
            class="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-mute"
          >
            {{ section.label }}
          </p>
          <div class="space-y-0.5">
            <RouterLink
              v-for="item in section.items"
              :key="item.to"
              v-tooltip.right="collapsed ? item.label : null"
              :to="item.to"
              class="group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors"
              :class="
                isActive(item.to)
                  ? 'bg-highlight font-semibold text-ink'
                  : 'text-mute hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800'
              "
            >
              <span
                v-if="isActive(item.to)"
                class="absolute inset-y-2 left-0 w-[3px] rounded-full bg-primary"
                aria-hidden="true"
              />
              <i
                :class="[
                  item.icon,
                  isActive(item.to) ? 'text-primary-600 dark:text-primary-400' : '',
                ]"
                class="w-5 text-center"
                aria-hidden="true"
              />
              <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
            </RouterLink>
          </div>
        </div>
      </nav>

      <!-- Collapse toggle -->
      <div class="border-t border-line p-3">
        <Button
          :icon="collapsed ? 'pi pi-angle-double-right' : 'pi pi-angle-double-left'"
          :label="collapsed ? null : 'Collapse'"
          text
          severity="secondary"
          size="small"
          class="w-full"
          :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
          @click="toggleCollapsed"
        />
      </div>
    </aside>

    <!-- ══ Main column ══ -->
    <div
      class="flex min-h-screen flex-col transition-[padding] duration-200"
      :class="collapsed ? 'lg:pl-[76px]' : 'lg:pl-64'"
    >
      <!-- Topbar -->
      <header
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-line bg-card/85 px-3 backdrop-blur sm:px-5"
      >
        <Button
          icon="pi pi-bars"
          text
          rounded
          severity="secondary"
          class="lg:!hidden"
          aria-label="Open menu"
          @click="mobileNav = true"
        />

        <RouterLink to="/" class="flex items-center gap-2 lg:hidden">
          <span
            class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary font-bold text-primary-contrast"
          >
            P
          </span>
        </RouterLink>

        <!-- Search trigger -->
        <button
          type="button"
          class="ml-auto flex h-10 w-10 items-center justify-center rounded-lg text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800 sm:ml-2 sm:w-72 sm:justify-start sm:gap-2 sm:border sm:border-line sm:bg-surface-50 sm:px-3 dark:sm:bg-surface-900"
          aria-label="Search"
          @click="search.show()"
        >
          <i class="pi pi-search text-sm" aria-hidden="true" />
          <span class="hidden flex-1 text-left text-sm sm:block">Search…</span>
          <kbd
            class="hidden rounded border border-line bg-card px-1.5 py-0.5 text-[10px] text-mute sm:block"
          >
            Ctrl K
          </kbd>
        </button>

        <div class="flex items-center gap-1 sm:ml-2">
          <Button
            :icon="isNight ? 'pi pi-sun' : 'pi pi-moon'"
            text
            rounded
            severity="secondary"
            :aria-label="isNight ? 'Switch to day theme' : 'Switch to night theme'"
            @click="toggle"
          />
          <NotificationBell v-if="auth.can('notifications.view')" />

          <!-- User menu -->
          <button
            type="button"
            class="ml-1 flex items-center gap-2 rounded-full transition-opacity hover:opacity-80"
            aria-label="Account menu"
            @click="userPanel.toggle($event)"
          >
            <Avatar
              :label="initials(auth.user?.name)"
              shape="circle"
              class="!bg-primary !text-primary-contrast"
            />
          </button>
          <Popover ref="userPanel" class="w-64">
            <div class="px-1 py-1">
              <p class="truncate text-sm font-semibold text-ink">{{ auth.user?.name }}</p>
              <p class="truncate text-xs text-mute">{{ auth.user?.email }}</p>
              <Tag
                v-if="auth.user?.role?.name"
                :value="auth.user.role.name"
                severity="secondary"
                class="mt-2"
              />
              <div class="mt-3 border-t border-line pt-2">
                <Button
                  label="Log out"
                  icon="pi pi-sign-out"
                  severity="danger"
                  text
                  size="small"
                  class="w-full !justify-start"
                  @click="logout"
                />
              </div>
            </div>
          </Popover>
        </div>
      </header>

      <!-- Routed content -->
      <main class="mx-auto w-full max-w-[1500px] flex-1 px-3 py-5 pb-24 sm:px-6 lg:pb-8">
        <RouterView v-slot="{ Component }">
          <Transition name="page" mode="out-in">
            <component :is="Component" />
          </Transition>
        </RouterView>
      </main>
    </div>

    <!-- ══ Mobile drawer (full nav) ══ -->
    <Drawer v-model:visible="mobileNav" class="!w-72">
      <template #header>
        <span class="flex items-center gap-2">
          <span
            class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary font-bold text-primary-contrast"
          >
            P
          </span>
          <span class="font-bold text-ink">PLAZA<span class="text-primary-600"> PRO</span></span>
        </span>
      </template>
      <nav>
        <div v-for="section in sections" :key="section.label" class="mb-4">
          <p class="mb-1 px-2 text-[11px] font-semibold uppercase tracking-wider text-mute">
            {{ section.label }}
          </p>
          <RouterLink
            v-for="item in section.items"
            :key="item.to"
            :to="item.to"
            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm"
            :class="isActive(item.to) ? 'bg-highlight font-semibold text-ink' : 'text-mute'"
            @click="mobileNav = false"
          >
            <i :class="item.icon" class="w-5 text-center" aria-hidden="true" />
            {{ item.label }}
          </RouterLink>
        </div>
      </nav>
    </Drawer>

    <!-- ══ Mobile bottom bar ══ -->
    <nav
      class="fixed inset-x-0 bottom-0 z-20 flex h-16 items-stretch justify-around border-t border-line bg-card pb-[env(safe-area-inset-bottom)] lg:hidden"
    >
      <RouterLink
        v-for="item in bottomNav"
        :key="item.to"
        :to="item.to"
        class="flex min-w-[56px] flex-col items-center justify-center gap-0.5 text-[11px]"
        :class="
          isActive(item.to) ? 'font-semibold text-primary-600 dark:text-primary-400' : 'text-mute'
        "
      >
        <i :class="item.icon" class="text-lg" aria-hidden="true" />
        {{ item.label }}
      </RouterLink>
      <button
        type="button"
        class="flex min-w-[56px] flex-col items-center justify-center gap-0.5 text-[11px] text-mute"
        @click="mobileNav = true"
      >
        <i class="pi pi-ellipsis-h text-lg" aria-hidden="true" />
        More
      </button>
    </nav>

    <GlobalSearch ref="search" />
  </div>
</template>
