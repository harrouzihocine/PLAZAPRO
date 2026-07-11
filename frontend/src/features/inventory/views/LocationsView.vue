<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import Slider from 'primevue/slider'
import ToggleSwitch from 'primevue/toggleswitch'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import OfflineStamp from '@/components/ui/OfflineStamp.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { gtmPriorityOptions } from '@/features/inventory/api'
import { countActiveFilters } from '@/utils/format'
import CoverImageUpload from '@/features/inventory/components/CoverImageUpload.vue'
import LocationCard from '@/features/inventory/components/LocationCard.vue'
import LocationMap from '@/features/inventory/components/LocationMap.vue'
import { googleMapsUrl } from '@/features/inventory/googleMaps'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { todayInput } from '@/utils/format'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'
import { copyToClipboard } from '@/composables/useClipboard'
import { t, isRTL } from '@/i18n'

const store = useLocationsStore()
const auth = useAuthStore()
const { wilayas } = useWilayas()
const { items: projectTypes } = useDynamicList('project_types')
const { items: contractTypes } = useDynamicList('contract_types')
// Financing / payment options this project offers buyers (multi-select).
const { items: projectPaymentMethods } = useDynamicList('project_payment_methods')
// Independent dependent-commune lists for the filter bar and the form.
const { communes: filterCommunes, load: loadFilterCommunes } = useCommunes()
const { communes: formCommunes, load: loadFormCommunes } = useCommunes()

const canManage = auth.can('locations.manage')

const blank = {
  name: '',
  code: '',
  wilaya_id: '',
  commune_id: '',
  type_id: '',
  contract_type_id: '',
  payment_method_ids: [],
  address: '',
  description: '',
  expected_delivery_date: '',
  gtm_priority: 'medium',
  latitude: null,
  longitude: null,
  cover_media_id: null,
  cover_focus_x: 50,
  cover_focus_y: 50,
  // Public-website controls (the /plaza showcase).
  is_published: false,
  show_prices: true,
  show_availability: true,
  marketing_tagline: { en: '', fr: '', ar: '' },
  marketing_description: { en: '', fr: '', ar: '' },
  construction_progress: null,
}
const form = reactive(structuredClone(blank))
// Which language tab of the marketing copy is being edited.
const marketingLang = ref('fr')
const editingId = ref(null)
const showForm = ref(false)
const showMap = ref(false)
const mapsUrl = computed(() => googleMapsUrl(form))
// Archived projects are lazy-loaded the first time the section is opened.
const showArchived = ref(false)

onMounted(() => store.fetch())
useRefreshable(() => store.fetch()) // pull-to-refresh (APK)

// Cascade: reload the dependent commune list when the chosen wilaya changes.
// A user-driven change also clears the previously-picked commune.
watch(
  () => form.wilaya_id,
  (id, prev) => {
    if (prev !== undefined && id !== prev) form.commune_id = ''
    loadFormCommunes(id)
  },
)
const activeFilterCount = computed(() => countActiveFilters(store.filters))

watch(
  () => store.filters.wilaya_id,
  (id, prev) => {
    if (prev !== undefined && id !== prev) store.filters.commune_id = ''
    loadFilterCommunes(id)
  },
)

// Filters apply themselves as they change — no "Filter" button.
useAutoFilter(() => store.filters, () => store.fetch())

function openCreate() {
  Object.assign(form, structuredClone(blank))
  editingId.value = null
  showMap.value = false
  showForm.value = true
}

function openEdit(loc) {
  Object.assign(form, {
    name: loc.name,
    code: loc.code,
    wilaya_id: loc.wilaya_id ?? '',
    commune_id: loc.commune_id ?? '',
    type_id: loc.type_id ?? '',
    contract_type_id: loc.contract_type_id ?? '',
    payment_method_ids: loc.payment_method_ids ?? [],
    address: loc.address ?? '',
    description: loc.description ?? '',
    expected_delivery_date: loc.expected_delivery_date ?? '',
    gtm_priority: loc.gtm_priority ?? 'medium',
    latitude: loc.latitude ?? null,
    longitude: loc.longitude ?? null,
    cover_media_id: loc.cover_media_id ?? null,
    cover_focus_x: loc.cover_focus_x ?? 50,
    cover_focus_y: loc.cover_focus_y ?? 50,
    is_published: !!loc.is_published,
    show_prices: loc.show_prices !== false,
    show_availability: loc.show_availability !== false,
    marketing_tagline: { en: '', fr: '', ar: '', ...(loc.marketing_tagline ?? {}) },
    marketing_description: { en: '', fr: '', ar: '', ...(loc.marketing_description ?? {}) },
    construction_progress: loc.construction_progress ?? null,
  })
  loadFormCommunes(loc.wilaya_id)
  editingId.value = loc.id
  showMap.value = loc.latitude != null && loc.longitude != null
  showForm.value = true
}

