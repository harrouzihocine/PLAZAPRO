import { en } from 'primelocale/js/en.js'
import { fr } from 'primelocale/js/fr.js'
import { ar } from 'primelocale/js/ar.js'

// PrimeVue's built-in strings (DataTable filter menus, paginator, file upload,
// aria labels…) per app locale. Algeria writes months French-style in Arabic
// (جانفي، فيفري — like Intl 'ar-DZ'), so the Middle-East month names that ship
// with primelocale are overridden to match every other date in the app.
const AR_DZ_MONTHS = [
  'جانفي',
  'فيفري',
  'مارس',
  'أفريل',
  'ماي',
  'جوان',
  'جويلية',
  'أوت',
  'سبتمبر',
  'أكتوبر',
  'نوفمبر',
  'ديسمبر',
]

export const PRIMEVUE_LOCALES = {
  en,
  fr,
  ar: { ...ar, monthNames: AR_DZ_MONTHS, monthNamesShort: AR_DZ_MONTHS },
}
