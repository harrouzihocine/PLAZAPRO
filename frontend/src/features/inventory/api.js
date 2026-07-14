import { useApi } from '@/composables/useApi'
import { t } from '@/i18n'

// Network calls for the Inventory feature. State lives in the feature stores;
// these functions are the only place Inventory talks to the API (via useApi).

// GTM (sales) priority degrees, mirroring App\Modules\Inventory\Enums\GtmPriority
// (the single source of truth). Ordered high→low for the pickers; `value` is the
// stored API value, `label` the caption.
// Function, not a constant: labels must re-resolve when the language changes.
export const gtmPriorityOptions = () => [
  { value: 'critical', label: t('status.critical') },
  { value: 'high', label: t('status.high') },
  { value: 'medium', label: t('status.medium') },
  { value: 'low', label: t('status.low') },
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

  // Read-only inventory funnel, pipeline and (permission-gated) revenue.
  async insights(id) {
    const { data } = await useApi().get(`/locations/${id}/insights`)
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

  // Park / un-park the whole project off the market (reversible; stays in management).
  async makeUnavailable(id) {
    const { data } = await useApi().post(`/locations/${id}/unavailable`)
    return data.data
  },

  async makeAvailable(id) {
    const { data } = await useApi().post(`/locations/${id}/available`)
    return data.data
  },

  cancel(id) {
    return useApi().delete(`/locations/${id}`)
  },
}

// Units (apartments / lots). Created under a location; price/sale_status
// corrections go through `correct` (cancel-and-duplicate versioning).
export const unitsApi = {
  // Full list — used location-scoped (bounded), returns just the array.
  async list(params = {}) {
    const { data } = await useApi().get('/units', { params })
    return data.data
  },

  // The unbounded global browse: server-paginated, returns the page + total for
  // the lazy DataTable.
  async listPaged(params = {}) {
    const { data } = await useApi().get('/units', { params })
    return { items: data.data, total: data.meta?.total ?? data.data.length }
  },

  async get(id) {
    const { data } = await useApi().get(`/units/${id}`)
    return data.data
  },

  // Read-only stats + payments summary for the unit detail page.
  async insights(id) {
    const { data } = await useApi().get(`/units/${id}/insights`)
    return data.data
  },

  // The interaction logs (calls + visits) of every visible client project that
  // has touched this unit, grouped per project — the unit page's "Project logs" tab.
  async projectLogs(id) {
    const { data } = await useApi().get(`/units/${id}/project-logs`)
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

  // Park / un-park a single unit off the market (reversible; hidden from selectors).
  async makeUnavailable(id) {
    const { data } = await useApi().post(`/units/${id}/unavailable`)
    return data.data
  },

  async makeAvailable(id) {
    const { data } = await useApi().post(`/units/${id}/available`)
    return data.data
  },

  // Multi-select cancel — returns { cancelled, skipped: [{ id, reference, reason }] }.
  async bulkCancel(ids, reason = null) {
    const { data } = await useApi().post('/units/bulk-cancel', { ids, reason })
    return data.data
  },

  // Multi-select park / un-park — returns { changed, skipped: [{ id, reference, reason }] }.
  async bulkUnavailable(ids) {
    const { data } = await useApi().post('/units/bulk-unavailable', { ids })
    return data.data
  },

  async bulkAvailable(ids) {
    const { data } = await useApi().post('/units/bulk-available', { ids })
    return data.data
  },

  // The current browse as an .xlsx blob (same filters as list) — the fast-edit
  // round-trip: export, fix in Excel, re-import.
  async exportExcel(params = {}) {
    const { data } = await useApi().get('/units/export', { params, responseType: 'blob' })
    return data
  },

  // The empty import .xlsx: example rows + a per-column guide sheet.
  async downloadTemplate() {
    const { data } = await useApi().get('/units/import-template', { responseType: 'blob' })
    return data
  },

  // Returns { created, updated, errors: [{ line, message }] }.
  async importFile(file) {
    const form = new FormData()
    form.append('file', file)
    const { data } = await useApi().post('/units/import', form)
    return data.data
  },
}

// The visual stacking plan: a location's units grouped by block/floor/position.
export const stackingApi = {
  async get(locationId) {
    const { data } = await useApi().get(`/locations/${locationId}/stacking`)
    return data.data
  },
}

// The 48-hour interest-hold lifecycle.
export const reservationsApi = {
  // The follow-up board: reserved/held units with their ordered queues
  // ("you are Nth in line"). Client identity comes masked per visibility.
  async queues(params = {}) {
    const { data } = await useApi().get('/reservations/queues', { params })
    return data.data
  },

  async markInterest(unitId, payload = {}) {
    const { data } = await useApi().post(`/units/${unitId}/interest`, payload)
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
// Grid derivatives (WebP thumbnail / video poster) are consumed via the
// resource's `thumb_url` — it carries a cache-busting version param.
export const mediaPreviewUrl = (id) => `/api/v1/media/${id}/preview`
// Forces an attachment download (original file + name). Same-origin cookie auth,
// so a plain <a href download> authenticates.
export const mediaDownloadUrl = (id) => `/api/v1/media/${id}/download`

// The media tabs, mirroring the backend MediaCollection enum (the single source
// of truth). `key` is the stored `collection` value; `label` is the tab caption.
export const MEDIA_COLLECTIONS = ['photos', 'videos', 'plans', 'presentations', 'documents', 'others']
export const mediaCollectionLabel = (key) => t(`media.${key}`)

// Collections that may ever leave the CRM — the showcase globe toggle and the
// send-to-client share both draw the line here (documents/presentations are
// internal by construction). Mirrors PublicProjectController::PUBLIC_COLLECTIONS.
export const SHAREABLE_COLLECTIONS = ['photos', 'videos', 'plans']

// Largest file the media library accepts, mirroring the UploadMediaRequest
// 'max' rule (200 MB) and the PHP upload_max_filesize in docker/php/uploads.ini.
// Enforced client-side so oversized files fail instantly with a clear message
// instead of a long upload that PHP rejects with a 413 PostTooLargeException.
export const MEDIA_MAX_BYTES = 200 * 1024 * 1024

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

  // Display-name only — the stored file (and its download bytes) are untouched.
  async rename(id, name) {
    const { data } = await useApi().patch(`/media/${id}`, { original_name: name })
    return data.data
  },

  // The public-showcase globe toggle: may anonymous visitors see this asset?
  async setPublic(id, isPublic) {
    const { data } = await useApi().patch(`/media/${id}/public`, { is_public: isPublic })
    return data.data
  },

  cancel(id) {
    return useApi().delete(`/media/${id}`)
  },
}

// Tokened share bundles — "send these photos to my client on WhatsApp". The
// backend mints the public /plaza/share/{token} link; the message itself is
// composed client-side (mediaShare.js) and sent over wa.me, like the office
// invite.
export const mediaShareApi = {
  async create(payload) {
    const { data } = await useApi().post('/media-shares', payload)
    return data.data
  },
}
