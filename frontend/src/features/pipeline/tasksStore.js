import { defineStore } from 'pinia'
import { toastError } from '@/composables/useConfirm'
import { tasksApi } from '@/features/pipeline/api'
import { agentsApi } from '@/features/clients/api'

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
  }),

  actions: {
    async fetch() {
      this.loading = true
      try {
        const params = {}
        for (const [k, v] of Object.entries(this.filters)) {
          if (v !== '' && v !== null && v !== false) params[k] = v
        }
        const [tasks, agents] = await Promise.all([tasksApi.list(params), agentsApi.list()])
        this.items = tasks
        this.agents = agents
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
