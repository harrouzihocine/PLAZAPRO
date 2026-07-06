import { humanize } from '@/utils/format'

// One source of truth for how every domain state renders (label, PrimeVue Tag
// severity, icon). Views never hand-pick badge colours; they pass the raw value
// to <StatusTag>. Keys are the exact values the API returns.
const META = {
  // unit / box sale status
  available: { severity: 'success', icon: 'pi pi-check-circle' },
  reserved: { severity: 'warn', icon: 'pi pi-lock' },
  // On Hold — a deposit-backed, off-market lock; stronger than reserved.
  onhold: { severity: 'contrast', icon: 'pi pi-pause-circle', label: 'On hold' },
  sold: { severity: 'info', icon: 'pi pi-flag-fill' },
  blocked: { severity: 'danger', icon: 'pi pi-ban' },

  // deal-pipeline stages
  lead: { severity: 'info', icon: 'pi pi-user-plus' },
  negotiating: { severity: 'warn', icon: 'pi pi-comments' },

  // client-project derived step
  new: { severity: 'info', icon: 'pi pi-sparkles' },
  qualifying: { severity: 'info', icon: 'pi pi-comments' },
  office_visit: { severity: 'warn', icon: 'pi pi-building' },
  in_site_visit: { severity: 'warn', icon: 'pi pi-map-marker' },
  deal: { severity: 'warn', icon: 'pi pi-file-edit' },
  won: { severity: 'success', icon: 'pi pi-trophy' },
  lost: { severity: 'danger', icon: 'pi pi-times-circle' },
  desire: { severity: 'secondary', icon: 'pi pi-heart' },
  archived: { severity: 'secondary', icon: 'pi pi-inbox' },
  removed: { severity: 'danger', icon: 'pi pi-trash' },

  // shortlist journey
  shortlisted: { severity: 'secondary', icon: 'pi pi-list' },
  not_visited: { severity: 'warn', icon: 'pi pi-eye-slash' },
  visited_interested: { severity: 'success', icon: 'pi pi-thumbs-up' },
  visited_not_interested: { severity: 'danger', icon: 'pi pi-thumbs-down' },

  // payment schedule state
  pending: { severity: 'secondary', icon: 'pi pi-clock' },
  partial: { severity: 'warn', icon: 'pi pi-hourglass' },
  paid: { severity: 'success', icon: 'pi pi-check-circle' },
  overdue: { severity: 'danger', icon: 'pi pi-exclamation-circle' },

  // tasks / next actions / visits
  open: { severity: 'info', icon: 'pi pi-circle' },
  done: { severity: 'success', icon: 'pi pi-check' },
  scheduled: { severity: 'info', icon: 'pi pi-calendar' },
  completed: { severity: 'success', icon: 'pi pi-check' },
  cancelled: { severity: 'secondary', icon: 'pi pi-ban' },

  // base record status
  active: { severity: 'success', icon: 'pi pi-circle-fill' },

  // GTM priority
  critical: { severity: 'danger', icon: 'pi pi-bolt' },
  high: { severity: 'warn', icon: 'pi pi-arrow-up' },
  medium: { severity: 'info', icon: 'pi pi-minus' },
  low: { severity: 'secondary', icon: 'pi pi-arrow-down' },
}

export function statusMeta(value) {
  const meta = META[value] ?? { severity: 'secondary', icon: null }
  return { label: humanize(value), ...meta }
}
