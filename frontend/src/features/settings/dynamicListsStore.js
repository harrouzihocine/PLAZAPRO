import { defineStore } from 'pinia'
import { dynamicListsApi } from '@/features/settings/api'
import { invalidateDynamicList } from '@/composables/useDynamicList'

// State for the Lists admin screen. Network lives in api.js; after any change we
// invalidate the shared dropdown cache so consumers app-wide pick up the edit.
export const useDynamicListsStore = defineStore('dynamicLists', {
  state: () => ({
    lists: [],
    selectedKey: null,
    items: [],
    loading: false,
    saving: false,
    error: '',
  }),

  getters: {
    selected: (state) => state.lists.find((l) => l.key === state.selectedKey) ?? null,
  },

  actions: {
    async fetchLists() {
      this.loading = true
      try {
        this.lists = await dynamicListsApi.list()
        if (!this.selectedKey && this.lists.length) {
          await this.select(this.lists[0].key)
        }
      } finally {
        this.loading = false
      }
    },

    async select(key) {
      this.selectedKey = key
      this.error = ''
      this.items = await dynamicListsApi.items(key)
    },

    async refresh() {
      invalidateDynamicList(this.selectedKey) // dropdowns refetch on next use
      this.items = await dynamicListsApi.items(this.selectedKey)
    },

    async mutate(fn) {
      this.saving = true
      this.error = ''
      try {
        await fn()
        await this.refresh()
      } catch (e) {
        this.error = e.response?.data?.message ?? 'Action failed.'
        throw e
      } finally {
        this.saving = false
      }
    },

    addItem(payload) {
      return this.mutate(() => dynamicListsApi.createItem(this.selectedKey, payload))
    },

    updateItem(id, payload) {
      return this.mutate(() => dynamicListsApi.updateItem(this.selectedKey, id, payload))
    },

    deactivateItem(id) {
      return this.mutate(() => dynamicListsApi.deactivateItem(this.selectedKey, id))
    },

    reorder(order) {
      return this.mutate(() => dynamicListsApi.reorder(this.selectedKey, order))
    },
  },
})
