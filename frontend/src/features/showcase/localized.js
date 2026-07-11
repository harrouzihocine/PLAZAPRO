import { currentLocale } from '@/i18n'

/**
 * Pick the current-locale value out of a {en,fr,ar} marketing-copy object,
 * falling back through the other languages (owners rarely fill all three
 * from day one).
 */
export function pickLocalized(obj) {
  if (!obj || typeof obj !== 'object') return null
  return obj[currentLocale()] || obj.fr || obj.en || obj.ar || null
}
