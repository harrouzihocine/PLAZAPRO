import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { tasksApi } from '@/features/pipeline/api'
import { agentsApi } from '@/features/clients/api'
import { cacheSnapshot, serveSnapshot } from '@/features/offline/snapshots'

// State for the tasks board. Filters are sent to the server (scope mine/team,
// state, priority, overdue); every write refetches so the list reflects server
// rules. Network calls live in pipeline/api.js.
export const useTasksStore = defineStore('tasks', {
  state: () => ({
    items: [],
    agents: [],
    filters: { scope: 'mine', state: '', priority: '', overdue: false },
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
        !this.filters.priority &&
        !this.filters.overdue
      try {
        const params = {}
        for (const [k, v] of Object.entries(this.filters)) {
          if (v !== '' && v !== null && v !== false) params[k] = v
        }
        const [tasks, agents] = await Promise.all([tasksApi.list(params), agentsApi.list()])
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
        this.error = e.response?.data?.message ?? 'Could not create the task.'
        toastError(this.error)
        throw e
      } finally {
        this.saving = false
      }
    },

    async complete(id) {
      await tasksApi.complete(id)
      await this.fetch()
    },

    async cancel(id, reason = 'Task cancelled') {
      await tasksApi.cancel(id, reason)
      await this.fetch()
    },
  },
})
