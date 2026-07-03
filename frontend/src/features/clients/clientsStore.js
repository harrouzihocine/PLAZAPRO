import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import {
  agentsApi,
  clientsApi,
  dealsApi,
  desireApi,
  followUpAgentsApi,
  projectsApi,
  reserveUnit,
} from '@/features/clients/api'
import { pipelineApi } from '@/features/pipeline/api'

// State for the Clients screens. Loads clients plus two agent catalogues: `agents`
// (field agents, for visit/next-action pickers) and `followUpAgents` (sales agents
// who can log calls, for the client "assigned agent" picker). Filters are sent to
// the server; every write refetches so server rules are reflected.
export const useClientsStore = defineStore('clients', {
  state: () => ({
    items: [],
    current: null,
    agents: [],
    followUpAgents: [],
    projects: [],
    archivedProjects: [],
    deals: {}, // projectId -> its deals (newest first)
    desire: null,
    matches: [],
    timeline: { calls: [], visits: [], next_actions: [], next_action_history: [] },
    timelineProjectId: null,
    filters: { assigned_agent_id: '', source_id: '', rating_id: '', search: '' },
    _fetchTicket: 0, // stale-response guard for auto-applied filters
    loading: false,
    saving: false,
    error: '',
  }),

  actions: {
    async fetch() {
      this.loading = true
      // Filters auto-apply on every change (useAutoFilter): tag the request so
      // a slower, older response can never overwrite a newer one.
      const ticket = ++this._fetchTicket
      try {
        const params = {}
        for (const [k, v] of Object.entries(this.filters)) {
          if (v !== '' && v !== null) params[k] = v
        }
        // The agent catalogues don't depend on the filters — fetch them once.
        const [clients, agents, followUpAgents] = await Promise.all([
          clientsApi.list(params),
          this.agents.length ? this.agents : agentsApi.list(),
          this.followUpAgents.length ? this.followUpAgents : followUpAgentsApi.list(),
        ])
        if (ticket !== this._fetchTicket) return // superseded by a newer fetch
        this.items = clients
        this.agents = agents
        this.followUpAgents = followUpAgents
      } finally {
        if (ticket === this._fetchTicket) this.loading = false
      }
    },

    async load(id) {
      this.loading = true
      try {
        this.current = await clientsApi.get(id)
        if (!this.agents.length) this.agents = await agentsApi.list()
        if (!this.followUpAgents.length) this.followUpAgents = await followUpAgentsApi.list()
        return this.current
      } finally {
        this.loading = false
      }
    },

    async mutate(fn) {
      this.saving = true
      this.error = ''
      try {
        const result = await fn()
        return result
      } catch (e) {
        this.error = e.response?.data?.message ?? 'Action failed.'
        toastError(this.error)
        throw e
      } finally {
        this.saving = false
      }
    },

    async create(payload) {
      const result = await this.mutate(() => clientsApi.create(payload))
      await this.fetch()
      return result
    },

    async update(id, payload) {
      const result = await this.mutate(() => clientsApi.update(id, payload))
      if (this.current?.id === id) this.current = result
      await this.fetch()
      return result
    },

    async cancel(id, reason = 'Removed') {
      const result = await this.mutate(() => clientsApi.cancel(id, reason))
      await this.fetch()
      return result
    },

    // --- Deals (client_projects) on the currently-loaded client ---

    async loadProjects(clientId) {
      this.projects = await projectsApi.list(clientId)
      return this.projects
    },

    async loadArchivedProjects(clientId) {
      this.archivedProjects = await projectsApi.list(clientId, 'archived')
      return this.archivedProjects
    },

    // Returns the created project (the "new project" flow immediately logs the
    // first call onto it, so the caller needs the id).
    async createProject(clientId, payload = {}) {
      const project = await this.mutate(() => projectsApi.create(clientId, payload))
      await this.loadProjects(clientId)
      return project
    },

    async advanceProject(clientId, projectId, stage) {
      await this.mutate(() => projectsApi.advance(projectId, stage))
      return this.loadProjects(clientId)
    },

    // Archive = reversible: hides the deal + its contents until reactivated. A
    // reason (archive_reasons item) is required; blocked if payments were recorded.
    async archiveProject(clientId, projectId, payload) {
      await this.mutate(() => projectsApi.archive(projectId, payload))
      await this.loadArchivedProjects(clientId)
      return this.loadProjects(clientId)
    },

    async reactivateProject(clientId, projectId) {
      await this.mutate(() => projectsApi.reactivate(projectId))
      await this.loadArchivedProjects(clientId)
      return this.loadProjects(clientId)
    },

    // Shift a deal back to the desire list (client changed their mind).
    async shiftProjectToDesire(clientId, projectId, payload) {
      await this.mutate(() => projectsApi.shiftToDesire(projectId, payload))
      await this.loadArchivedProjects(clientId)
      await this.loadDesire(clientId)
      return this.loadProjects(clientId)
    },

    // Remove = terminal: cancels the deal and everything inside it (kept + audited).
    async cancelProject(clientId, projectId, reason = 'Deal removed') {
      await this.mutate(() => projectsApi.cancel(projectId, reason))
      return this.loadProjects(clientId)
    },

    // --- Desire + inventory matching ---

    async loadDesire(clientId) {
      this.desire = await desireApi.get(clientId)
      return this.desire
    },

    async saveDesire(clientId, payload) {
      this.desire = await this.mutate(() => desireApi.save(clientId, payload))
      return this.desire
    },

    async loadMatches(clientId) {
      this.matches = await desireApi.matches(clientId)
      return this.matches
    },

    async reserveMatch(clientId, unitId) {
      await this.mutate(() => reserveUnit(unitId))
      // The unit is now held → no longer available, so refresh the candidate list.
      return this.loadMatches(clientId)
    },

    // --- Deals on a project (created from visit logs; one active at a time) ---

    async loadDeals(projectId) {
      this.deals = { ...this.deals, [projectId]: await dealsApi.list(projectId) }
      return this.deals[projectId]
    },

    async createDeal(clientId, projectId, payload) {
      await this.mutate(() => dealsApi.create(projectId, payload))
      await this.loadDeals(projectId)
      return this.loadProjects(clientId)
    },

    async closeDeal(clientId, projectId, dealId, payload) {
      await this.mutate(() => dealsApi.close(dealId, payload))
      await this.loadDeals(projectId)
      return this.loadProjects(clientId)
    },

    async syncDealBoxes(projectId, dealId, boxIds) {
      await this.mutate(() => dealsApi.syncBoxes(dealId, boxIds))
      return this.loadDeals(projectId)
    },

    // --- Interaction chain (calls, visits, next actions) ---

    // Scoped to one project when projectId is given (the per-project story).
    async loadTimeline(clientId, projectId = this.timelineProjectId) {
      this.timelineProjectId = projectId ?? null
      this.timeline = await pipelineApi.timeline(clientId, this.timelineProjectId)
      return this.timeline
    },

    async logCall(clientId, payload) {
      await this.mutate(() => pipelineApi.logCall(clientId, payload))
      // The first call unlocks the projects (has_calls flips), a call may open /
      // reopen a project or upsert the desire — refresh what the panels show.
      if (this.current && String(this.current.id) === String(clientId)) {
        this.current = await clientsApi.get(clientId)
      }
      await this.loadProjects(clientId)
      if (payload.desire) await this.loadDesire(clientId)
      return this.loadTimeline(clientId)
    },

    async completeVisit(clientId, visitId, payload) {
      await this.mutate(() => pipelineApi.completeVisit(visitId, payload))
      return this.loadTimeline(clientId)
    },

    // Plan a next action after the fact (a log that didn't need one at the time).
    async createNextAction(clientId, payload) {
      await this.mutate(() => pipelineApi.createNextAction(clientId, payload))
      return this.loadTimeline(clientId)
    },

    // Corrections — cancel + new version, reason required. Refetch to show history.
    async correctCall(clientId, callId, payload) {
      await this.mutate(() => pipelineApi.correctCall(callId, payload))
      return this.loadTimeline(clientId)
    },

    async correctVisit(clientId, visitId, payload) {
      await this.mutate(() => pipelineApi.correctVisit(visitId, payload))
      return this.loadTimeline(clientId)
    },

    async correctNextAction(clientId, nextActionId, payload) {
      await this.mutate(() => pipelineApi.correctNextAction(nextActionId, payload))
      return this.loadTimeline(clientId)
    },
  },
})
