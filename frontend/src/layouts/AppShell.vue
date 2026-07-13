<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import Popover from 'primevue/popover'
import Tag from 'primevue/tag'
import { useTheme } from '@/composables/useTheme'
import { useIsBelowLg } from '@/composables/useNativeMode'
import { useNavSwipe } from '@/composables/useNavSwipe'
import { hasRefreshHandler, runRefresh } from '@/composables/useRefreshRegistry'
import { isNativeApp } from '@/utils/nativeApp'
import { initNativePush } from '@/utils/nativePush'
import { initAppUpdateCheck } from '@/utils/appUpdate'
import { useAuthStore } from '@/features/settings/store'
import { t, isRTL } from '@/i18n'
import { initials } from '@/utils/format'
import NotificationBell from '@/features/collaboration/components/NotificationBell.vue'
import ChatDock from '@/features/collaboration/components/ChatDock.vue'
import DraftsIndicator from '@/components/shell/DraftsIndicator.vue'
import GlobalSearch from '@/components/shell/GlobalSearch.vue'
import LanguageSwitcher from '@/components/shell/LanguageSwitcher.vue'
import PullToRefresh from '@/components/shell/PullToRefresh.vue'
import ServerIndicator from '@/components/shell/ServerIndicator.vue'
import SyncIndicator from '@/components/shell/SyncIndicator.vue'
import BrandLogo from '@/components/BrandLogo.vue'
import UnitSoldCelebration from '@/features/inventory/components/UnitSoldCelebration.vue'
import ProfileModal from '@/features/settings/components/ProfileModal.vue'
import NotificationPrefsModal from '@/features/settings/components/NotificationPrefsModal.vue'
import SessionsModal from '@/features/settings/components/SessionsModal.vue'
import UpdateBanner from '@/components/shell/UpdateBanner.vue'
import OfflineBanner from '@/features/offline/OfflineBanner.vue'
import { useAnnouncementsStore } from '@/features/inventory/announcementsStore'
import { useChatDockStore } from '@/features/collaboration/chatDockStore'
import { usePresenceStore } from '@/features/collaboration/presenceStore'
import { useDutyTracking } from '@/composables/useDutyTracking'
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
const belowLg = useIsBelowLg()
// A chat thread in the APK (phone AND tablet) takes over the viewport like a
// messaging app: no page padding, no bottom bar — just the conversation.
const chatTakeover = computed(() => isNative && route.name === 'chat.thread')

// Unread-chat counter for the shell's bottom-bar Chat tab (the web shows the
// same number on the dock launcher instead). ChatDock keeps it live: it stays
// mounted on native phones purely to feed this store.
const chatDock = useChatDockStore()

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
    // The visible page may have mounted EMPTY while the link was down (its
    // fetch died with no snapshot to serve) — re-run its registered reload so
    // it heals without a manual refresh. Handler-registered views only: the
    // full-reload PTR fallback must never fire on its own mid-session.
    if (hasRefreshHandler(route.name)) runRefresh(route.name)
    // The link is back: refresh the offline working set while it lasts.
    prewarmSoon()
  },
)

// Offline pre-warm (features/offline/prewarm.js): snapshot the user's working
// set — agenda, clients, tasks, chat, inventory — in an idle slot so a later
// no-signal open has data, not just code. Dynamic import: the feature stores
// it touches must not ride in the boot chunk.
function prewarmSoon() {
  const idle = window.requestIdleCallback ?? ((fn) => setTimeout(fn, 3000))
  idle(() => {
    import('@/features/offline/prewarm')
      .then((m) => m.prewarmOfflineData())
      .catch(() => {})
  })
}

// The service worker's Background Sync hands the outbox replay to a live page
// when one exists (full Sync Center UI); this is that hand-off listener.
function onSwMessage(event) {
  if (event.data?.type === 'plaza-outbox-sync') outbox.sync()
}
// A fresh login (including re-login after a mid-sync 401) resumes the queue.
// load() must FINISH first: resuming against the still-empty list synced
// nothing and left the queue waiting for the next reconnect.
watch(
  () => auth.user?.id,
  async (id) => {
    await outbox.load(id)
    if (id) outbox.resumeAfterLogin()
  },
)