// Empty-string translations are dropped; an all-empty object becomes null.
function cleanTranslations(obj) {
  const filled = Object.fromEntries(
    Object.entries(obj ?? {}).filter(([, v]) => (v ?? '').trim() !== '')
      .map(([k, v]) => [k, v.trim()]),
  )
  return Object.keys(filled).length ? filled : null
}

async function submit() {
  const payload = {
    name: form.name.trim(),
    code: form.code.trim(),
    wilaya_id: form.wilaya_id || null,
    commune_id: form.commune_id || null,
    type_id: form.type_id || null,
    contract_type_id: form.contract_type_id || null,
    payment_method_ids: form.payment_method_ids ?? [],
    address: form.address.trim() || null,
    description: form.description.trim() || null,
    expected_delivery_date: form.expected_delivery_date || null,
    gtm_priority: form.gtm_priority,
    latitude: form.latitude ?? null,
    longitude: form.longitude ?? null,
    cover_media_id: form.cover_media_id ?? null,
    cover_focus_x: form.cover_focus_x ?? 50,
    cover_focus_y: form.cover_focus_y ?? 50,
    is_published: form.is_published,
    show_prices: form.show_prices,
    show_availability: form.show_availability,
    marketing_tagline: cleanTranslations(form.marketing_tagline),
    marketing_description: cleanTranslations(form.marketing_description),
    construction_progress: form.construction_progress ?? null,
  }
  try {
    if (editingId.value) {
      await store.update(editingId.value, payload)
    } else {
      await store.create(payload)
    }
    showForm.value = false
  } catch {
    /* error surfaced via store.error */
  }
}

// Archive: reversible. Hides the project and everything inside it until reactivated.
async function archive(loc) {
  if (
    await confirmAction({
      title: t('inventory.archiveProjectTitle', { name: loc.name }),
      text: t('inventory.archiveProjectText'),
      confirmText: t('project.archive'),
    })
  ) {
    store.archive(loc.id)
  }
}

function reactivate(loc) {
  store.reactivate(loc.id)
}

// Remove: terminal. Cancels the project and its inventory (kept + audited, not deleted).
async function remove(loc) {
  if (
    await confirmAction({
      title: t('inventory.removeProjectTitle', { name: loc.name }),
      text: t('inventory.removeProjectText'),
      confirmText: t('common.remove'),
      danger: true,
    })
  ) {
    store.cancel(loc.id)
  }
}

function toggleArchived() {
  showArchived.value = !showArchived.value
  if (showArchived.value) store.loadArchived()
}
</script>

<template>
  <div>
    <PageHeader :title="$t('inventory.projects')" :subtitle="$t('inventory.projectsSubtitle')">
      <template #actions>
        <Button
          v-if="canManage"
:label="$t('clients.newProject')"
          icon="pi pi-plus"
          class="native-fab"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <OfflineStamp :at="store.offlineAt" />

    <!-- Filter toolbar -->
    <FilterPanel card :active-count="activeFilterCount" class="mb-5">
    <div class="flex flex-wrap items-center gap-2 max-sm:px-4 max-sm:py-3">
      <div class="relative w-full sm:w-64">
        <i
          class="pi pi-search absolute start-3 top-1/2 -translate-y-1/2 text-sm text-mute"
          aria-hidden="true"
        />
        <InputText
          v-model="store.filters.q"
:placeholder="$t('inventory.searchNameCode')"
          class="w-full !ps-9"
        />
      </div>
      <BaseSelect
        v-model="store.filters.wilaya_id"
:placeholder="$t('inventory.allWilayas')"
        :aria-label="$t('inventory.filterByWilaya')"
        class="w-full sm:w-52"
        :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
      />
      <BaseSelect
        v-model="store.filters.commune_id"
:placeholder="$t('inventory.allCommunes')"
        :aria-label="$t('inventory.filterByCommune')"
        class="w-full sm:w-48"
        :disabled="!store.filters.wilaya_id"
        :options="filterCommunes.map((c) => ({ value: c.id, label: c.name }))"
      />
      <BaseSelect
        v-model="store.filters.priority"
:placeholder="$t('tasks.allPriorities')"
        :aria-label="$t('inventory.filterByPriority')"
        class="w-full sm:w-44"
        :options="gtmPriorityOptions()"
      />
    </div>
    </FilterPanel>

    <!-- Project cards -->
    <div v-if="store.loading" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      <Skeleton v-for="i in 6" :key="i" height="9rem" />
    </div>

    <SectionCard v-else-if="!store.items.length">
      <EmptyState
        icon="pi pi-building"
