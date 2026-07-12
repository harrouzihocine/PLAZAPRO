<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/features/payments/money'
import { formatDate } from '@/utils/format'
import { useShowcaseStore } from '../store'
import { useSeoMeta } from '../composables/useSeoMeta'
import PublicGallery from '../components/PublicGallery.vue'
import PaymentSimulator from '../components/PaymentSimulator.vue'
import LeadFormModal from '../components/LeadFormModal.vue'
import ShareButton from '../components/ShareButton.vue'

// One apartment's own page: hero, full specs, its gallery and the lead CTA.
// Exists only while the project is published + advertises availability (the
// API 404s otherwise, mirroring the project page's unit list).

const props = defineProps({
  id: { type: [String, Number], required: true },
  unitId: { type: [String, Number], required: true },
})

const showcase = useShowcaseStore()
const { t } = useI18n()

const unit = ref(null)
const notFound = ref(false)
const loading = ref(true)
const leadOpen = ref(false)

async function load() {
  loading.value = true
  notFound.value = false
  unit.value = showcase.unitCache[`${props.id}:${props.unitId}`] ?? null
  try {
    unit.value = await showcase.loadUnit(props.id, props.unitId)
  } catch (e) {
    if (!unit.value) notFound.value = e.response?.status === 404
  } finally {
    loading.value = false
  }
}

watch(() => [props.id, props.unitId], load, { immediate: true })

const project = computed(() => unit.value?.project ?? null)
const heroImage = computed(() => unit.value?.media?.find((m) => m.type === 'photo') ?? null)
const title = computed(() =>
  [unit.value?.rooms || t('showcase.units.apartment'), unit.value?.reference].filter(Boolean).join(' — '),
)

const FINISH_LABELS = computed(() => ({
  semi_fini: t('showcase.units.semiFini'),
  fini: t('showcase.units.fini'),
}))

const specs = computed(() => {
  const u = unit.value
  if (!u) return []
  return [
    { key: 'rooms', icon: 'pi pi-home', label: t('showcase.unit.specRooms'), value: u.rooms },
    { key: 'area', icon: 'pi pi-expand', label: t('showcase.unit.specArea'), value: u.area_sqm ? `${Number(u.area_sqm)} m²` : null, num: true },
    { key: 'floor', icon: 'pi pi-sort-amount-up', label: t('showcase.unit.specFloor'), value: u.floor },
    { key: 'block', icon: 'pi pi-table', label: t('showcase.unit.specBlock'), value: u.block },
    {
      key: 'finishes',
      icon: 'pi pi-palette',
      label: t('showcase.unit.specFinishes'),
      value: (u.finishes ?? []).map((f) => FINISH_LABELS.value[f] ?? f).join(' / ') || null,
    },
    { key: 'delivery', icon: 'pi pi-calendar', label: t('showcase.project.factDelivery'), value: project.value?.expected_delivery_date ? formatDate(project.value.expected_delivery_date) : null },
  ].filter((s) => s.value)
})

const prices = computed(() => {
  const u = unit.value
  if (!u || !project.value?.show_prices) return []
  return [
    u.price_semi_fini ? { label: t('showcase.units.semiFini'), value: formatMoney(u.price_semi_fini) } : null,
    u.price_fini ? { label: t('showcase.units.fini'), value: formatMoney(u.price_fini) } : null,
  ].filter(Boolean)
})

const simulatorUnits = computed(() =>
  unit.value?.available && (unit.value.price_semi_fini || unit.value.price_fini) && project.value?.show_prices
    ? [unit.value]
    : [],
)

useSeoMeta(() => ({
  title: unit.value ? `${title.value} — ${project.value?.name ?? ''}` : null,
  description: unit.value
    ? [project.value?.name, unit.value.rooms, unit.value.area_sqm ? `${Number(unit.value.area_sqm)} m²` : null]
        .filter(Boolean)
        .join(' · ')
    : null,
  image: heroImage.value?.file_url,
}))
</script>

