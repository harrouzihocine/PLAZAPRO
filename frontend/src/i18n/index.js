import { createI18n } from 'vue-i18n'
import en from './locales/en'
import fr from './locales/fr'
import ar from './locales/ar'

// Trilingual UI: English / French / Arabic (RTL). The locale lives in three
// places kept in sync: this i18n instance (what renders), localStorage (so the
// choice survives reloads and applies before login), and users.locale on the
// backend (so validation errors, notifications and push arrive in the user's
// language on every device). This module is dependency-free on purpose — the
// API layer and stores import from here, never the other way around.

export const SUPPORTED_LOCALES = ['en', 'fr', 'ar']
export const RTL_LOCALES = ['ar']
const STORAGE_KEY = 'plaza-locale'

// Native-script names — a language picker must be readable in the language it
// offers, so these are never translated.
export const LOCALE_NAMES = { en: 'English', fr: 'Français', ar: 'العربية' }

function detectLocale() {
  const saved = localStorage.getItem(STORAGE_KEY)
  if (SUPPORTED_LOCALES.includes(saved)) return saved
  const nav = (navigator.language || 'en').slice(0, 2).toLowerCase()
  return SUPPORTED_LOCALES.includes(nav) ? nav : 'en'
}

// Arabic has six CLDR plural forms (zero|one|two|few|many|other). Messages may
// still provide only two ("one | other") — clamp so those keep working.
function arPluralRule(choice, choicesLength) {
  if (choicesLength === 2) return choice === 1 ? 0 : 1
  const idx =
    choice === 0
      ? 0
      : choice === 1
        ? 1
        : choice === 2
          ? 2
          : choice % 100 >= 3 && choice % 100 <= 10
            ? 3
            : choice % 100 >= 11
              ? 4
              : 5
  return Math.min(idx, choicesLength - 1)
}

export const i18n = createI18n({
  legacy: false,
  globalInjection: true, // templates use $t without imports
  locale: detectLocale(),
  fallbackLocale: 'en',
  messages: { en, fr, ar },
  pluralRules: { ar: arPluralRule },
  missingWarn: false,
  fallbackWarn: false,
})

/** Current app locale ('en' | 'fr' | 'ar'). */
export function currentLocale() {
  return i18n.global.locale.value
}

export function isRTL(locale = currentLocale()) {
  return RTL_LOCALES.includes(locale)
}

/** Translate outside components (stores, utils, api). */
export function t(...args) {
  return i18n.global.t(...args)
}

// Stamp <html lang dir> so RTL layout, the Arabic font stack and PrimeVue's
// built-in RTL styles all key off the same attribute.
function applyDom(locale) {
  document.documentElement.setAttribute('lang', locale)
  document.documentElement.setAttribute('dir', isRTL(locale) ? 'rtl' : 'ltr')
}

/**
 * Switch the UI language (local only — callers that must persist it to the
 * user's profile do that themselves via the auth store).
 */
export function setLocale(locale) {
  if (!SUPPORTED_LOCALES.includes(locale)) return
  i18n.global.locale.value = locale
  localStorage.setItem(STORAGE_KEY, locale)
  applyDom(locale)
}

/** Apply the boot-time locale before the first paint (called from main.js). */
export function initLocale() {
  applyDom(currentLocale())
}