// A session that dies mid-app (401 → authStore.clear) must LAND on the login
// screen — the route guard only checks on navigation, so without this the
// user sat on a half-dead page wondering what happened.
watch(
  () => auth.isAuthenticated,
  (authed) => {
    if (!authed && auth.ready) router.replace({ name: 'login' })
  },
)

onMounted(async () => {
  network.init()
  // APK: register this device for system-tray push + route a tapped
  // notification's deep link (no-op on the web / without Firebase config).
  initNativePush()
  // APK: watch /downloads/version.json for a newer build (no-op on the web).
  initAppUpdateCheck()
  await outbox.load(auth.user?.id)
  if (network.online) outbox.sync()
  navigator.serviceWorker?.addEventListener('message', onSwMessage)
  prewarmSoon()
  announcements.subscribe()
  // Everyone joins the `online` presence channel so the app's green "Active
  // now" dots reflect web users too; the web UI itself never shows them.
  usePresenceStore().join()
  // Field agents: align the location watcher with the server's duty state so
  // an on-duty agent keeps sharing while browsing any page (My Day toggles it).
  if (auth.isAgent) useDutyTracking().refresh()

  if (!OVERSIGHT_PERMS.some((p) => auth.can(p))) return
  try {
    badges.value = await oversightApi.summary()
  } catch {
    /* badges are best-effort */
  }
})

onBeforeUnmount(() => {
  navigator.serviceWorker?.removeEventListener('message', onSwMessage)
})

const search = ref(null)
const userPanel = ref(null)
const mobileNav = ref(false)
const showProfile = ref(false)
const showNotifPrefs = ref(false)
const showSessions = ref(false)

// APK, any screen still on drawer navigation (below lg — phones AND portrait
// tablets): swipe left→right anywhere opens the nav drawer, right→left closes
// it (the chat takeover thread owns its own gestures and stands down).
useNavSwipe({
  enabled: () => belowLg.value && !chatTakeover.value,
  isOpen: () => mobileNav.value,
  open: () => (mobileNav.value = true),
  close: () => (mobileNav.value = false),
})

function openProfile() {
  userPanel.value?.hide()
  showProfile.value = true
}

function openNotifPrefs() {
  userPanel.value?.hide()
  showNotifPrefs.value = true
}

