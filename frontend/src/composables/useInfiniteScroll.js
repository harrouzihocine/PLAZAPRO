import { onBeforeUnmount, ref, watch } from 'vue'

/**
 * Infinite scroll on a sentinel element: bind the returned `sentinel` ref to an
 * empty div after the list; `onReach` fires when it scrolls near the viewport
 * (pre-fetching `margin` early so the user rarely sees the loader). The caller
 * guards its own state (hasMore / not already loading) — the composable only
 * reports proximity. Pair it with a visible "Load more" button: that keeps the
 * list usable where IntersectionObserver is missing and for keyboard users.
 */
export function useInfiniteScroll(onReach, { margin = '600px' } = {}) {
  const sentinel = ref(null)
  let observer = null

  if (typeof IntersectionObserver !== 'undefined') {
    observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) onReach()
      },
      { rootMargin: margin },
    )
  }

  watch(sentinel, (el, prev) => {
    if (!observer) return
    if (prev) observer.unobserve(prev)
    if (el) observer.observe(el)
  })

  onBeforeUnmount(() => observer?.disconnect())

  return { sentinel }
}
