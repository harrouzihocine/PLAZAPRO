import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { tasksApi } from '@/features/pipeline/api'
import { agentsApi } from '@/features/clients/api'
import { useAuthStore } from '@/features/settings/store'
import { cacheSnapshot, serveSnapshot } from '@/features/offline/snapshots'
import { t } from '@/i18n'

// State for the tasks board. Filters are sent to the server (scope mine/team,
// state, priority, overdue); every write refetches so the list reflects server
// rules. Network calls live in pipeline/api.js.
export const useTasksStore = defineStore('tasks', {
  state: () => ({
    items: [],
    agents: [],
    filters: { scope: 'mine', state: '', category: '', priority: '', overdue: false },
    loading: false,
    saving: false,
    error: '',
    offlineAt: null, // data served from the offline snapshot (views show a stamp)
  }),

  actions: {
    async fetch() {
      this.loading = true
      // Offline snapshot covers the default view ("mine", no extra filters).
      const defaultView =
        this.filters.scope === 'mine' &&
        !this.filters.state &&
        !this.filters.category &&
        !this.filters.priority &&
        !this.filters.overdue
      try {
        const params = {}
        for (const [k, v] of Object.entries(this.filters)) {
          if (v !== '' && v !== null && v !== false) params[k] = v
        }
        // The agents list only feeds the assignee picker (tasks.assign holders)
        // and never changes mid-session — fetch it once, and never for others.
        const needAgents = useAuthStore().can('tasks.assign') && !this.agents.length
        const [tasks, agents] = await Promise.all([
          tasksApi.list(params),
          needAgents ? agentsApi.list() : Promise.resolve(this.agents),
        ])
        this.items = tasks
        this.agents = agents
        this.offlineAt = null
        if (defaultView) cacheSnapshot('tasks:list', { items: tasks, agents })
      } catch (e) {
        // Only the default view may serve its snapshot — a filtered view must
        // never render default-view data under the wrong filter chips.
        const served =
          defaultView &&
          (await serveSnapshot(e, 'tasks:list', (data, at) => {
            this.items = data.items
            this.agents = data.agents ?? []
            this.offlineAt = at
          }))
        if (!served) throw e
      } finally {
        this.loading = false
      }
    },

    async create(payload) {
      this.saving = true
      this.error = ''
      try {
        await tasksApi.create(payload)
        await this.fetch()
      } catch (e) {
        this.error = e.response?.data?.message ?? t('tasks.createFailed')
        toastError(this.error)
        throw e
      } finally {
        this.saving = false
      }
    },

    // report = the completion form (summary/outcome/difficulties/time spent).
    async complete(id, report) {
      this.saving = true
      try {
        await tasksApi.complete(id, report)
        await this.fetch()
      } finally {
        this.saving = false
      }
    },

    async cancel(id, reason = 'Task cancelled') {
      await tasksApi.cancel(id, reason)
      await this.fetch()
    },
  },
})