:title="$t('inventory.noProjectsTitle')"
        :body="canManage ? $t('inventory.noProjectsBodyManage') : $t('inventory.noProjectsBody')"
      />
    </SectionCard>

    <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      <LocationCard
        v-for="loc in store.items"
        :key="loc.id"
        :loc="loc"
        :can-manage="canManage"
        @edit="openEdit"
        @archive="archive"
        @remove="remove"
      />
    </div>

    <!-- Archived projects: hidden by default, reactivatable one by one. -->
    <div v-if="canManage" class="mt-6">
      <Button
        :label="showArchived ? $t('inventory.hideArchived') : $t('inventory.showArchived')"
        :icon="showArchived ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
        text
        size="small"
        severity="secondary"
        @click="toggleArchived"
      />
      <div v-if="showArchived" class="mt-3 space-y-2">
        <div
          v-for="loc in store.archivedItems"
          :key="loc.id"
          class="flex flex-col gap-2 rounded-xl border border-dashed border-line bg-card p-3 opacity-90 sm:flex-row sm:items-center"
        >
          <div class="min-w-0 flex-1">
            <span class="font-medium text-ink">{{ loc.name }}</span>
            <span class="ms-2 text-xs text-mute">
              {{ loc.code }}<template v-if="loc.wilaya"> · {{ loc.wilaya.name }}</template
              ><template v-if="loc.commune"> ({{ loc.commune.name }})</template>
            </span>
            <span class="ms-2 text-xs uppercase text-mute">{{ $t('status.archived').toLowerCase() }}</span>
          </div>
          <Button
:label="$t('project.reactivate')"
            icon="pi pi-undo"
            size="small"
            outlined
            @click="reactivate(loc)"
          />
        </div>
        <p v-if="!store.archivedItems.length" class="py-2 text-center text-sm text-mute">
          {{ $t('inventory.noArchivedProjects') }}
        </p>
      </div>
    </div>

    <!-- Create / edit drawer -->
    <Drawer
      v-model:visible="showForm"
:position="isRTL() ? 'left' : 'right'"
      class="!w-full sm:!w-[540px]"
      :header="editingId ? $t('inventory.editProject') : $t('clients.newProject')"
    >
      <form v-if="canManage" class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-3 sm:grid-cols-2">
          <BaseInput v-model="form.name" :label="$t('common.name')" required />
          <BaseInput v-model="form.code" :label="$t('inventory.code')" required />
          <BaseSelect
            v-model="form.wilaya_id"
:label="$t('geo.wilaya')"
            :placeholder="$t('common.none')"
            :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
          />
          <BaseSelect
            v-model="form.commune_id"
:label="$t('geo.commune')"
            :placeholder="$t('common.none')"
            :disabled="!form.wilaya_id"
            :options="formCommunes.map((c) => ({ value: c.id, label: c.name }))"
          />
          <BaseSelect
            v-model="form.type_id"
:label="$t('inventory.projectType')"
            :placeholder="$t('common.none')"
            :options="projectTypes.map((t) => ({ value: t.id, label: itemLabel(t) }))"
          />
          <BaseSelect
            v-model="form.contract_type_id"
:label="$t('inventory.contractType')"
            :placeholder="$t('common.none')"
            :options="contractTypes.map((t) => ({ value: t.id, label: itemLabel(t) }))"
          />
          <BaseMultiSelect
            v-model="form.payment_method_ids"
:label="$t('inventory.paymentMethodsOffered')"
            :placeholder="$t('common.none')"
            class="sm:col-span-2"
            :options="projectPaymentMethods.map((m) => ({ value: m.id, label: itemLabel(m) }))"
          />
          <div>
            <BaseInput v-model="form.address" :label="$t('common.address')" />
            <div v-if="mapsUrl" class="mt-1 flex items-center gap-3">
              <a
                :href="mapsUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-sm text-primary-600 hover:underline dark:text-primary-400"
              >
                <i class="pi pi-map-marker text-xs" aria-hidden="true" />
                {{ $t('inventory.openInMaps') }}
              </a>
              <button
                type="button"
:title="$t('pipeline.copyMapsLink')"
                :aria-label="$t('pipeline.copyMapsLink')"
                class="inline-flex items-center text-primary-600 hover:underline dark:text-primary-400"
                @click="copyToClipboard(mapsUrl, $t('pipeline.mapsLinkCopied'))"
              >
                <i class="pi pi-copy text-xs" aria-hidden="true" />
              </button>
            </div>
          </div>
          <BaseInput
            v-model="form.expected_delivery_date"
