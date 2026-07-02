import { useApi } from '@/composables/useApi'

// Network calls for the Inventory feature. State lives in the feature stores;
// these functions are the only place Inventory talks to the API (via useApi).

// GTM (sales) priority degrees, mirroring App\Modules\Inventory\Enums\GtmPriority
// (the single source of truth). Ordered high→low for the pickers; `value` is the
// stored API value, `label` the caption.
export const GTM_PRIORITIES = [
  { value: 'critical', label: 'Critical' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
]

// Locations (projects). `list` also feeds location pickers elsewhere.
export const locationsApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/locations', { params })
    return data.data
  },

  async get(id) {
    const { data } = await useApi().get(`/locations/${id}`)
    return data.data
  },

  async create(payload) {
    const { data } = await useApi().post('/locations', payload)
    return data.data
  },

  async update(id, payload) {
    const { data } = await useApi().put(`/locations/${id}`, payload)
    return data.data
  },

  archive(id) {
    return useApi().post(`/locations/${id}/archive`)
  },

  reactivate(id) {
    return useApi().post(`/locations/${id}/reactivate`)
  },

  cancel(id) {
    return useApi().delete(`/locations/${id}`)
  },
}

// Units (apartments / lots). Created under a location; price/sale_status
// corrections go through `correct` (cancel-and-duplicate versioning).
export const unitsApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/units', { params })
    return data.data
  },

  async get(id) {
    const { data } = await useApi().get(`/units/${id}`)
    return data.data
  },

  async create(locationId, payload) {
    const { data } = await useApi().post(`/locations/${locationId}/units`, payload)
    return data.data
  },

  async update(id, payload) {
    const { data } = await useApi().put(`/units/${id}`, payload)
    return data.data
  },

  async correct(id, payload) {
    const { data } = await useApi().post(`/units/${id}/correct`, payload)
    return data.data
  },

  cancel(id) {
    return useApi().delete(`/units/${id}`)
  },
}

// The visual stacking plan: a location's units grouped by block/floor/position.
export const stackingApi = {
  async get(locationId) {
    const { data } = await useApi().get(`/locations/${locationId}/stacking`)
    return data.data
  },
}

// The 48-hour reservation hold lifecycle.
export const reservationsApi = {
  async reserve(unitId, payload = {}) {
    const { data } = await useApi().post(`/units/${unitId}/reserve`, payload)
    return data.data
  },

  async release(reservationId) {
    const { data } = await useApi().post(`/reservations/${reservationId}/release`)
    return data.data
  },

  async convert(reservationId) {
    const { data } = await useApi().post(`/reservations/${reservationId}/convert`)
    return data.data
  },
}

// Boxes (parking / storage), optionally linked to a unit in the same location.
export const boxesApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/boxes', { params })
    return data.data
  },

  async create(locationId, payload) {
    const { data } = await useApi().post(`/locations/${locationId}/boxes`, payload)
    return data.data
  },

  async update(id, payload) {
    const { data } = await useApi().put(`/boxes/${id}`, payload)
    return data.data
  },

  cancel(id) {
    return useApi().delete(`/boxes/${id}`)
  },
}

// Media (polymorphic, versioned). Files are streamed from permission-gated
// endpoints — never public URLs. Because the API is same-origin (Vite proxy) and
// uses cookie auth, these relative URLs authenticate in <img>/<video>/<iframe>.
export const mediaFileUrl = (id) => `/api/v1/media/${id}/file`
export const mediaPreviewUrl = (id) => `/api/v1/media/${id}/preview`
// Forces an attachment download (original file + name). Same-origin cookie auth,
// so a plain <a href download> authenticates.
export const mediaDownloadUrl = (id) => `/api/v1/media/${id}/download`

// The media tabs, mirroring the backend MediaCollection enum (the single source
// of truth). `key` is the stored `collection` value; `label` is the tab caption.
export const MEDIA_COLLECTIONS = [
  { key: 'photos', label: 'Photos' },
  { key: 'videos', label: 'Videos' },
  { key: 'plans', label: 'Plans' },
  { key: 'presentations', label: 'Presentations' },
  { key: 'documents', label: 'Documents' },
  { key: 'others', label: 'Others' },
]

export const mediaApi = {
  async list(mediableType, mediableId, params = {}) {
    const { data } = await useApi().get(`/${mediableType}/${mediableId}/media`, { params })
    return data.data
  },

  async upload(mediableType, mediableId, file, collection = 'others') {
    const form = new FormData()
    form.append('file', file)
    if (collection) form.append('collection', collection)
    const { data } = await useApi().post(`/${mediableType}/${mediableId}/media`, form)
    return data.data
  },

  async replace(id, file) {
    const form = new FormData()
    form.append('file', file)
    const { data } = await useApi().post(`/media/${id}/replace`, form)
    return data.data
  },

  reorder(mediableType, mediableId, order) {
    return useApi().post(`/${mediableType}/${mediableId}/media/reorder`, { order })
  },

  cancel(id) {
    return useApi().delete(`/media/${id}`)
  },
}
