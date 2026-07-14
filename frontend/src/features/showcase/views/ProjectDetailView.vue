<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import { formatDate } from '@/utils/format'
import { useShowcaseStore } from '../store'
import { pickLocalized } from '../localized'
import { useSeoMeta } from '../composables/useSeoMeta'
import PublicGallery from '../components/PublicGallery.vue'
import AvailabilityGrid from '../components/AvailabilityGrid.vue'
import UnitExplorer from '../components/UnitExplorer.vue'
import PaymentSimulator from '../components/PaymentSimulator.vue'
import CompareDrawer from '../components/CompareDrawer.vue'
import LeadFormModal from '../components/LeadFormModal.vue'
import ShareButton from '../components/ShareButton.vue'

const props = defineProps({
  id: { type: [String, Number], required: true },
})

const showcase = useShowcaseStore()

const project = ref(null)
const notFound = ref(false)
const loading = ref(true)

const leadModal = ref({ open: false, type: 'interest', unit: null })

async function load(id) {
  loading.value = true
  notFound.value = false
  project.value = showcase.projectCache[id] ?? null
  try {
    project.value = await showcase.loadProject(id)
  } catch (e) {
    if (!project.value) notFound.value = e.response?.status === 404
  } finally {
    loading.value = false
  }
}

watch(() => props.id, load, { immediate: true })

const tagline = computed(() => pickLocalized(project.value?.tagline))
const description = computed(() => pickLocalized(project.value?.marketing_description))
const cover = computed(() => {
  const p = project.value
  if (!p?.cover_media_id) return null
  return p.media?.find((m) => m.id === p.cover_media_id) ?? p.media?.find((m) => m.type === 'photo') ?? null
})
const heroImage = computed(() => cover.value ?? project.value?.media?.find((m) => m.type === 'photo') ?? null)
const units = computed(() => project.value?.units ?? [])
const pricedUnits = computed(() =>
  project.value?.show_prices ? units.value.filter((u) => u.available && (u.price_semi_fini || u.price_fini)) : [],
)

function openLead(type, unit = null) {
  leadModal.value = { open: true, type, unit }
}

useSeoMeta(() => ({
  title: project.value ? `${project.value.name} — ${showcase.company.name || 'PLAZA PRO'}` : null,
  description: tagline.value || description.value?.slice(0, 160),
  image: heroImage.value?.file_url,
  // Structured data so search engines read the listing as real estate.
  jsonLd: project.value
    ? {
        '@context': 'https://schema.org',
        '@type': 'ApartmentComplex',
        name: project.value.name,
        description: description.value || tagline.value || undefined,
        url: window.location.href,
        image: heroImage.value ? new URL(heroImage.value.file_url, window.location.origin).href : undefined,
        address: {
          '@type': 'PostalAddress',
          streetAddress: project.value.address || undefined,
          addressLocality: project.value.commune || undefined,
          addressRegion: project.value.wilaya || undefined,
          addressCountry: 'DZ',
        },
      }
    : null,
}))
</script>

