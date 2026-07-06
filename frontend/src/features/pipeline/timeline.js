// Shared shaping for the interaction timeline (calls + visits + next actions).
// Both the project TimelinePanel and the unit page's project-logs tab render the
// same entries, so the version-nesting and ordering live here in one place.

// --- Version chains: a superseded row nests under its replacement ------------
// Rows arrive flat (active + cancelled). The top level keeps rows nobody
// replaced; each carries its older versions (walked via supersedes_id).
export function withVersions(list) {
  const byId = new Map((list ?? []).map((x) => [x.id, x]))
  const replacedIds = new Set((list ?? []).map((x) => x.supersedes_id).filter((id) => id != null))
  return (list ?? [])
    .filter((x) => !replacedIds.has(x.id))
    .map((x) => {
      const versions = []
      let cur = x
      while (cur?.supersedes_id != null && byId.has(cur.supersedes_id)) {
        cur = byId.get(cur.supersedes_id)
        versions.push(cur)
      }
      return { ...x, previous_versions: versions }
    })
}

// Order by when the log was recorded (created_at) — the last thing logged sits
// in the first row. Fall back to the event time for older rows without it.
export const byNewest = (a, b) => new Date(b.loggedAt ?? b.at) - new Date(a.loggedAt ?? a.at)

export const callEntries = (calls) =>
  withVersions(calls).map((c) => ({ kind: 'call', at: c.called_at, loggedAt: c.created_at, data: c }))

export const visitEntries = (visits) =>
  withVersions(visits).map((v) => ({ kind: 'visit', at: v.scheduled_at, loggedAt: v.created_at, data: v }))

export const actionEntries = (actions) =>
  withVersions(actions).map((a) => ({ kind: 'action', at: a.due_at, loggedAt: a.created_at, data: a }))
