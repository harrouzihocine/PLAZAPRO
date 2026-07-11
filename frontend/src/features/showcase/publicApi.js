import { useApi } from '@/composables/useApi'

// The showcase's API surface — exclusively the unauthenticated /public/*
// endpoints. Same axios instance (same origin, Accept-Language per request);
// cookies are irrelevant here and a 401 can't happen by construction.

const api = useApi()

export const publicApi = {
  config: () => api.get('/public/config'),
  projects: (params = {}) => api.get('/public/projects', { params }),
  project: (id) => api.get(`/public/projects/${id}`),
  submitLead: (payload) => api.post('/public/leads', payload),
}

/** Public media URLs (unauthenticated streaming; ?v= comes from the API). */
export function publicThumbUrl(media) {
  return media?.thumb_url ?? null
}

export function publicFileUrl(media) {
  return media?.file_url ?? null
}