function openSessions() {
  userPanel.value?.hide()
  showSessions.value = true
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
    key: 'overview',
    items: [{ to: '/', labelKey: 'nav.dashboard', icon: 'pi pi-home' }],
  },
  {
    key: 'sales',
    items: [
      { to: '/clients', labelKey: 'nav.clients', icon: 'pi pi-users', permission: 'clients.view' },
      { to: '/web-leads', labelKey: 'nav.webLeads', icon: 'pi pi-globe', permission: 'web.leads' },
      { to: '/tasks', labelKey: 'nav.tasks', icon: 'pi pi-check-square', permission: 'tasks.manage' },
      { to: '/my-day', labelKey: 'nav.myDay', icon: 'pi pi-compass', agentOnly: true },
      { to: '/dispatch', labelKey: 'nav.dispatch', icon: 'pi pi-send', permission: 'visits.dispatch' },
      { to: '/chat', labelKey: 'nav.chat', icon: 'pi pi-comments', permission: 'chat.use' },
    ],
  },
  {
    key: 'inventory',
    items: [
      {
        to: '/inventory/locations',
        labelKey: 'nav.locations',
        icon: 'pi pi-building',
        permission: 'units.view',
      },
      { to: '/inventory/units', labelKey: 'nav.units', icon: 'pi pi-th-large', permission: 'units.view' },
      // The reservation follow-up board — who queues on which unit, in order.
      { to: '/reservations', labelKey: 'nav.reservations', icon: 'pi pi-bookmark', permission: 'reservations.view' },
    ],
  },
  {
    key: 'finance',
    items: [
      { to: '/payments', labelKey: 'nav.payments', icon: 'pi pi-wallet', permission: 'versements.view' },
    ],
  },
  {
    key: 'insights',
    items: [
      { to: '/analytics/kpi', labelKey: 'nav.kpi', icon: 'pi pi-chart-bar', permission: 'analytics.kpi' },
      { to: '/analytics', labelKey: 'nav.reports', icon: 'pi pi-chart-line', permission: 'reports.view' },
      // The public website's own traffic board (visits, project/unit views).
      { to: '/website-stats', labelKey: 'nav.websiteStats', icon: 'pi pi-wave-pulse', permission: 'web.stats' },
      // Open to everyone: a user without logs.view_all sees only their own logs
      // (the label reads "My logs" for them, "Team logs" for the company-wide view).
      { to: '/team-logs', labelKey: 'nav.teamLogs', icon: 'pi pi-list-check' },
      { to: '/audit', labelKey: 'nav.audit', icon: 'pi pi-shield', permission: 'audit.view' },
    ],
  },
  {
    key: 'oversight',
    items: [
      {
        to: '/oversight/clients',
        labelKey: 'nav.clientQuality',
        icon: 'pi pi-user-minus',
        permission: 'oversight.clients',
        badgeKey: 'clients',
      },
      {
        to: '/oversight/pipeline',
        labelKey: 'nav.pipeline',
        icon: 'pi pi-hourglass',
        permission: 'oversight.pipeline',
        badgeKey: 'pipeline',
      },
      {
        to: '/oversight/office-program',
        labelKey: 'nav.officeProgram',
        icon: 'pi pi-calendar',
        // View permission (agents, masked grid) or the managing dispatchers.
        permissionAny: ['oversight.office_program', 'visits.dispatch'],
      },
      {
        to: '/oversight/deals',
        labelKey: 'nav.lostPaid',
        icon: 'pi pi-wallet',
        permission: 'oversight.deals',
        badgeKey: 'deals',
      },
      {
        to: '/oversight/drafts',
        labelKey: 'nav.drafts',
        icon: 'pi pi-pencil',
        permission: 'oversight.drafts',
        badgeKey: 'drafts',
      },
      {
        to: '/oversight/duplicates',
        labelKey: 'nav.duplicates',
        icon: 'pi pi-clone',
        permission: 'clients.duplicates.resolve',
        badgeKey: 'duplicates',
      },
      {
        to: '/desires/matches',
        labelKey: 'nav.matches',
        icon: 'pi pi-heart',
        permission: 'oversight.matches',
        badgeKey: 'matches',
      },
      {
        to: '/oversight/archive',
        labelKey: 'nav.archive',
        icon: 'pi pi-inbox',
        permission: 'oversight.archive',
        badgeKey: 'archive',
      },
    ],
  },
  {
    key: 'system',
    items: [
      { to: '/settings', labelKey: 'nav.settings', icon: 'pi pi-cog', permission: 'settings.manage' },
      // No permission: every user gets the install-the-app page.
      { to: '/install', labelKey: 'nav.mobileApp', icon: 'pi pi-mobile' },
    ],
  },
]

