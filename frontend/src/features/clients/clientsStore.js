import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import {
  agentsApi,
  clientsApi,
  dealsApi,
  desireApi,
  followUpAgentsApi,
  projectsApi,
} from '@/features/clients/api'
import { pipelineApi } from '@/features/pipeline/api'
import { cacheSnapshot, serveSnapshot } from '@/features/offline/snapshots'
import { queueable } from '@/features/offline/apiOrQueue'
import { useNetworkStore } from '@/features/offline/networkStore'

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
    page: 1, // current page (server-side pagination)
    rows: 25, // page size
    total: 0, // total matching rows (drives the paginator)
    _fetchTicket: 0, // stale-response guard for auto-applied filters
    loading: false,
    saving: false,
    error: '',
    // Set when data came from the IndexedDB snapshot (offline) — views show
    // an "Offline — data from {time}" stamp; cleared on any live fetch.
    offlineAt: null,
  }),

  actions: {
    async fetch() {
      this.loading = true
      // Filters auto-apply on every change (useAutoFilter): tag the request so
      // a slower, older response can never overwrite a newer one.
      const ticket = ++this._fetchTicket
      // Only the landing view (page 1, no filters) is snapshotted for offline.
      const defaultView =
        this.page === 1 && Object.values(this.filters).every((v) => v === '' || v === null)
      try {
        const params = { page: this.page, per_page: this.rows }
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
        this.items = clients.items
        this.total = clients.total
        this.agents = agents
        this.followUpAgents = followUpAgents
        this.offlineAt = null
        if (defaultView) {
          cacheSnapshot('clients:list', {
            items: this.items,
            total: this.total,
            agents: this.agents,
            followUpAgents: this.followUpAgents,
          })
        }
      } catch (e) {
        if (ticket !== this._fetchTicket) return
        // Only the default view may serve its snapshot — a filtered/paged view
        // must never render page-1 data under the wrong filter UI.
        const served =
          defaultView &&
          (await serveSnapshot(e, 'clients:list', (data, at) => {
            this.items = data.items
            this.total = data.total
            this.agents = data.agents ?? []
            this.followUpAgents = data.followUpAgents ?? []
            this.offlineAt = at
          }))
        if (!served) throw e
      } finally {
        if (ticket === this._fetchTicket) this.loading = false
      }
    },

    // Jump to a page (from the DataTable paginator) and reload.
    goToPage({ page, rows }) {
      this.page = page
      this.rows = rows
      return this.fetch()
    },

    // A filter changed: go back to page 1, then reload.
    applyFilters() {
      this.page = 1
      return this.fetch()
    },

    async load(id) {
      this.loading = true
      try {
        this.current = await clientsApi.get(id)
        this.offlineAt = null
        cacheSnapshot(`clients:file:${id}`, this.current)
        try {
          if (!this.agents.length) this.agents = await agentsApi.list()
          if (!this.followUpAgents.length) this.followUpAgents = await followUpAgentsApi.list()
        } catch {
          /* the agent catalogues only feed pickers — optional offline */
        }
        return this.current
      } catch (e) {
        // A recently-opened client file works offline from its snapshot.
        const served = await serveSnapshot(e, `clients:file:${id}`, (data, at) => {
          this.current = data
          this.offlineAt = at
        })
        if (!served) throw e
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

    // Actions that must NOT queue offline (interactive duplicate resolution,
    // financial closes): toast a clear notice and stop before any request.
    _requireOnline(what) {
      if (!useNetworkStore().requireOnline(`You're offline — ${what} needs a connection.`)) {
        const err = new Error('offline-blocked')
        err.offlineBlocked = true
        throw err
      }
    },

    async create(payload) {
      // The duplicate-phone check must run live (its resolution is interactive).
      this._requireOnline('creating a client')
      this.saving = true
      this.error = ''
      try {
        const result = await clientsApi.create(payload)
        // Show the new client: it lands on page 1 (newest first).
        this.page = 1
        await this.fetch()
        return result
      } catch (e) {
        // A duplicate phone is not a toast — the caller shows the resolution
        // notice (either "already yours" or "a request was sent to a supervisor").
        if (e.response?.status === 409 && e.response.data?.duplicate) {
          const dup = new Error('duplicate')
          dup.duplicate = e.response.data
          throw dup
        }
        this.error = e.response?.data?.message ?? 'Action failed.'
        toastError(this.error)
        throw e
      } finally {
        this.saving = false
      }
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
      try {
        this.projects = await projectsApi.list(clientId)
        cacheSnapshot(`clients:projects:${clientId}`, this.projects)
      } catch (e) {
        const served = await serveSnapshot(e, `clients:projects:${clientId}`, (data, at) => {
          this.projects = data
          this.offlineAt = at
        })
        if (!served) throw e
      }
      return this.projects
    },

    async loadArchivedProjects(clientId) {
      try {
        this.archivedProjects = await projectsApi.list(clientId, 'archived')
        cacheSnapshot(`clients:archived:${clientId}`, this.archivedProjects)
      } catch (e) {
        const served = await serveSnapshot(e, `clients:archived:${clientId}`, (data, at) => {
          this.archivedProjects = data
          this.offlineAt = at
        })
        if (!served) throw e
      }
      return this.archivedProjects
    },

    // Returns the created project (the "new project" flow immediately logs the
    // first call onto it, so the caller needs the id).
    async createProject(clientId, payload = {}) {
      // The new project's id anchors the opening call — it needs the server.
      this._requireOnline('opening a project')
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

    // Freeze / unfreeze: a deliberate close-down to new activity.
    async freezeProject(clientId, projectId) {
      await this.mutate(() => projectsApi.freeze(projectId))
      return this.loadProjects(clientId)
    },

    async unfreezeProject(clientId, projectId) {
      await this.mutate(() => projectsApi.unfreeze(projectId))
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
      try {
        this.desire = await desireApi.get(clientId)
        cacheSnapshot(`clients:desire:${clientId}`, this.desire)
      } catch (e) {
        const served = await serveSnapshot(e, `clients:desire:${clientId}`, (data, at) => {
          this.desire = data
          this.offlineAt = at
        })
        if (!served) throw e
      }
      return this.desire
    },

    async saveDesire(clientId, payload) {
      const res = await this.mutate(() =>
        queueable({
          method: 'put',
          url: `/clients/${clientId}/desire`,
          body: payload,
          label: `Client desire — ${this.current?.name ?? 'client'}`,
          entityHint: { route: `/clients/${clientId}` },
        }),
      )
      if (res.queued) {
        // Keep what the agent typed visible until the sync lands (the upsert
        // is last-write-wins server-side).
        this.desire = { ...(this.desire ?? {}), ...payload }
        return this.desire
      }
      this.desire = res.data?.data ?? res.data
      return this.desire
    },

    async loadMatches(clientId) {
      this.matches = await desireApi.matches(clientId)
      return this.matches
    },


    // --- Deals on a project (created from visit logs; several may be open) ---

    async loadDeals(projectId) {
      this.deals = { ...this.deals, [projectId]: await dealsApi.list(projectId) }
      return this.deals[projectId]
    },

    async createDeal(clientId, projectId, payload) {
      this._requireOnline('opening a deal')
      await this.mutate(() => dealsApi.create(projectId, payload))
      await this.loadDeals(projectId)
      return this.loadProjects(clientId)
    },

    async closeDeal(clientId, projectId, dealId, payload) {
      this._requireOnline('closing a deal')
      await this.mutate(() => dealsApi.close(dealId, payload))
      await this.loadDeals(projectId)
      return this.loadProjects(clientId)
    },

    // Close ONE apartment on the deal (won with its own price / lost).
    async closeDealItem(clientId, projectId, dealId, itemId, payload) {
      this._requireOnline('closing an apartment on the deal')
      await this.mutate(() => dealsApi.closeItem(dealId, itemId, payload))
      await this.loadDeals(projectId)
      return this.loadProjects(clientId)
    },

    // Release a WON apartment — it returns to the market; payments stay as history.
    async releaseDealItem(clientId, projectId, dealId, itemId, payload) {
      this._requireOnline('releasing a won apartment')
      await this.mutate(() => dealsApi.releaseItem(dealId, itemId, payload))
      await this.loadDeals(projectId)
      return this.loadProjects(clientId)
    },

    // Sell extra boxes onto a WON apartment (agreed price grows).
    async addDealBoxes(clientId, projectId, dealId, itemId, payload) {
      this._requireOnline('selling extra boxes')
      await this.mutate(() => dealsApi.addBoxes(dealId, itemId, payload))
      await this.loadDeals(projectId)
      return this.loadProjects(clientId)
    },

    async syncDealUnitBoxes(projectId, dealId, itemId, boxIds) {
      await this.mutate(() => dealsApi.syncUnitBoxes(dealId, itemId, boxIds))
      return this.loadDeals(projectId)
    },

    // --- Interaction chain (calls, visits, next actions) ---

    // Scoped to one project when projectId is given (the per-project story).
    async loadTimeline(clientId, projectId = this.timelineProjectId) {
      this.timelineProjectId = projectId ?? null
      const key = `clients:timeline:${clientId}:${this.timelineProjectId ?? 'all'}`
      try {
        this.timeline = await pipelineApi.timeline(clientId, this.timelineProjectId)
        cacheSnapshot(key, this.timeline)
      } catch (e) {
        const served = await serveSnapshot(e, key, (data, at) => {
          this.timeline = data
          this.offlineAt = at
        })
        if (!served) throw e
      }
      return this.timeline
    },

    // ── The field-agent write set: offline-queueable (X-Idempotency-Key).
    // Online they run straight through; offline (or dead mid-flight) they land
    // in the outbox and replay FIFO on reconnect. A queued action returns null
    // and skips the refetch chain — the server's guards judge it later, and a
    // rejection ("visit already completed", "project frozen"…) surfaces in the
    // Sync Center with the server's reason. ──

    async logCall(clientId, payload) {
      const res = await this.mutate(() =>
        queueable({
          method: 'post',
          url: `/clients/${clientId}/calls`,
          body: payload,
          label: `Call log — ${this.current?.name ?? 'client'}`,
          entityHint: { route: `/clients/${clientId}` },
        }),
      )
      if (res.queued) return null
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
      const res = await this.mutate(() =>
        queueable({
          method: 'post',
          url: `/visits/${visitId}/complete`,
          body: payload,
          label: `Visit completion — ${this.current?.name ?? 'client'}`,
          entityHint: { route: `/clients/${clientId}` },
        }),
      )
      if (res.queued) return null
      return this.loadTimeline(clientId)
    },

    // Add apartment(s) to visit on a project, standalone — no open visit to
    // complete first. The new pending visit(s) / pooled request show in the
    // refreshed timeline.
    async proposeInSiteVisit(clientId, projectId, payload) {
      const res = await this.mutate(() =>
        queueable({
          method: 'post',
          url: `/projects/${projectId}/in-site-visits`,
          body: payload,
          label: `Add unit to visit — ${this.current?.name ?? 'client'}`,
          entityHint: { route: `/clients/${clientId}/projects/${projectId}` },
        }),
      )
      if (res.queued) return null
      return this.loadTimeline(clientId)
    },

    // Plan a next action after the fact (a log that didn't need one at the time).
    async createNextAction(clientId, payload) {
      const res = await this.mutate(() =>
        queueable({
          method: 'post',
          url: `/clients/${clientId}/next-actions`,
          body: payload,
          label: `Next action — ${this.current?.name ?? 'client'}`,
          entityHint: { route: `/clients/${clientId}` },
        }),
      )
      if (res.queued) return null
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
