import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/features/settings/store'

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
        path: 'desires/matches',
        name: 'desires.matches',
        component: () => import('@/features/clients/views/DesireMatchesView.vue'),
        meta: { permission: 'clients.view' },
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
        path: 'chat',
        name: 'chat',
        component: () => import('@/features/collaboration/views/ChatView.vue'),
        meta: { permission: 'chat.use' },
      },
      {
        path: 'chat/:id',
        name: 'chat.thread',
        props: true,
        component: () => import('@/features/collaboration/views/ThreadView.vue'),
        meta: { permission: 'chat.use' },
      },
      {
        path: 'payments',
        name: 'payments',
        component: () => import('@/features/payments/views/PaymentsView.vue'),
        meta: { permission: 'versements.view' },
      },
      {
        path: 'analytics',
        name: 'analytics',
        component: () => import('@/features/analytics/views/ReportsView.vue'),
        meta: { permission: 'reports.view' },
      },
      {
        path: 'team-logs',
        name: 'team-logs',
        component: () => import('@/features/analytics/views/TeamLogsView.vue'),
        meta: { permission: 'logs.view_all' },
      },
      {
        path: 'audit',
        name: 'audit',
        component: () => import('@/features/analytics/views/AuditView.vue'),
        meta: { permission: 'audit.view' },
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
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Route guard: resolve the session once, then gate protected routes.
router.beforeEach(async (to) => {
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
  return true
})

export default router
