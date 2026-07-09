import { ref } from 'vue'
import { useApi } from '@/composables/useApi'

// Wilayas + communes: the geographic hierarchy that backs location / desire
// dropdowns. Wilayas are fetched once and cached app-wide (like useDynamicList);
// communes are fetched per wilaya and memoised, so a dependent commune select
// only hits the API the first time a given wilaya is opened.

const wilayas = ref([])
const wilayasLoading = ref(false)
const wilayasError = ref(null)
let wilayasPromise = null

function loadWilayas(force = false) {
  if (wilayasPromise && !force) return wilayasPromise

  wilayasLoading.value = true
  wilayasError.value = null
  wilayasPromise = useApi()
    .get('/wilayas')
    .then(({ data }) => {
      wilayas.value = data.data ?? []
      return wilayas.value
    })
    .catch((e) => {
      wilayasError.value = e
      wilayasPromise = null // allow a later retry
      throw e
    })
    .finally(() => {
      wilayasLoading.value = false
    })

  return wilayasPromise
}

export function useWilayas() {
  loadWilayas() // kick off on first use; callers read the reactive refs
  return {
    wilayas,
    loading: wilayasLoading,
    error: wilayasError,
    reload: () => loadWilayas(true),
  }
}

// Drop the cached wilayas so the next useWilayas() refetches (call after edits).
export function invalidateWilayas() {
  wilayasPromise = null
}

const communeCache = new Map() // wilayaId -> commune[]

// One wilaya's communes, memoised app-wide (each entry annotated with its
// wilaya_id so multi-wilaya callers can group / prune).
async function fetchCommunes(wilayaId) {
  if (communeCache.has(wilayaId)) return communeCache.get(wilayaId)

  const { data } = await useApi().get(`/wilayas/${wilayaId}/communes`)
  const list = (data.data ?? []).map((c) => ({ ...c, wilaya_id: c.wilaya_id ?? wilayaId }))
  communeCache.set(wilayaId, list)
  return list
}

// A per-component dependent commune list. Call load(wilayaId) whenever the chosen
// wilaya changes; the result is memoised across components by wilaya id.
export function useCommunes() {
  const communes = ref([])
  const loading = ref(false)

  async function load(wilayaId) {
    if (!wilayaId) {
      communes.value = []
      return communes.value
    }

    loading.value = true
    try {
      communes.value = await fetchCommunes(wilayaId)
      return communes.value
    } finally {
      loading.value = false
    }
  }

  return { communes, loading, load }
}

// The multi-wilaya form: load([ids]) unions the selected wilayas' communes (in
// the given wilaya order), for multi-select commune pickers.
export function useCommunesByWilayas() {
  const communes = ref([])
  const loading = ref(false)

  async function load(wilayaIds) {
    const ids = (wilayaIds ?? []).filter(Boolean)
    if (!ids.length) {
      communes.value = []
      return communes.value
    }

    loading.value = true
    try {
      const lists = await Promise.all(ids.map(fetchCommunes))
      communes.value = lists.flat()
      return communes.value
    } finally {
      loading.value = false
    }
  }

  return { communes, loading, load }
}

// Drop cached communes (one wilaya, or all) so the next load() refetches.
export function invalidateCommunes(wilayaId) {
  if (wilayaId == null) communeCache.clear()
  else communeCache.delete(wilayaId)
}
