import { useApi } from '@/composables/useApi'

// Network calls for the Pipeline feature: the interaction chain (calls, visits)
// and the enforced next action. The timeline is rendered on the client file.
export const pipelineApi = {
  // Optionally scoped to one project's story (its logs + the client-level ones).
  async timeline(clientId, projectId = null) {
    const params = projectId ? { project_id: projectId } : {}
    const { data } = await useApi().get(`/clients/${clientId}/timeline`, { params })
    return data.data // { calls, visits, next_actions }
  },

  async logCall(clientId, payload) {
    const { data } = await useApi().post(`/clients/${clientId}/calls`, payload)
    return data.data
  },

  // Plan a next action after the fact (for a log that didn't need one then).
  async createNextAction(clientId, payload) {
    const { data } = await useApi().post(`/clients/${clientId}/next-actions`, payload)
    return data.data
  },

  async assignVisit(visitId, agentId) {
    const { data } = await useApi().post(`/visits/${visitId}/assign`, { agent_id: agentId })
    return data.data
  },

  async completeVisit(visitId, payload) {
    const { data } = await useApi().post(`/visits/${visitId}/complete`, payload)
    return data.data
  },

  // Add apartment(s) to visit on a project, standalone (no need to complete an
  // open visit first). Assigned → materializes the pending visit(s); unassigned
  // → lands in the dispatch pool. Payload: { unit_ids, due_date, due_time?,
  // assigned_to? } (assigned_to honored only for dispatchers, server-side).
  async proposeInSiteVisit(projectId, payload) {
    const { data } = await useApi().post(`/projects/${projectId}/in-site-visits`, payload)
    return data.data
  },

  // Click-to-call: push this client's number to the agent's own phone (the
  // Android shell pops a "Call …" notification that opens the dialer). 422s
  // with a friendly message when no device is registered.
  async sendCallRequest(clientId) {
    const { data } = await useApi().post(`/clients/${clientId}/call-requests`)
    return data.data // { id, status, link }
  },

  // Answer the "log this call?" prompt: 'logged' (a call log was created) or
  // 'dismissed' ("not now"). Other open sessions drop their prompt over Reverb.
  async closeCallRequest(callRequestId, status) {
    const { data } = await useApi().post(`/call-requests/${callRequestId}/close`, { status })
    return data.data
  },

  // Corrections — every edit is a cancel + new version, so a reason is required.
  async correctCall(callId, payload) {
    const { data } = await useApi().post(`/calls/${callId}/correct`, payload)
    return data.data
  },

  async correctVisit(visitId, payload) {
    const { data } = await useApi().post(`/visits/${visitId}/correct`, payload)
    return data.data
  },

  async correctNextAction(nextActionId, payload) {
    const { data } = await useApi().post(`/next-actions/${nextActionId}/correct`, payload)
    return data.data
  },

  // The signed-in user's own workload for a week (`days` from `from`, default
  // today), bucketed per day — the free/busy strip that helps an agent pick when
  // to schedule the follow-up. `from` ('YYYY-MM-DD') shifts to a later week.
  async myAgenda({ days = 7, from = null } = {}) {
    const params = from ? { days, from } : { days }
    const { data } = await useApi().get('/me/agenda', { params })
    return data.data // [{ date, items: [{ kind, time, client, label }] }]
  },

  // The dispatch board (visits.dispatch): pending in-site pool + agent week grid.
  async dispatchBoard(week = null) {
    const { data } = await useApi().get('/dispatch/board', { params: week ? { week } : {} })
    return data.data
  },

  async dispatchAssign(changes) {
    const { data } = await useApi().post('/dispatch/assign', { changes })
    return data
  },

  // The Office Visits Program (oversight.pipeline): one week of office visits
  // by day × hour + every plan waiting for an approval verdict.
  async officeProgram(week = null) {
    const { data } = await useApi().get('/office-program', { params: week ? { week } : {} })
    return data.data // { week_start, window_days, visits, pending_approvals }
  },

  // A dispatcher's verdict on a beyond-window office-visit plan.
  // Payload: { decision: 'approve'|'deny'|'reschedule', reason?, due_date?, due_time? }
  async decideOfficeVisitApproval(nextActionId, payload) {
    const { data } = await useApi().post(`/next-actions/${nextActionId}/approval`, payload)
    return data.data
  },

  // --- The dispatch GPS layer -----------------------------------------------

  // The dispatcher's live map: agent dots (status + freshest fix) and today's
  // target sites; pending pool sites ride along hollow. visits.dispatch.
  async dispatchMap() {
    const { data } = await useApi().get('/dispatch/map')
    return data.data
  },

  // Ranked agents for one pending in-site plan (distance/load/familiarity —
  // suggest-only, the dispatcher assigns). visits.dispatch.
  async dispatchSuggest(actionId) {
    const { data } = await useApi().get('/dispatch/suggest', { params: { action_id: actionId } })
    return data.data // { sites, candidates }
  },

  // Ping one on-duty agent's device for a fresh fix (server-throttled 60s).
  async dispatchLocate(agentId) {
    const { data } = await useApi().post('/dispatch/locate', { agent_id: agentId })
    return data.data // { pinged }
  },

  // One agent's breadcrumb trail + in-site visits for a day. visits.dispatch.
  async dispatchReplay(agentId, date) {
    const { data } = await useApi().get('/dispatch/replay', { params: { agent_id: agentId, date } })
    return data.data // { positions, visits }
  },

  // The field agent's own day: duty state + today's visits with lifecycle
  // stamps, site pins and a nearest-first route_order proposal.
  async myDay() {
    const { data } = await useApi().get('/me/day')
    return data.data
  },

  async dutyState() {
    const { data } = await useApi().get('/me/duty')
    return data.data // { on, since }
  },

  async setDuty(on) {
    const { data } = await useApi().post('/me/duty', { on })
    return data.data
  },

  // "Go on duty, please" — bell + tray push to one agent (60s throttle).
  async dispatchNudge(agentId) {
    const { data } = await useApi().post('/dispatch/nudge', { agent_id: agentId })
    return data.data // { sent }
  },

  // Device location went off while on duty: end duty + notify both sides.
  async locationLost() {
    const { data } = await useApi().post('/me/duty/location-lost')
    return data.data // { on, since }
  },

  // One GPS fix while on duty. 409 = off duty (the watcher must stop).
  async postPosition(fix) {
    const { data } = await useApi().post('/me/positions', fix)
    return data.data
  },

  // The lifecycle stamps are idempotent — safe to retry blindly.
  async acceptVisit(visitId) {
    const { data } = await useApi().post(`/visits/${visitId}/accept`)
    return data.data
  },

  async declineVisit(visitId, reason) {
    const { data } = await useApi().post(`/visits/${visitId}/decline`, { reason })
    return data.data
  },

  async enRouteVisit(visitId) {
    const { data } = await useApi().post(`/visits/${visitId}/en-route`)
    return data.data
  },

  async arrivedVisit(visitId) {
    const { data } = await useApi().post(`/visits/${visitId}/arrived`)
    return data.data
  },
}

// Tasks (to-dos) backing the Phase 5 tasks board. All gated tasks.manage.
export const tasksApi = {
  async list(params = {}) {
    const { data } = await useApi().get('/tasks', { params })
    return data.data
  },

  // Single task incl. completion report — backs the detail modal (?task= links).
  async get(id) {
    const { data } = await useApi().get(`/tasks/${id}`)
    return data.data
  },

  async create(payload) {
    const { data } = await useApi().post('/tasks', payload)
    return data.data
  },

  // Completion report: { summary, outcome, difficulties?, time_spent_minutes? }
  async complete(id, report) {
    const { data } = await useApi().post(`/tasks/${id}/complete`, report)
    return data.data
  },

  cancel(id, reason) {
    return useApi().delete(`/tasks/${id}`, { data: { reason } })
  },
}