:label="$t('inventory.expectedDelivery')"
            type="date"
            :min="todayInput()"
          />
          <BaseSelect
            v-model="form.gtm_priority"
:label="$t('inventory.gtmPriority')"
            :clearable="false"
            :options="gtmPriorityOptions()"
          />
        </div>
        <CoverImageUpload
          v-if="editingId"
          v-model="form.cover_media_id"
          v-model:focus-x="form.cover_focus_x"
          v-model:focus-y="form.cover_focus_y"
          :mediable-id="editingId"
        />
        <div class="space-y-2">
          <Button
            type="button"
            :label="showMap ? $t('inventory.hideMap') : $t('inventory.pickOnMap')"
            icon="pi pi-map"
            text
            size="small"
            @click="showMap = !showMap"
          />
          <LocationMap
            v-if="showMap"
            v-model:latitude="form.latitude"
            v-model:longitude="form.longitude"
            v-model:address="form.address"
            editable
          />
        </div>
        <BaseTextarea v-model="form.description" :label="$t('common.description')" :rows="3" />

        <!-- Public website (/plaza) controls -->
        <fieldset class="rounded-xl border border-line p-4">
          <legend class="px-1 text-sm font-semibold text-ink">
            <i class="pi pi-globe me-1 text-primary-500" aria-hidden="true" />{{ $t('inventory.websitePanel') }}
          </legend>

          <div class="space-y-3">
            <label class="flex items-center justify-between gap-3 text-sm text-ink">
              {{ $t('inventory.websitePublish') }}
              <ToggleSwitch v-model="form.is_published" />
            </label>
            <template v-if="form.is_published">
              <label class="flex items-center justify-between gap-3 text-sm text-ink">
                {{ $t('inventory.websiteShowPrices') }}
                <ToggleSwitch v-model="form.show_prices" />
              </label>
              <label class="flex items-center justify-between gap-3 text-sm text-ink">
                {{ $t('inventory.websiteShowAvailability') }}
                <ToggleSwitch v-model="form.show_availability" />
              </label>

              <!-- Trilingual marketing copy, one language tab at a time -->
              <div class="flex gap-1 pt-1">
                <button
                  v-for="lang in ['fr', 'ar', 'en']"
                  :key="lang"
                  type="button"
                  class="rounded-full px-3 py-1 text-xs font-semibold uppercase transition-colors"
                  :class="marketingLang === lang
                    ? 'bg-primary-500 text-primary-contrast'
                    : 'bg-surface-100 text-mute hover:text-ink dark:bg-surface-800'"
                  @click="marketingLang = lang"
                >{{ lang }}</button>
              </div>
              <BaseInput
                v-model="form.marketing_tagline[marketingLang]"
                :label="$t('inventory.websiteTagline')"
                :maxlength="180"
              />
              <BaseTextarea
                v-model="form.marketing_description[marketingLang]"
                :label="$t('inventory.websiteDescription')"
                :rows="4"
              />

              <!-- Construction advancement, shown as a progress bar on the site -->
              <div>
                <div class="mb-2 flex items-center justify-between">
                  <span class="text-sm font-medium text-ink">{{ $t('inventory.websiteProgress') }}</span>
                  <span class="num text-sm font-semibold" :class="form.construction_progress === null ? 'text-mute' : 'text-primary-500'">
                    {{ form.construction_progress === null ? $t('common.none') : `${form.construction_progress}%` }}
                  </span>
                </div>
                <div class="flex items-center gap-3">
                  <Slider
                    :model-value="form.construction_progress ?? 0"
                    class="w-full"
                    :step="5"
                    @update:model-value="form.construction_progress = $event"
                  />
                  <button
                    v-if="form.construction_progress !== null"
                    type="button"
                    class="text-xs text-mute hover:text-danger"
                    :aria-label="$t('common.cancel')"
                    @click="form.construction_progress = null"
                  ><i class="pi pi-times" aria-hidden="true" /></button>
                </div>
                <p class="mt-1.5 text-xs text-mute">{{ $t('inventory.websiteProgressHint') }}</p>
              </div>

              <p class="text-xs text-mute">{{ $t('inventory.websiteHint') }}</p>
            </template>
          </div>
        </fieldset>

        <div class="flex gap-2 pt-1">
          <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="store.saving" />
          <Button
            type="button"
:label="$t('common.cancel')"
            severity="secondary"
            outlined
            @click="showForm = false"
          />
        </div>
      </form>
    </Drawer>
  </div>
</template>
