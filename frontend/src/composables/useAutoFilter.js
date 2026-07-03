import { watch } from 'vue'

/**
 * Auto-apply a filter bar: watches a reactive filters object and calls `fetch`
 * on any change — no "Filter" button needed. Text inputs settle for
 * `debounceMs` before firing (a keystroke isn't a query); selects and other
 * discrete controls share the same trailing debounce, short enough to feel
 * immediate while collapsing rapid changes into one request.
 *
 * Usage: useAutoFilter(() => store.filters, () => store.fetch())
 */
export function useAutoFilter(filtersGetter, fetch, { debounceMs = 400 } = {}) {
  let timer = null

  watch(
    filtersGetter,
    () => {
      clearTimeout(timer)
      timer = setTimeout(fetch, debounceMs)
    },
    { deep: true },
  )
}
