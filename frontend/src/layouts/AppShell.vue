<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import Popover from 'primevue/popover'
import Tag from 'primevue/tag'
import { useTheme } from '@/composables/useTheme'
import { useNativePhone } from '@/composables/useNativeMode'
import { isNativeApp } from '@/utils/nativeApp'
import { initNativePush } from '@/utils/nativePush'
import { useAuthStore } from '@/features/settings/store'
import { initials } from '@/utils/format'
import NotificationBell from '@/features/collaboration/components/NotificationBell.vue'
import ChatDock from '@/features/collaboration/components/ChatDock.vue'
import DraftsIndicator from '@/components/shell/DraftsIndicator.vue'
import GlobalSearch from '@/components/shell/GlobalSearch.vue'
import PullToRefresh from '@/components/shell/PullToRefresh.vue'
import SyncIndicator from '@/components/shell/SyncIndicator.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import UnitSoldCelebration from '@/features/inventory/components/UnitSoldCelebration.vue'
import ProfileModal from '@/features/settings/components/ProfileModal.vue'
import OfflineBanner from '@/features/offline/OfflineBanner.vue'
import { useAnnouncementsStore } from '@/features/inventory/announcementsStore'
import { usePresenceStore } from '@/features/collaboration/presenceStore'
import { useNetworkStore } from '@/features/offline/networkStore'
import { useOutboxStore } from '@/features/offline/outboxStore'
import { oversightApi } from '@/features/oversight/api'

const { isNight, toggle } = useTheme()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

// APK-only design: the Android shell gets an app-grade chrome (Chat in the
// bottom bar, pill highlights, edge-to-edge chat) while the web keeps today's.
const isNative = isNativeApp()
const nativePhone = useNativePhone()
// A chat thread on a native phone takes over the viewport like a messaging
// app: no page padding, no bottom bar — just the conversation.
const chatTakeover = computed(() => nativePhone.value && route.name === 'chat.thread')

// Oversight sidebar badge counts (only fetched for users who can see any monitor).
const badges = ref({})
const OVERSIGHT_PERMS = [
  'oversight.clients',
  'oversight.pipeline',
  'oversight.deals',
  'oversight.drafts',
  'oversight.archive',
  'oversight.matches',
  'clients.duplicates.resolve',
]
// Live announcements reach EVERY logged-in user (sold celebration, status moves,
// new units) over the public channel — subscribe once for the whole session.
const announcements = useAnnouncementsStore()

// Connectivity: init the tracker, and when the link comes back after an
// offline-snapshot boot, revalidate the session against the server (a real
// 401 then logs out normally) and replay the offline outbox.
const network = useNetworkStore()
const outbox = useOutboxStore()
watch(
  () => network.online,
  (online) => {
    if (!online) return
    if (auth.offlineSession) auth.fetchMe()
    outbox.sync()
  },
)
// A fresh login (including re-login after a mid-sync 401) resumes the queue.
watch(
  () => auth.user?.id,
  (id) => {
    outbox.load(id)
    if (id) outbox.resumeAfterLogin()
  },
)

onMounted(async () => {
  network.init()
  // APK: register this device for system-tray push + route a tapped
  // notification's deep link (no-op on the web / without Firebase config).
  initNativePush()
  await outbox.load(auth.user?.id)
  if (network.online) outbox.sync()
  announcements.subscribe()
  // Everyone joins the `online` presence channel so the app's green "Active
  // now" dots reflect web users too; the web UI itself never shows them.
  usePresenceStore().join()

  if (!OVERSIGHT_PERMS.some((p) => auth.can(p))) return
  try {
    badges.value = await oversightApi.summary()
  } catch {
    /* badges are best-effort */
  }
})

const search = ref(null)
const userPanel = ref(null)
const mobileNav = ref(false)
const showProfile = ref(false)

