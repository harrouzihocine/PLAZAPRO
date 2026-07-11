import { onUnmounted, watchEffect } from 'vue'

// SPA-grade SEO for the public showcase: document.title + description/OG tags
// per route, cleaned up on unmount so the CRM keeps its own title. Managed
// tags are marked data-seo-managed and upserted in place. (Real hreflang/SSR
// waits for the domain + Nuxt lift-out — this folder is the seam.)

const DEFAULT_TITLE = document.title

function upsert(attr, name, content) {
  let el = document.head.querySelector(`meta[${attr}="${name}"][data-seo-managed]`)
  if (!content) {
    el?.remove()
    return
  }
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(attr, name)
    el.setAttribute('data-seo-managed', '')
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}

/**
 * @param {() => {title?: string, description?: string, image?: string}} source
 *   Reactive getter — re-applied whenever its dependencies (locale, loaded
 *   project…) change.
 */
export function useSeoMeta(source) {
  watchEffect(() => {
    const { title, description, image } = source() ?? {}

    document.title = title ? `${title}` : DEFAULT_TITLE
    upsert('name', 'description', description)
    upsert('property', 'og:title', title)
    upsert('property', 'og:description', description)
    // Media URLs from the API are relative — OG consumers need absolute.
    upsert('property', 'og:image', image ? new URL(image, window.location.origin).href : null)
    upsert('property', 'og:url', window.location.href)
    upsert('property', 'og:type', 'website')
    upsert('name', 'twitter:card', image ? 'summary_large_image' : 'summary')
  })

  onUnmounted(() => {
    document.title = DEFAULT_TITLE
    document.head.querySelectorAll('[data-seo-managed]').forEach((el) => el.remove())
  })
}
