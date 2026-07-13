import { t } from '@/i18n'

// The WhatsApp message that carries a media-share link ("here are the photos
// of your apartment"). Same contract as the office invite: no backend send —
// the backend only mints the tokened link, this composes the text in the
// agent's current app language, and the wa.me deep link does the rest.

/**
 * @param {{ name?: string, title?: string, url: string, profile?: object }} opts
 *   `profile` is the office profile from officeProfile() — only `company` is
 *   used, and like the invite it is optional (blank profile still reads fine).
 */
export function buildMediaShareText({ name = '', title = '', url = '', profile = {} } = {}) {
  const p = profile || {}
  const lines = [
    name ? t('mediaShare.greetingNamed', { name }) : t('mediaShare.greeting'),
    p.company
      ? t('mediaShare.bodyCompany', { title, company: p.company })
      : t('mediaShare.body', { title }),
    url,
    t('mediaShare.close'),
  ]
  return lines.filter(Boolean).join('\n\n')
}
