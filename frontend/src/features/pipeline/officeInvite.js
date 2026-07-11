import { t } from '@/i18n'
import { appSettingsApi } from '@/features/settings/api'
import { formatDateTime } from '@/utils/format'

// The office-visit invitation an agent sends a client to bring them in ("come
// to us"). Sharing happens over the client's own channels (WhatsApp / SMS /
// copy) — exactly the wa.me pattern used everywhere else for client contact —
// so there is no backend send: we only compose the text here.
//
// The message is written in the agent's current app language (per decision) and
// is filled from the company / office profile in Settings → General. Every
// profile field is optional: a blank one is simply left out, so the invite
// still works before the office details are configured.

/** The company/office profile from app settings (cached once per session). */
export async function officeProfile() {
  try {
    const s = await appSettingsApi.cached()
    return {
      company: (s.company_name || '').trim(),
      address: (s.office_address || '').trim(),
      maps: (s.office_maps_url || '').trim(),
      phone: (s.office_phone || '').trim(),
    }
  } catch {
    // The invite is still useful without the office details — never break on it.
    return { company: '', address: '', maps: '', phone: '' }
  }
}

/**
 * Compose the invitation body. Lines are joined with blank lines and any that
 * resolve to nothing (no name, no schedule, no office detail) are dropped.
 *
 * @param {{ name?: string, dateTime?: string|number|Date|null, profile?: object }} opts
 */
export function buildOfficeInviteText({ name = '', dateTime = null, profile = {} } = {}) {
  const p = profile || {}
  const lines = [
    name ? t('officeInvite.greetingNamed', { name }) : t('officeInvite.greeting'),
    p.company
      ? t('officeInvite.bodyCompany', { company: p.company })
      : t('officeInvite.body'),
    dateTime ? t('officeInvite.when', { datetime: formatDateTime(dateTime) }) : '',
    p.address ? t('officeInvite.address', { address: p.address }) : '',
    p.maps ? t('officeInvite.maps', { maps: p.maps }) : '',
    p.phone ? t('officeInvite.phone', { phone: p.phone }) : '',
    t('officeInvite.close'),
  ]
  return lines.filter(Boolean).join('\n\n')
}
