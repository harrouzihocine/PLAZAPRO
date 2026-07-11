import { computed, ref, watch } from 'vue'

// Visitor favorites — pure localStorage, no account needed. One module-level
// store shared by every component (hearts, compare drawer) so toggles react
// everywhere at once. Shape: [{ projectId, unitId }].

const KEY = 'plaza.favorites.v1'

function load() {
  try {
    const parsed = JSON.parse(localStorage.getItem(KEY) ?? '[]')
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

const favorites = ref(load())

watch(favorites, (list) => localStorage.setItem(KEY, JSON.stringify(list)), { deep: true })

export function useFavorites(projectId) {
  const pid = Number(projectId)

  const unitIds = computed(() =>
    favorites.value.filter((f) => f.projectId === pid).map((f) => f.unitId),
  )

  function has(unitId) {
    return unitIds.value.includes(unitId)
  }

  function toggle(unitId) {
    if (has(unitId)) {
      favorites.value = favorites.value.filter((f) => !(f.projectId === pid && f.unitId === unitId))
    } else {
      favorites.value = [...favorites.value, { projectId: pid, unitId }]
    }
  }

  function clear() {
    favorites.value = favorites.value.filter((f) => f.projectId !== pid)
  }

  return { unitIds, has, toggle, clear }
}