function openProfile() {
  userPanel.value?.hide()
  showProfile.value = true
}

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
      // Open to everyone: a user without logs.view_all sees only their own logs
      // (the label reads "My logs" for them, "Team logs" for the company-wide view).
      { to: '/team-logs', label: 'Team logs', icon: 'pi pi-list-check' },
      { to: '/audit', label: 'Audit', icon: 'pi pi-shield', permission: 'audit.view' },
    ],
  },
  {
    label: 'Oversight',
    items: [
      {
        to: '/oversight/clients',
        label: 'Client quality',
        icon: 'pi pi-user-minus',
        permission: 'oversight.clients',
        badgeKey: 'clients',
      },
      {
        to: '/oversight/pipeline',
        label: 'Pipeline',
        icon: 'pi pi-hourglass',
        permission: 'oversight.pipeline',
        badgeKey: 'pipeline',
      },
      {
        to: '/oversight/deals',
        label: 'Lost + paid',
        icon: 'pi pi-wallet',
        permission: 'oversight.deals',
        badgeKey: 'deals',
      },
      {
        to: '/oversight/drafts',
        label: 'Drafts',
        icon: 'pi pi-pencil',
        permission: 'oversight.drafts',
        badgeKey: 'drafts',
      },
      {
        to: '/oversight/duplicates',
        label: 'Duplicates',
        icon: 'pi pi-clone',
        permission: 'clients.duplicates.resolve',
        badgeKey: 'duplicates',
      },
      {
        to: '/desires/matches',
        label: 'Matches',
        icon: 'pi pi-heart',
        permission: 'oversight.matches',
        badgeKey: 'matches',
      },
      {
        to: '/oversight/archive',
        label: 'Archive',
        icon: 'pi pi-inbox',
        permission: 'oversight.archive',
        badgeKey: 'archive',
      },
    ],
  },
  {
    label: 'System',
    items: [
      { to: '/settings', label: 'Settings', icon: 'pi pi-cog', permission: 'settings.manage' },
      // No permission: every user gets the install-the-app page.
      { to: '/install', label: 'Mobile App', icon: 'pi pi-mobile' },
    ],
  },
]

const sections = computed(() =>
  SECTIONS.map((s) => ({
    ...s,
    items: s.items
      .filter((i) => !i.permission || auth.can(i.permission))
      // Team logs is self-scoped for users without logs.view_all — call it what it
      // is for them so the label never over-promises a company-wide view.
      .map((i) =>
        i.to === '/team-logs' && !auth.can('logs.view_all') ? { ...i, label: 'My logs' } : i,
      ),
  })).filter((s) => s.items.length > 0),
)

function isActive(to) {
  if (to === '/') return route.path === '/'
  return route.path === to || route.path.startsWith(to + '/')
}

// ── Collapsible sections ──────────────────────────────────────────────
// Only the group you're working in stays open, so the rail never scrolls.
// Manual open/close survives reloads; the active section always auto-opens.
const NAV_OPEN_KEY = 'plaza-nav-open'
const openSections = ref({})

function activeSectionLabel() {
  return SECTIONS.find((s) => s.items.some((i) => isActive(i.to)))?.label
}

;(() => {
  let saved = null
  try {
    saved = JSON.parse(localStorage.getItem(NAV_OPEN_KEY) || 'null')
  } catch {
    /* corrupt value — fall back to defaults */
  }
  if (saved && typeof saved === 'object') {
    openSections.value = saved
  } else {
    // First visit: open the section for the current page (Overview as a fallback).
    openSections.value[activeSectionLabel() || 'Overview'] = true
  }
})()

const isOpen = (section) => !!openSections.value[section.label]

function toggleSection(label) {
  openSections.value[label] = !openSections.value[label]
  localStorage.setItem(NAV_OPEN_KEY, JSON.stringify(openSections.value))
}

// Sum of pending badges in a section — surfaced on a collapsed header.
function sectionBadge(section) {
  return (
    section.items.reduce((n, i) => n + (i.badgeKey ? badges.value[i.badgeKey] || 0 : 0), 0) || null
  )
}

// Keep the section for the current route open as the user navigates.
watch(
  () => route.path,
  () => {
    const label = activeSectionLabel()
    if (label) openSections.value[label] = true
  },
)

