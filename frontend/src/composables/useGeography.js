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
    if (communeCache.has(wilayaId)) {
      communes.value = communeCache.get(wilayaId)
      return communes.value
    }

    loading.value = true
    try {
      const { data } = await useApi().get(`/wilayas/${wilayaId}/communes`)
      const list = data.data ?? []
      communeCache.set(wilayaId, list)
      communes.value = list
      return list
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
