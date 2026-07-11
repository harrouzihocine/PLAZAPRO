import { defineStore } from 'pinia'
import { publicApi } from './publicApi'

// Public-site data cache. Payloads carry the full {en,fr,ar} marketing copy,
// so a language switch re-renders instantly with no refetch. The freshest
// form_token from ANY public GET is kept for the lead form (min-fill-time:
// the server refuses tokens younger than a few seconds).
export const useShowcaseStore = defineStore('showcase', {
  state: () => ({
    config: null,
    projects: null, // null = never loaded; [] = loaded, none published
    projectCache: {},
    formToken: null,
    loadingConfig: false,
    loadingProjects: false,
  }),

  getters: {
    company: (s) => s.config?.company ?? {},
    stats: (s) => s.config?.stats ?? null,
    whatsappNumber: (s) => s.config?.company?.whatsapp ?? null,
  },

  actions: {
    async loadConfig() {
      if (this.config || this.loadingConfig) return
      this.loadingConfig = true
      try {
        const { data } = await publicApi.config()
        this.config = data.data
        this.formToken = data.data.form_token ?? this.formToken
      } finally {
        this.loadingConfig = false
      }
    },

    async loadProjects() {
      if (this.loadingProjects) return
      this.loadingProjects = true
      try {
        const { data } = await publicApi.projects()
        this.projects = data.data
      } finally {
        this.loadingProjects = false
      }
    },

    async loadProject(id) {
      const { data } = await publicApi.project(id)
      const project = data.data
      this.projectCache[project.id] = project
      this.formToken = project.form_token ?? this.formToken
      return project
    },

    /**
     * Submit a lead. The `website` field is the honeypot — always empty from
     * the real UI; bots that fill it get a fake success server-side.
     */
    async submitLead(payload) {
      await publicApi.submitLead({
        ...payload,
        website: '',
        form_token: this.formToken,
        source_url: window.location.href,
      })
    },
  },
})