<template>
  <div>
    <!-- Not found -->
    <div v-if="notFound" class="mx-auto max-w-xl px-6 pb-24 pt-40 text-center">
      <i class="pi pi-compass text-5xl text-mute" aria-hidden="true" />
      <h1 class="mt-4 text-2xl font-bold text-ink">{{ $t('showcase.unit.notFound') }}</h1>
      <p class="mt-2 text-mute">{{ $t('showcase.project.notFoundHint') }}</p>
      <RouterLink :to="{ name: 'showcase.projects' }" class="mt-6 inline-block">
        <Button :label="$t('showcase.projects.title')" icon="pi pi-arrow-left" outlined rounded />
      </RouterLink>
    </div>

    <!-- Loading skeleton (first visit only) -->
    <div v-else-if="loading && !unit" class="animate-pulse">
      <div class="h-[45vh] bg-surface-200 dark:bg-surface-800" />
      <div class="mx-auto max-w-6xl space-y-4 px-4 py-10 sm:px-6">
        <div class="h-8 w-1/2 rounded bg-surface-200 dark:bg-surface-800" />
        <div class="h-4 w-2/3 rounded bg-surface-200 dark:bg-surface-800" />
      </div>
    </div>

    <template v-else-if="unit">
      <!-- Hero -->
      <section class="relative flex min-h-[45vh] items-end overflow-hidden bg-surface-950 supports-[height:1svh]:min-h-[45svh]">
        <img
          v-if="heroImage"
          :src="heroImage.file_url"
          :alt="title"
          class="absolute inset-0 h-full w-full object-cover"
        />
        <div
          v-else
          class="absolute inset-0 bg-gradient-to-br from-surface-950 via-surface-900 to-primary-950"
          aria-hidden="true"
        />
        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-black/40" aria-hidden="true" />

        <div class="relative mx-auto w-full max-w-6xl px-4 pb-10 pt-32 sm:px-6">
          <!-- Breadcrumb back to the project -->
          <RouterLink
            :to="{ name: 'showcase.project', params: { id: project.id } }"
            class="inline-flex items-center gap-1.5 text-sm font-medium text-white/80 transition-colors hover:text-white"
          >
            <i class="pi pi-arrow-left rtl:rotate-180" aria-hidden="true" />
            {{ project.name }}
          </RouterLink>

          <h1 class="mt-3 text-3xl font-bold text-white sm:text-4xl">{{ title }}</h1>

          <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/80">
            <span v-if="project.commune || project.wilaya" class="flex items-center gap-1.5">
              <i class="pi pi-map-marker" aria-hidden="true" />
              {{ [project.address, project.commune, project.wilaya].filter(Boolean).join(', ') }}
            </span>
            <span
              class="flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
              :class="unit.available ? 'bg-success/20 text-green-200' : 'bg-white/15 text-white/80'"
            >
              <span class="h-1.5 w-1.5 rounded-full" :class="unit.available ? 'bg-green-300' : 'bg-white/60'" aria-hidden="true" />
              {{ unit.available ? $t('showcase.units.statusAvailable') : $t('showcase.units.statusTaken') }}
            </span>
          </div>

          <div class="mt-6 flex flex-wrap gap-3">
            <Button
              v-if="unit.available"
              :label="$t('showcase.units.interested')"
              icon="pi pi-send"
              rounded
              @click="leadOpen = true"
            />
            <Button
              v-else
              :label="$t('showcase.project.askInfo')"
              severity="secondary"
              outlined
              rounded
              class="!border-white/40 !text-white hover:!bg-white/10"
              @click="leadOpen = true"
            />
            <ShareButton :title="title" :location-id="project.id" />
          </div>
        </div>
      </section>

      <div class="mx-auto max-w-6xl space-y-14 px-4 py-12 sm:px-6">
        <!-- Specs + price card -->
        <section class="grid gap-10 md:grid-cols-3">
          <div class="md:col-span-2">
            <h2 class="text-2xl font-bold text-ink">{{ $t('showcase.unit.specsTitle') }}</h2>
            <dl class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
              <div
                v-for="spec in specs"
                :key="spec.key"
                class="rounded-2xl border border-line bg-card p-4 shadow-card"
              >
                <dt class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-mute">
                  <i :class="spec.icon" class="text-primary-500" aria-hidden="true" />
                  {{ spec.label }}
                </dt>
                <dd class="mt-2 font-semibold text-ink" :class="spec.num ? 'num' : ''">{{ spec.value }}</dd>
              </div>
            </dl>
          </div>

          <aside class="space-y-4 self-start rounded-2xl border border-line bg-card p-6 shadow-card">
            <h3 class="font-semibold text-ink">{{ $t('showcase.unit.priceTitle') }}</h3>
            <template v-if="prices.length">
              <div v-for="price in prices" :key="price.label" class="flex items-baseline justify-between gap-3">
                <span class="text-sm text-mute">{{ price.label }}</span>
                <span class="num text-lg font-bold text-primary-600 dark:text-primary-400">{{ price.value }}</span>
              </div>
            </template>
            <p v-else class="text-sm font-medium text-mute">{{ $t('showcase.projects.priceOnRequest') }}</p>

            <Button
              :label="unit.available ? $t('showcase.units.interested') : $t('showcase.project.askInfo')"
              icon="pi pi-send"
              fluid
              rounded
              @click="leadOpen = true"
            />
          </aside>
        </section>

        <!-- The unit's own gallery -->
        <PublicGallery v-if="unit.media?.length" :media="unit.media" />
        <p v-else class="rounded-xl border border-line bg-card p-8 text-center text-mute">
          {{ $t('showcase.unit.noMedia') }}
        </p>

        <!-- Payment simulator, scoped to this unit -->
        <PaymentSimulator v-if="simulatorUnits.length" :units="simulatorUnits" />
      </div>

      <!-- Sticky mobile CTA (pe-24 keeps the WhatsApp float clear of the button).
           sticky, not fixed: it pins while the page scrolls, then yields so the
           footer is reachable instead of permanently covered. -->
      <div
        v-if="unit.available"
        class="sticky bottom-0 z-30 border-t border-line bg-card/95 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pe-24 backdrop-blur sm:hidden"
      >
        <Button :label="$t('showcase.units.interested')" icon="pi pi-send" fluid rounded @click="leadOpen = true" />
      </div>

      <LeadFormModal
        v-model:open="leadOpen"
        type="interest"
        :project="{ id: project.id, name: project.name }"
        :unit="unit"
      />
    </template>
  </div>
</template>
