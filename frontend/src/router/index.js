import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'
import { isStaleChunkError, reloadForFreshBuild } from '@/utils/appRecovery'
import { showcaseRoutes } from '@/features/showcase/routes'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/features/settings/views/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/',
    component: () => import('@/layouts/AppShell.vue'),
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'dashboard',
        component: () => import('@/features/analytics/views/DashboardView.vue'),
      },
      {
        path: 'inventory',
        component: () => import('@/features/inventory/views/InventoryView.vue'),
        children: [
          { path: '', redirect: { name: 'inventory.locations' } },
          {
            path: 'locations',
            name: 'inventory.locations',
            component: () => import('@/features/inventory/views/LocationsView.vue'),
            meta: { permission: 'units.view' },
          },
          {
            path: 'locations/:id',
            name: 'inventory.location',
            props: true,
            component: () => import('@/features/inventory/views/LocationDetailView.vue'),
            meta: { permission: 'units.view' },
          },
          {
            path: 'units',
            name: 'inventory.units',
            component: () => import('@/features/inventory/views/UnitsView.vue'),
            meta: { permission: 'units.view' },
          },
          {
            path: 'units/:id',
            name: 'inventory.unit',
            props: true,
            component: () => import('@/features/inventory/views/UnitDetailView.vue'),
            meta: { permission: 'units.view' },
          },
        ],
      },
      {
        path: 'clients',
        name: 'clients',
        component: () => import('@/features/clients/views/ClientsView.vue'),
        meta: { permission: 'clients.view' },
      },
      {
        // The public site's leads inbox (triage + convert into clients).
        path: 'web-leads',
        name: 'web-leads',
        component: () => import('@/features/webleads/views/WebLeadsView.vue'),
        meta: { permission: 'web.leads' },
      },
      {
        // The public site's statistics board (visits, project/unit views).
        path: 'website-stats',
        name: 'website-stats',
        component: () => import('@/features/webleads/views/WebsiteStatsView.vue'),
        meta: { permission: 'web.stats' },
      },
      {
        path: 'desires/matches',
        name: 'desires.matches',
        component: () => import('@/features/clients/views/DesireMatchesView.vue'),
        meta: { permission: 'oversight.matches' },
      },
      {
        path: 'clients/:id',
        name: 'clients.file',
        props: true,
        component: () => import('@/features/clients/views/ClientFileView.vue'),
        meta: { permission: 'clients.view' },
      },
      {
        // Each client project opens as its own workspace page (deal, shortlist,
        // payments, timeline, history) instead of an accordion on the file.
        path: 'clients/:id/projects/:projectId',
        name: 'clients.project',
        props: true,
        component: () => import('@/features/clients/views/ClientProjectView.vue'),
        meta: { permission: 'clients.view' },
      },
      {
        path: 'tasks',
        name: 'tasks',
        component: () => import('@/features/pipeline/views/TasksView.vue'),
        meta: { permission: 'tasks.manage' },
      },
      {
        path: 'dispatch',
        name: 'dispatch',
        component: () => import('@/features/pipeline/views/DispatchView.vue'),
        meta: { permission: 'visits.dispatch' },
      },
      {
        // The field agent's own day: duty switch, today's dispatched visits,
        // the accept/en-route/arrived stepper. Personal data — no permission.
        path: 'my-day',
        name: 'my-day',
        component: () => import('@/features/pipeline/views/MyDayView.vue'),
      },
      {
        path: 'chat',
        name: 'chat',
        component: () => import('@/features/collaboration/views/ChatView.vue'),
        meta: { permission: 'chat.use' },
      },
      {
        // Same view as /chat — it renders the thread (phone/web) or the
        // two-pane (native tablet) from the :id param. The distinct route
        // name stays: AppShell keys its phone chat takeover on it.
        path: 'chat/:id',
        name: 'chat.thread',
        component: () => import('@/features/collaboration/views/ChatView.vue'),
        meta: { permission: 'chat.use' },
      },
      {
        path: 'payments',
        name: 'payments',
        component: () => import('@/features/payments/views/PaymentsView.vue'),
        meta: { permission: 'versements.view' },
      },
      {
        // The reservation follow-up board: reserved/held units and the ordered
        // queue on each ("you are 2nd in line"). reservations.view_all (checked
        // server-side) widens it from "my own book" to company-wide.
        path: 'reservations',
        name: 'reservations',
        component: () => import('@/features/inventory/views/ReservationsView.vue'),
        meta: { permission: 'reservations.view' },
      },
      {
        path: 'analytics',
        name: 'analytics',
        component: () => import('@/features/analytics/views/ReportsView.vue'),
        meta: { permission: 'reports.view' },
      },
      {
        // The company-wide KPI command center — sales, inventory, hold engine,
        // pipeline, collections, agents, cancellations and profitability on one
        // filterable board. Gated by its own permission.
        path: 'analytics/kpi',
        name: 'kpi-dashboard',
        component: () => import('@/features/analytics/views/KpiDashboardView.vue'),
        meta: { permission: 'analytics.kpi' },
      },
      {
        // Open to any authed user; the view self-scopes to the caller's own logs
        // unless they have logs.view_all (which unlocks the all-users selector).
        path: 'team-logs',
        name: 'team-logs',
        component: () => import('@/features/analytics/views/TeamLogsView.vue'),
      },
      {
        path: 'audit',
        name: 'audit',
        component: () => import('@/features/analytics/views/AuditView.vue'),
        meta: { permission: 'audit.view' },
      },
      {
        // Install-the-mobile-app page — open to every authed user (no permission).
        path: 'install',
        name: 'install',
        component: () => import('@/features/mobile/views/InstallAppView.vue'),
      },
      {
        // Compose + send custom broadcast notifications and review the sent
        // history (company-wide, with read tracking).
        path: 'broadcasts',
        name: 'broadcasts',
        component: () => import('@/features/broadcasts/views/BroadcastsView.vue'),
        meta: { permission: 'notifications.broadcast' },
      },
      {
        path: 'oversight/duplicates',
        name: 'oversight.duplicates',
        component: () => import('@/features/oversight/views/DuplicateRequestsView.vue'),
        meta: { permission: 'clients.duplicates.resolve' },
      },
      {
        path: 'oversight/clients',
        name: 'oversight.clients',
        component: () => import('@/features/oversight/views/OversightClientsView.vue'),
        meta: { permission: 'oversight.clients' },
      },
      {
        path: 'oversight/pipeline',
        name: 'oversight.pipeline',
        component: () => import('@/features/oversight/views/OversightPipelineView.vue'),
        meta: { permission: 'oversight.pipeline' },
      },
      {
        path: 'oversight/office-program',
        name: 'oversight.officeProgram',
        component: () => import('@/features/oversight/views/OfficeProgramView.vue'),
        // View permission (agents pick a free slot, masked view) OR the
        // managing side — dispatchers land here from approval notifications.
        meta: { permissionAny: ['oversight.office_program', 'visits.dispatch'] },
      },
      {
        path: 'oversight/deals',
        name: 'oversight.deals',
        component: () => import('@/features/oversight/views/OversightDealsView.vue'),
        meta: { permission: 'oversight.deals' },
      },
      {
        path: 'oversight/drafts',
        name: 'oversight.drafts',
        component: () => import('@/features/oversight/views/OversightDraftsView.vue'),
        meta: { permission: 'oversight.drafts' },
      },
      {
        path: 'oversight/archive',
        name: 'oversight.archive',
        component: () => import('@/features/oversight/views/ArchiveView.vue'),
        meta: { permission: 'oversight.archive' },
      },
      {
        path: 'settings',
        component: () => import('@/features/settings/views/SettingsLayout.vue'),
        children: [
          { path: '', redirect: { name: 'settings.lists' } },
          {
            path: 'lists',
            name: 'settings.lists',
            component: () => import('@/features/settings/views/ListsView.vue'),
            meta: { permission: 'settings.manage' },
          },
          {
            path: 'general',
            name: 'settings.general',
            component: () => import('@/features/settings/views/GeneralView.vue'),
            meta: { permission: 'settings.manage' },
          },
          {
            // Everything the public showcase presents: contacts, socials,
            // trilingual about, landing-hero library.
            path: 'website',
            name: 'settings.website',
            component: () => import('@/features/settings/views/WebsiteView.vue'),
            meta: { permission: 'settings.manage' },
          },
          {
            path: 'wilayas',
            name: 'settings.wilayas',
            component: () => import('@/features/settings/views/WilayasView.vue'),
            meta: { permission: 'settings.manage' },
          },
          {
            path: 'departments',
            name: 'settings.departments',
            component: () => import('@/features/settings/views/DepartmentsView.vue'),
            meta: { permission: 'settings.manage' },
          },
          {
            path: 'roles',
            name: 'settings.roles',
            component: () => import('@/features/settings/views/RolesView.vue'),
            meta: { permission: 'roles.manage' },
          },
          {
            path: 'users',
            name: 'settings.users',
            component: () => import('@/features/settings/views/UsersView.vue'),
            meta: { permission: 'users.manage' },
          },
        ],
      },
    ],
  },
  // The public showcase (/plaza) — its own layout, fully outside AppShell.
  ...showcaseRoutes,
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  // Marketing pages scroll like a website (top on navigate, anchors honored);
  // the CRM keeps its old behavior (no managed scrolling) untouched.
  scrollBehavior(to, from, savedPosition) {
    if (!to.matched.some((r) => r.meta.public)) return undefined
    if (savedPosition) return savedPosition
    if (to.hash) return { el: to.hash, behavior: 'smooth', top: 80 }
    return { top: 0 }
  },
})

// Route guard: resolve the session once, then gate protected routes.
router.beforeEach(async (to) => {
  // Public showcase pages never touch the session — no /auth/me fetch, no
  // boot-time 401 for anonymous visitors. (/login is also meta.public but
  // stays below: it needs the session to bounce signed-in users to the app.)
  if (to.matched.some((r) => r.meta.public) && to.name !== 'login') {
    return true
  }

  const auth = useAuthStore()
  if (!auth.ready) {
    await auth.fetchMe()
  }
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }
  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }
  if (to.meta.permission && !auth.can(to.meta.permission)) {
    return { name: 'dashboard' } // lacks the required permission
  }
  if (to.meta.permissionAny && !to.meta.permissionAny.some((p) => auth.can(p))) {
    return { name: 'dashboard' } // holds none of the accepted permissions
  }
  return true
})

// A deploy replaces the hashed chunk files, so a session opened before it
// 404s on every page it hadn't lazy-loaded yet and the navigation dies with
// no UI at all — the blank-page-until-refresh bug. Hard-load the target URL:
// the fresh index.html carries the new chunk names.
router.onError((error, to) => {
  if (isStaleChunkError(error)) reloadForFreshBuild(to?.fullPath)
})

export default router
