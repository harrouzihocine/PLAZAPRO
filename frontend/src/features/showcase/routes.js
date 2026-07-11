// The public showcase — the ONLY route constant. The whole marketing site
// hangs under this prefix; when the company buys its own domain the feature
// lifts out (self-contained folder + slim /public API) and this becomes '/'.
export const PUBLIC_BASE = '/plaza'

export const showcaseRoutes = [
  {
    path: PUBLIC_BASE,
    component: () => import('./PublicLayout.vue'),
    // `public: true` short-circuits the router guard: no /auth/me fetch, no
    // permission checks — anonymous visitors never touch the CRM machinery.
    meta: { public: true },
    children: [
      // meta.hero: the page opens with a full-bleed dark visual, so the fixed
      // topbar starts transparent (white text) and solidifies on scroll.
      { path: '', name: 'showcase.home', meta: { hero: true }, component: () => import('./views/HomeView.vue') },
      { path: 'projects', name: 'showcase.projects', component: () => import('./views/ProjectsView.vue') },
      {
        path: 'projects/:id',
        name: 'showcase.project',
        props: true,
        meta: { hero: true },
        component: () => import('./views/ProjectDetailView.vue'),
      },
      // Unknown public paths stay on the public site (never bounce to /login).
      { path: ':pathMatch(.*)*', redirect: { name: 'showcase.home' } },
    ],
  },
]
