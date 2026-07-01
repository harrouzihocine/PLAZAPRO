import { defineStore } from 'pinia'
import { agentsApi, clientsApi, desireApi, projectsApi, reserveUnit } from '@/features/clients/api'
import { pipelineApi } from '@/features/pipeline/api'

// State for the Clients screens. Loads clients plus the agent catalogue (for the
// assign-agent picker). Filters are sent to the server; every write refetches so
// server rules (e.g. agent-only assignment) are reflected.
export const useClientsStore = defineStore('clients', {
  state: () => ({
    items: [],
    current: null,
    agents: [],
    projects: [],
    desire: null,
    matches: [],
    timeline: { calls: [], visits: [], next_actions: [] },
    filters: { assigned_agent_id: '', source_id: '', rating_id: '', search: '' },
    loading: false,
    saving: false,
    error: '',
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        const params = {}
        for (const [k, v] of Object.entries(this.filters)) {
          if (v !== '' && v !== null) params[k] = v
        }
        const [clients, agents] = await Promise.all([clientsApi.list(params), agentsApi.list()])
        this.items = clients
        this.agents = agents
      } finally {
        this.loading = false
      }
    },

    async load(id) {
      this.loading = true
      try {
        this.current = await clientsApi.get(id)
        if (!this.agents.length) this.agents = await agentsApi.list()
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

    async createProject(clientId, payload = {}) {
      await this.mutate(() => projectsApi.create(clientId, payload))
      return this.loadProjects(clientId)
    },

    async advanceProject(clientId, projectId, stage) {
      await this.mutate(() => projectsApi.advance(projectId, stage))
      return this.loadProjects(clientId)
    },

    async cancelProject(clientId, projectId, reason = 'Deal cancelled') {
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

    // --- Interaction chain (calls, visits, next actions) ---

    async loadTimeline(clientId) {
      this.timeline = await pipelineApi.timeline(clientId)
      return this.timeline
    },

    async logCall(clientId, payload) {
      await this.mutate(() => pipelineApi.logCall(clientId, payload))
      return this.loadTimeline(clientId)
    },

    async scheduleVisit(clientId, payload) {
      await this.mutate(() => pipelineApi.scheduleVisit(payload))
      return this.loadTimeline(clientId)
    },

    async completeVisit(clientId, visitId, payload) {
      await this.mutate(() => pipelineApi.completeVisit(visitId, payload))
      return this.loadTimeline(clientId)
    },
  },
})
