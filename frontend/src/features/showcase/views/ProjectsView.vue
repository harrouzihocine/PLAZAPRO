<script setup>
import { computed, onMounted, ref } from 'vue'
import Select from 'primevue/select'
import ToggleButton from 'primevue/togglebutton'
import { useI18n } from 'vue-i18n'
import { useShowcaseStore } from '../store'
import { useSeoMeta } from '../composables/useSeoMeta'
import ProjectCard from '../components/ProjectCard.vue'

const showcase = useShowcaseStore()
const { t } = useI18n()

onMounted(() => {
  showcase.loadConfig()
  showcase.loadProjects()
})

const wilaya = ref(null)
const type = ref(null)
const availableOnly = ref(false)

const projects = computed(() => showcase.projects ?? [])

// Filter options derive from the loaded list (a promoter has dozens of
// projects at most — everything is client-side).
const wilayaOptions = computed(() =>
  [...new Set(projects.value.map((p) => p.wilaya).filter(Boolean))].sort().map((w) => ({ label: w, value: w })),
)
const typeOptions = computed(() => {
  const seen = new Map()
  for (const p of projects.value) {
    if (p.type_id && !seen.has(p.type_id)) seen.set(p.type_id, { label: p.type, value: p.type_id })
  }
  return [...seen.values()]
})

const filtered = computed(() =>
  projects.value.filter(
    (p) =>
      (!wilaya.value || p.wilaya === wilaya.value) &&
      (!type.value || p.type_id === type.value) &&
      (!availableOnly.value || !p.show_availability || p.available_count > 0),
  ),
)

useSeoMeta(() => ({
  title: `${t('showcase.projects.title')} — ${showcase.company.name || 'PLAZA PRO'}`,
  description: t('showcase.projects.subtitle'),
}))
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 pb-20 pt-28 sm:px-6">
    <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
      {{ $t('showcase.projects.kicker') }}
    </p>
    <h1 class="mt-2 text-3xl font-bold text-ink sm:text-4xl">{{ $t('showcase.projects.title') }}</h1>
    <p class="mt-3 max-w-2xl text-mute">{{ $t('showcase.projects.subtitle') }}</p>

    <!-- Filters -->
    <div class="mt-8 flex flex-wrap items-center gap-3">
      <Select
        v-model="wilaya"
        :options="wilayaOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="$t('showcase.projects.filterWilaya')"
        class="w-full sm:w-56"
      />
      <Select
        v-model="type"
        :options="typeOptions"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="$t('showcase.projects.filterType')"
        class="w-full sm:w-56"
      />
      <ToggleButton
        v-model="availableOnly"
        :on-label="$t('showcase.projects.availableOnly')"
        :off-label="$t('showcase.projects.availableOnly')"
        on-icon="pi pi-check"
        off-icon="pi pi-filter"
        size="small"
      />
    </div>

    <!-- Grid -->
    <div v-if="showcase.loadingProjects && !projects.length" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      <div v-for="n in 6" :key="n" class="h-80 animate-pulse rounded-2xl border border-line bg-card" />
    </div>

    <div v-else-if="filtered.length" class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      <ProjectCard v-for="project in filtered" :key="project.id" :project="project" />
    </div>

    <p v-else class="mt-10 rounded-xl border border-line bg-card p-10 text-center text-mute">
      {{ $t('showcase.projects.empty') }}
    </p>
  </div>
</template>
