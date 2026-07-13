import { defineStore } from 'pinia'
import { publicApi } from './publicApi'
import { track } from './composables/useTracker'

// Public-site data cache. Payloads carry the full {en,fr,ar} marketing copy,
// so a language switch re-renders instantly with no refetch. The freshest
// form_token from ANY public GET is kept for the lead form (min-fill-time:
// the server refuses tokens younger than a few seconds).
export const useShowcaseStore = defineStore('showcase', {
  state: () => ({
    config: null,
    projects: null, // null = never loaded; [] = loaded, none published
    projectCache: {},
    unitCache: {},
    desireOptions: null, // geography + type/rooms vocabularies for the desire form
    formToken: null,
    loadingConfig: false,
    loadingProjects: false,
    loadingDesireOptions: false,
  }),

  getters: {
    company: (s) => s.config?.company ?? {},
    stats: (s) => s.config?.stats ?? null,
    whatsappNumber: (s) => s.config?.company?.whatsapp ?? null,
    phones: (s) => s.config?.company?.phones ?? [],
    // The footer's social row — only the filled profiles, in a fixed order.
    socialLinks: (s) => {
      const c = s.config?.company ?? {}
      return [
        { key: 'facebook', url: c.facebook_url, icon: 'pi pi-facebook', label: 'Facebook' },
        { key: 'instagram', url: c.instagram_url, icon: 'pi pi-instagram', label: 'Instagram' },
        { key: 'tiktok', url: c.tiktok_url, icon: 'pi pi-tiktok', label: 'TikTok' },
        { key: 'youtube', url: c.youtube_url, icon: 'pi pi-youtube', label: 'YouTube' },
        { key: 'linkedin', url: c.linkedin_url, icon: 'pi pi-linkedin', label: 'LinkedIn' },
        { key: 'x', url: c.x_url, icon: 'pi pi-twitter', label: 'X' },
      ].filter((s2) => s2.url)
    },
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

    async loadDesireOptions() {
      if (this.desireOptions || this.loadingDesireOptions) return
      this.loadingDesireOptions = true
      try {
        const { data } = await publicApi.desireOptions()
        this.desireOptions = data.data
        this.formToken = data.data.form_token ?? this.formToken
      } finally {
        this.loadingDesireOptions = false
      }
    },

    async loadUnit(projectId, unitId) {
      const { data } = await publicApi.unit(projectId, unitId)
      const unit = data.data
      this.unitCache[`${projectId}:${unitId}`] = unit
      this.formToken = unit.form_token ?? this.formToken
      return unit
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
      track('lead_submit', {
        location_id: payload.location_id ?? null,
        unit_id: payload.unit_id ?? null,
      })
    },
  },
})
