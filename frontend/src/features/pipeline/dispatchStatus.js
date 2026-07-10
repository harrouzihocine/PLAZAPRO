// Shared palette + labels for the dispatch GPS layer, so the board, the live
// map and My Day speak one visual language.
//
// Visit lifecycle: assigned → accepted → en_route → arrived → done.
// Agent availability: available / en_route / on_site / off_duty.

export const VISIT_STATUS_TONES = {
  assigned: 'bg-surface-100 text-mute dark:bg-surface-800 dark:text-surface-300',
  accepted: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
  en_route: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  arrived: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  done: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
}

export const VISIT_STATUS_LABEL_KEYS = {
  assigned: 'myday.statusAssigned',
  accepted: 'myday.statusAccepted',
  en_route: 'myday.statusEnRoute',
  arrived: 'myday.statusArrived',
  done: 'myday.statusDone',
}

// The agent dot (board row header + map marker). Hex twins the Tailwind
// classes because Leaflet divIcons take inline styles, not utility classes.
export const AGENT_STATUS_DOTS = {
  available: 'bg-emerald-500',
  en_route: 'bg-amber-500',
  on_site: 'bg-sky-500',
  off_duty: 'bg-surface-400 dark:bg-surface-600',
}

export const AGENT_STATUS_COLORS = {
  available: '#10b981',
  en_route: '#f59e0b',
  on_site: '#0ea5e9',
  off_duty: '#94a3b8',
}

export const AGENT_STATUS_LABEL_KEYS = {
  available: 'dispatch.statusAvailable',
  en_route: 'dispatch.statusEnRoute',
  on_site: 'dispatch.statusOnSite',
  off_duty: 'dispatch.statusOffDuty',
}
