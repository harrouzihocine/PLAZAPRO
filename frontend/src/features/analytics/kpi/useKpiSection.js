import { ref, watch } from 'vue'
import { analyticsApi } from '@/features/analytics/api'
import { t } from '@/i18n'

// Fetch one KPI section and keep it in sync with the shared filter params.
// Empty-string dimensions are dropped so the backend treats them as "no filter"
// (Laravel filled() already ignores '', but a clean query is nicer to read).
export function useKpiSection(name, paramsRef, { immediate = true } = {}) {
  const data = ref(null)
  const loading = ref(true)
  const error = ref('')

  function clean(params) {
    return Object.fromEntries(
      Object.entries(params ?? {}).filter(([, v]) => v !== '' && v !== null && v !== undefined),
    )
  }

  async function reload() {
    loading.value = true
    try {
      data.value = await analyticsApi.kpiSection(name, clean(paramsRef.value))
      error.value = ''
    } catch {
      error.value = t('kpi.loadFailed')
    } finally {
      loading.value = false
    }
  }

  watch(paramsRef, reload, { deep: true, immediate })

  return { data, loading, error, reload }
}