<template>
  <div>
    <!-- Not found -->
    <div v-if="notFound" class="mx-auto max-w-xl px-6 pb-24 pt-40 text-center">
      <i class="pi pi-compass text-5xl text-mute" aria-hidden="true" />
      <h1 class="mt-4 text-2xl font-bold text-ink">{{ $t('showcase.project.notFound') }}</h1>
      <p class="mt-2 text-mute">{{ $t('showcase.project.notFoundHint') }}</p>
      <RouterLink :to="{ name: 'showcase.projects' }" class="mt-6 inline-block">
        <Button :label="$t('showcase.projects.title')" icon="pi pi-arrow-left" outlined rounded />
      </RouterLink>
    </div>

    <!-- Loading skeleton (first visit only) -->
    <div v-else-if="loading && !project" class="animate-pulse">
      <div class="h-[55vh] bg-surface-200 dark:bg-surface-800" />
      <div class="mx-auto max-w-6xl space-y-4 px-4 py-10 sm:px-6">
        <div class="h-8 w-1/2 rounded bg-surface-200 dark:bg-surface-800" />
        <div class="h-4 w-2/3 rounded bg-surface-200 dark:bg-surface-800" />
      </div>
    </div>

    <template v-else-if="project">
      <!-- Hero -->
      <section class="relative flex min-h-[55vh] items-end overflow-hidden bg-surface-950 supports-[height:1svh]:min-h-[55svh]">
        <img
          v-if="heroImage"
          :src="heroImage.file_url"
          :alt="project.name"
          class="absolute inset-0 h-full w-full object-cover"
          :style="cover ? { objectPosition: `${project.cover_focus_x}% ${project.cover_focus_y}%` } : null"
        />
        <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/35 to-black/50" aria-hidden="true" />

        <div class="relative mx-auto w-full max-w-6xl px-4 pb-10 pt-28 sm:px-6 sm:pt-36">
          <p v-if="project.type" class="text-sm font-semibold uppercase tracking-widest text-primary-400">
            {{ project.type }}
          </p>
          <h1 class="mt-2 text-3xl font-bold text-white sm:text-5xl">{{ project.name }}</h1>
          <!-- Parked off the market: the page still loads, but says so up front. -->
          <span
            v-if="project.is_available === false"
            class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-black/50 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm"
          >
            <i class="pi pi-eye-slash text-xs" aria-hidden="true" />
            {{ $t('showcase.projects.unavailable') }}
          </span>
          <p v-if="tagline" class="mt-3 max-w-2xl text-lg text-white/85">{{ tagline }}</p>

          <div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/80">
            <span v-if="project.wilaya" class="flex items-center gap-1.5">
              <i class="pi pi-map-marker" aria-hidden="true" />
              {{ [project.address, project.commune, project.wilaya].filter(Boolean).join(', ') }}
            </span>
            <span v-if="project.expected_delivery_date" class="flex items-center gap-1.5">
              <i class="pi pi-calendar" aria-hidden="true" />
              {{ $t('showcase.projects.delivery') }} {{ formatDate(project.expected_delivery_date) }}
            </span>
            <span v-if="project.contract_type" class="flex items-center gap-1.5">
              <i class="pi pi-file" aria-hidden="true" />
              {{ project.contract_type }}
            </span>
          </div>

          <div class="mt-7 flex flex-wrap gap-3">
            <Button
              :label="$t('showcase.project.bookVisit')"
              icon="pi pi-calendar-plus"
              rounded
              @click="openLead('visit_request')"
            />
            <Button
              :label="$t('showcase.project.askInfo')"
              severity="secondary"
              outlined
              rounded
              class="!border-white/40 !text-white hover:!bg-white/10"
              @click="openLead('interest')"
            />
            <ShareButton :title="project.name" :location-id="project.id" />
          </div>
        </div>
      </section>

      <div class="mx-auto max-w-6xl space-y-16 px-4 py-14 sm:px-6">
        <!-- Description + facts -->
        <section class="grid gap-10 md:grid-cols-3">
          <div class="md:col-span-2">
            <h2 class="text-2xl font-bold text-ink">{{ $t('showcase.project.aboutTitle') }}</h2>
            <p class="mt-4 whitespace-pre-line leading-relaxed text-mute">
              {{ description || tagline || $t('showcase.project.aboutFallback') }}
            </p>

            <!-- Construction progress (when the owner publishes it) -->
            <div v-if="project.construction_progress !== null" class="mt-8">
              <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-ink">{{ $t('showcase.project.progress') }}</span>
                <span class="num font-semibold text-primary-500">{{ project.construction_progress }}%</span>
              </div>
              <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-surface-200 dark:bg-surface-800">
                <div
                  class="h-full rounded-full bg-primary-500 transition-all duration-700"
                  :style="{ width: `${project.construction_progress}%` }"
                />
              </div>
            </div>
          </div>

          <aside class="space-y-4 self-start rounded-2xl border border-line bg-card p-6 shadow-card">
            <h3 class="font-semibold text-ink">{{ $t('showcase.project.factsTitle') }}</h3>
            <dl class="space-y-3 text-sm">
              <div v-if="project.show_availability" class="flex justify-between gap-3">
                <dt class="text-mute">{{ $t('showcase.project.factAvailable') }}</dt>
                <dd class="num whitespace-nowrap font-semibold text-ink">{{ units.filter((u) => u.available).length }} / {{ units.length }}</dd>
              </div>
              <div v-if="project.type" class="flex justify-between gap-3">
                <dt class="text-mute">{{ $t('showcase.project.factType') }}</dt>
                <dd class="font-medium text-ink">{{ project.type }}</dd>
              </div>
              <div v-if="project.expected_delivery_date" class="flex justify-between gap-3">
                <dt class="text-mute">{{ $t('showcase.project.factDelivery') }}</dt>
                <dd class="font-medium text-ink">{{ formatDate(project.expected_delivery_date) }}</dd>
              </div>
            </dl>

            <div v-if="project.payment_methods?.length" class="border-t border-line pt-4">
              <h4 class="text-sm font-semibold text-ink">{{ $t('showcase.project.paymentMethods') }}</h4>
              <div class="mt-2.5 flex flex-wrap gap-2">
                <span
                  v-for="method in project.payment_methods"
                  :key="method.id"
                  class="rounded-full bg-primary-500/10 px-3 py-1 text-xs font-medium text-primary-600 dark:text-primary-400"
                >{{ method.label }}</span>
              </div>
            </div>
          </aside>
        </section>

        <!-- Gallery -->
        <PublicGallery v-if="project.media?.length" :media="project.media" />

        <!-- The building elevation: every floor RDC → top, apartments in place -->
        <AvailabilityGrid
          v-if="project.show_availability && units.length"
          :units="units"
          :show-prices="project.show_prices"
          :project-id="project.id"
        />

        <!-- Units -->
        <UnitExplorer
          v-if="project.show_availability && units.length"
          :units="units"
          :show-prices="project.show_prices"
          :project-id="project.id"
          @interested="(unit) => openLead('interest', unit)"
        />

        <!-- Payment simulator -->
        <PaymentSimulator v-if="pricedUnits.length" :units="pricedUnits" />

        <!-- CTA band -->
        <section class="overflow-hidden rounded-3xl bg-surface-950 px-6 py-12 text-center sm:px-12">
          <h2 class="text-2xl font-bold text-white sm:text-3xl">{{ $t('showcase.project.ctaTitle') }}</h2>
          <p class="mx-auto mt-3 max-w-xl text-white/75">{{ $t('showcase.project.ctaBody') }}</p>
          <div class="mt-7 flex flex-wrap justify-center gap-3">
            <Button
              :label="$t('showcase.project.bookVisit')"
              icon="pi pi-calendar-plus"
              size="large"
              rounded
              @click="openLead('visit_request')"
            />
            <Button
              :label="$t('showcase.project.askInfo')"
              severity="secondary"
              outlined
              size="large"
              rounded
              class="!border-white/40 !text-white hover:!bg-white/10"
              @click="openLead('interest')"
            />
          </div>
        </section>
      </div>

      <!-- Visitor shortlist tray + side-by-side comparison -->
      <CompareDrawer
        v-if="project.show_availability && units.length"
        :project-id="project.id"
        :units="units"
        :show-prices="project.show_prices"
        @interested="(unit) => openLead('interest', unit)"
      />

      <LeadFormModal
        v-model:open="leadModal.open"
        :type="leadModal.type"
        :project="project"
        :unit="leadModal.unit"
      />
    </template>
  </div>
</template>
