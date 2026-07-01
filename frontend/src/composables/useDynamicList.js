import { ref } from 'vue'
import { useApi } from '@/composables/useApi'

// The single consumer every dropdown reuses. Fetches a dynamic list's active
// items by key from GET /dynamic-lists/{key} and caches them app-wide, so the
// same list is only ever fetched once. Admin edits call invalidateDynamicList()
// to force the next use to refetch.
const cache = new Map() // key -> entry

function createEntry(key) {
  const items = ref([])
  const loading = ref(false)
  const error = ref(null)

  const entry = { items, loading, error, promise: null }

  entry.load = (force = false) => {
    if (entry.promise && !force) return entry.promise

    loading.value = true
    error.value = null
    entry.promise = useApi()
      .get(`/dynamic-lists/${key}`)
      .then(({ data }) => {
        items.value = data.data.items ?? []
        return items.value
      })
      .catch((e) => {
        error.value = e
        entry.promise = null // allow a later retry
        throw e
      })
      .finally(() => {
        loading.value = false
      })

    return entry.promise
  }

  return entry
}

export function useDynamicList(key) {
  if (!cache.has(key)) {
    cache.set(key, createEntry(key))
  }

  const entry = cache.get(key)
  entry.load() // kick off on first use; callers read the reactive refs

  return {
    items: entry.items,
    loading: entry.loading,
    error: entry.error,
    reload: () => entry.load(true),
  }
}

// Drop a cached list so the next useDynamicList(key) refetches (call after edits).
export function invalidateDynamicList(key) {
  const entry = cache.get(key)
  if (entry) entry.promise = null
}