// Mobile bottom bar: the four most-used destinations + "More". In the Android
// shell, Chat earns a permanent slot — it's the most app-like destination.
const bottomNav = computed(() => {
  const flat = sections.value.flatMap((s) => s.items)
  const order = isNative
    ? ['/', '/clients', '/chat', '/inventory/units']
    : ['/', '/clients', '/inventory/locations', '/tasks']
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
        class="flex h-16 shrink-0 items-center border-b border-line px-4"
        :class="collapsed && 'justify-center px-0'"
      >
        <BrandLogo
          :variant="collapsed ? 'mark' : 'full'"
          :subtitle="collapsed ? '' : 'Real-estate CRM'"
        />
      </RouterLink>

      <!-- Nav -->
      <nav class="flex-1 overflow-y-auto px-3 py-4">
        <div
          v-for="section in sections"
          :key="section.label"
          :class="collapsed ? 'mb-4 last:mb-0' : 'mb-0.5'"
        >
          <!-- Section header — click to expand / collapse the group -->
          <button
            v-if="!collapsed"
            type="button"
            class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
            :aria-expanded="isOpen(section)"
            @click="toggleSection(section.label)"
          >
            <span class="truncate">{{ section.label }}</span>
            <span
              v-if="!isOpen(section) && sectionBadge(section)"
              class="num rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold leading-none text-primary-contrast"
            >
              {{ sectionBadge(section) }}
            </span>
            <i
              class="pi pi-chevron-down ml-auto text-[10px] transition-transform duration-200"
              :class="isOpen(section) ? '' : '-rotate-90'"
              aria-hidden="true"
            />
          </button>

          <div
            v-show="collapsed || isOpen(section)"
            class="space-y-0.5"
            :class="!collapsed && 'mb-2 mt-0.5'"
          >
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
              <span
                v-if="item.badgeKey && badges[item.badgeKey]"
                class="num ml-auto rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold leading-none text-primary-contrast"
                :class="collapsed ? 'absolute right-1 top-1 min-w-0 !px-1' : ''"
              >
                {{ badges[item.badgeKey] }}
              </span>
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
        <!-- The shell's bottom "More" replaces the hamburger — one menu, not two. -->
        <Button
          v-if="!isNative"
          icon="pi pi-bars"
          text
          rounded
          severity="secondary"
          class="lg:!hidden"
          aria-label="Open menu"
          @click="mobileNav = true"
        />

        <RouterLink to="/" class="flex items-center lg:hidden" aria-label="PLAZA PRO — home">
          <BrandLogo variant="mark" icon-class="h-8 w-auto" />
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
          <SyncIndicator />
          <DraftsIndicator />
          <NotificationBell v-if="auth.can('notifications.view')" />

          <!-- User menu -->
          <button
            type="button"
            class="ml-1 flex items-center gap-2 rounded-full transition-opacity hover:opacity-80"
            aria-label="Account menu"
            @click="userPanel.toggle($event)"
          >
            <Avatar
              :image="auth.user?.avatar_url || undefined"
              :label="auth.user?.avatar_url ? undefined : initials(auth.user?.name)"
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
              <div class="mt-3 space-y-1 border-t border-line pt-2">
                <Button
                  label="Edit profile"
                  icon="pi pi-user-edit"
                  severity="secondary"
                  text
                  size="small"
                  class="w-full !justify-start"
                  @click="openProfile"
                />
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

      <!-- Offline notice (all platforms — the PWA benefits too). Fixed overlay:
           the lg: offsets keep it clear of the sidebar; it must never push the
           page content down (that reflow made the app jump on flaky signal). -->
      <OfflineBanner :class="collapsed ? 'lg:left-[76px]' : 'lg:left-64'" />

      <!-- Facebook-style pull-to-refresh (APK only; the chat takeover thread
           and any open overlay stand down). -->
      <PullToRefresh v-if="isNative" :disabled="chatTakeover" />

      <!-- Routed content -->
      <main
        class="mx-auto w-full max-w-[1500px] flex-1"
        :class="chatTakeover ? 'flex flex-col' : 'px-3 py-5 pb-24 sm:px-6 lg:pb-8'"
      >
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
        <BrandLogo icon-class="h-8 w-auto" />
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
            <span
              v-if="item.badgeKey && badges[item.badgeKey]"
              class="num ml-auto rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold leading-none text-primary-contrast"
            >
              {{ badges[item.badgeKey] }}
            </span>
          </RouterLink>
        </div>
      </nav>
    </Drawer>

    <!-- ══ Mobile bottom bar ══ -->
    <nav
      v-if="!chatTakeover"
      class="fixed inset-x-0 bottom-0 z-20 flex h-16 items-stretch justify-around border-t border-line bg-card pb-[env(safe-area-inset-bottom)] native:h-[4.25rem] lg:hidden"
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
        <!-- In the shell the active icon sits in a Material-style pill. -->
        <span
          class="flex items-center justify-center native:h-8 native:w-14 native:rounded-full native:transition-colors"
          :class="isActive(item.to) && 'native:bg-highlight'"
        >
          <i :class="item.icon" class="text-lg" aria-hidden="true" />
        </span>
        {{ item.label }}
      </RouterLink>
      <button
        type="button"
        class="flex min-w-[56px] flex-col items-center justify-center gap-0.5 text-[11px] text-mute"
        @click="mobileNav = true"
      >
        <span class="flex items-center justify-center native:h-8 native:w-14">
          <i class="pi pi-ellipsis-h text-lg" aria-hidden="true" />
        </span>
        More
      </button>
    </nav>

    <GlobalSearch ref="search" />

    <!-- Full-screen "unit sold" celebration for all users (teleports to body). -->
    <UnitSoldCelebration />

    <!-- Floating chat dock (heads + popup thread), hidden on the /chat page. -->
    <ChatDock v-if="auth.can('chat.use')" />

    <!-- Self-service profile editor (opened from the account menu). -->
    <ProfileModal v-if="showProfile" @close="showProfile = false" />
  </div>
</template>