// Section headers translate through nav.section.* (key), items through their
// labelKey — resolved here so the whole nav re-renders on a language switch.
const sections = computed(() =>
  SECTIONS.map((s) => ({
    ...s,
    label: t(`nav.section.${s.key}`),
    items: s.items
      .filter(
        (i) =>
          (!i.permission || auth.can(i.permission)) &&
          (!i.permissionAny || i.permissionAny.some((p) => auth.can(p))) &&
          (!i.agentOnly || auth.isAgent),
      )
      // Team logs is self-scoped for users without logs.view_all — call it what it
      // is for them so the label never over-promises a company-wide view.
      .map((i) => ({
        ...i,
        label: t(
          i.to === '/team-logs' && !auth.can('logs.view_all') ? 'nav.myLogs' : i.labelKey,
        ),
      })),
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
  return SECTIONS.find((s) => s.items.some((i) => isActive(i.to)))?.key
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
    openSections.value[activeSectionLabel() || 'overview'] = true
  }
})()

const isOpen = (section) => !!openSections.value[section.key]

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
// shell, Chat earns a permanent slot — it's the most app-like destination —
// and Team/My logs replaces Units: an agent's day starts from "what's planned
// for me", not the inventory grid (which stays one tap away under More).
const bottomNav = computed(() => {
  const flat = sections.value.flatMap((s) => s.items)
  const order = isNative
    ? ['/', '/clients', '/chat', '/team-logs']
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
      class="fixed inset-y-0 start-0 z-30 hidden flex-col border-e border-line bg-card transition-[width] duration-200 lg:flex"
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
          :subtitle="collapsed ? '' : $t('shell.realEstateCrm')"
        />
      </RouterLink>

      <!-- Nav -->
      <nav class="flex-1 overflow-y-auto px-3 py-4">
        <div
          v-for="section in sections"
          :key="section.key"
          :class="collapsed ? 'mb-4 last:mb-0' : 'mb-0.5'"
        >
          <!-- Section header — click to expand / collapse the group -->
          <button
            v-if="!collapsed"
            type="button"
            class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-[11px] font-semibold uppercase tracking-wider text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800"
            :aria-expanded="isOpen(section)"
            @click="toggleSection(section.key)"
          >
            <span class="truncate">{{ section.label }}</span>
            <span
              v-if="!isOpen(section) && sectionBadge(section)"
              class="num rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold leading-none text-primary-contrast"
            >
              {{ sectionBadge(section) }}
            </span>
            <i
              class="pi pi-chevron-down ms-auto text-[10px] transition-transform duration-200"
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
                class="absolute inset-y-2 start-0 w-[3px] rounded-full bg-primary"
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
                class="num ms-auto rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold leading-none text-primary-contrast"
                :class="collapsed ? 'absolute end-1 top-1 min-w-0 !px-1' : ''"
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
          :label="collapsed ? null : $t('shell.collapse')"
          text
          severity="secondary"
          size="small"
          class="w-full"
          :aria-label="collapsed ? $t('shell.expandSidebar') : $t('shell.collapseSidebar')"
          @click="toggleCollapsed"
        />
      </div>
    </aside>

    <!-- ══ Main column ══ -->
    <div
      class="flex min-h-screen flex-col transition-[padding] duration-200"
      :class="collapsed ? 'lg:ps-[76px]' : 'lg:ps-64'"
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
:aria-label="$t('shell.openMenu')"
          @click="mobileNav = true"
        />

        <RouterLink to="/" class="flex items-center lg:hidden" :aria-label="$t('shell.homeAria')">
          <BrandLogo variant="mark" icon-class="h-8 w-auto" />
        </RouterLink>

        <!-- Search trigger — the topbar's left side; everything else lives in
             the right cluster below. -->
        <button
          type="button"
          class="flex h-10 w-10 items-center justify-center rounded-lg text-mute transition-colors hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800 sm:w-72 sm:justify-start sm:gap-2 sm:border sm:border-line sm:bg-surface-50 sm:px-3 dark:sm:bg-surface-900"
:aria-label="$t('shell.searchAria')"
          @click="search.show()"
        >
          <i class="pi pi-search text-sm" aria-hidden="true" />
          <span class="hidden flex-1 text-start text-sm sm:block">{{ $t('common.searchEllipsis') }}</span>
          <kbd
            class="hidden rounded border border-line bg-card px-1.5 py-0.5 text-[10px] text-mute sm:block"
          >
            Ctrl K
          </kbd>
        </button>

        <!-- Left of the gap, beside search: the "work" indicators — the bell
             and unsaved drafts (drafts only renders while something is pending). -->
        <div class="flex items-center gap-1">
          <NotificationBell v-if="auth.can('notifications.view')" />
          <DraftsIndicator />
        </div>

        <!-- Right cluster, pinned to the end: transient sync status, then the
             always-on controls, with the account menu at the extreme edge. -->
        <div class="ms-auto flex items-center gap-1">
          <SyncIndicator />
          <ServerIndicator />
          <Button
            :icon="isNight ? 'pi pi-sun' : 'pi pi-moon'"
            text
            rounded
            severity="secondary"
            :aria-label="isNight ? $t('shell.switchToDay') : $t('shell.switchToNight')"
            @click="toggle"
          />
          <LanguageSwitcher />

          <span class="mx-1 hidden h-6 w-px bg-line sm:block" aria-hidden="true" />

          <!-- User menu -->
          <button
            type="button"
            class="ms-1 flex items-center gap-2 rounded-full transition-opacity hover:opacity-80"
            :aria-label="$t('shell.accountMenu')"
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
:label="$t('shell.editProfile')"
                  icon="pi pi-user-edit"
                  severity="secondary"
                  text
                  size="small"
                  class="w-full !justify-start"
                  @click="openProfile"
                />
                <Button
:label="$t('shell.notifications')"
                  icon="pi pi-bell"
                  severity="secondary"
                  text
                  size="small"
                  class="w-full !justify-start"
                  @click="openNotifPrefs"
                />
                <Button
:label="$t('shell.sessions')"
                  icon="pi pi-desktop"
                  severity="secondary"
                  text
                  size="small"
                  class="w-full !justify-start"
                  @click="openSessions"
                />
                <Button
:label="$t('shell.logOut')"
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
      <OfflineBanner :class="collapsed ? 'lg:start-[76px]' : 'lg:start-64'" />

      <!-- "New APK published" notice (shell only; same overlay rules). -->
      <UpdateBanner v-if="isNative" :class="collapsed ? 'lg:start-[76px]' : 'lg:start-64'" />

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
    <Drawer v-model:visible="mobileNav" :position="isRTL() ? 'right' : 'left'" class="!w-72">
      <template #header>
        <BrandLogo icon-class="h-8 w-auto" />
      </template>
      <nav>
        <div v-for="section in sections" :key="section.key" class="mb-4">
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
              class="num ms-auto rounded-full bg-primary px-1.5 py-0.5 text-[10px] font-semibold leading-none text-primary-contrast"
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
          class="relative flex items-center justify-center native:h-8 native:w-14 native:rounded-full native:transition-colors"
          :class="isActive(item.to) && 'native:bg-highlight'"
        >
          <i :class="item.icon" class="text-lg" aria-hidden="true" />
          <!-- Unread chats ride the Chat tab (shell only — web has the dock). -->
          <span
            v-if="item.to === '/chat' && chatDock.totalUnread > 0"
            class="num pointer-events-none absolute -end-1.5 -top-1 flex h-4 min-w-[1rem] items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold leading-none text-white"
          >
            {{ chatDock.totalUnread > 99 ? '99+' : chatDock.totalUnread }}
          </span>
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
        {{ $t('common.more') }}
      </button>
    </nav>

    <GlobalSearch ref="search" />

    <!-- Full-screen "unit sold" celebration for all users (teleports to body). -->
    <UnitSoldCelebration />

    <!-- Floating chat dock (heads + popup thread), hidden on the /chat page. -->
    <ChatDock v-if="auth.can('chat.use')" />

    <!-- Self-service profile editor (opened from the account menu). -->
    <ProfileModal v-if="showProfile" @close="showProfile = false" />

    <!-- Per-category push toggles (account menu + the bell panel's gear). -->
    <NotificationPrefsModal v-if="showNotifPrefs" @close="showNotifPrefs = false" />
    <SessionsModal v-if="showSessions" @close="showSessions = false" />
  </div>
</template>
