import { h } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import PhasePlaceholder from '@/components/PhasePlaceholder.vue'
import { useAuthStore } from '@/features/settings/store'

// Small helper to render a placeholder route until its phase is built.
const placeholder = (title, phase) => ({
  render: () => h(PhasePlaceholder, { title, phase }),
})

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
      { path: 'inventory', name: 'inventory', component: placeholder('Inventory', 'Phase 2') },
      { path: 'clients', name: 'clients', component: placeholder('Clients', 'Phase 3') },
      { path: 'payments', name: 'payments', component: placeholder('Payments', 'Phase 4') },
      {
        path: 'settings/lists',
        name: 'settings.lists',
        component: () => import('@/features/settings/views/ListsView.vue'),
        meta: { permission: 'settings.manage' },
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
