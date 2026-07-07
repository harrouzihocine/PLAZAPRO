<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useDynamicList } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { GTM_PRIORITIES } from '@/features/inventory/api'
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
}
const form = reactive({ ...blank })
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
  Object.assign(form, blank)
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
  })
  loadFormCommunes(loc.wilaya_id)
  editingId.value = loc.id
  showMap.value = loc.latitude != null && loc.longitude != null
  showForm.value = true
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
      title: `Archive project "${loc.name}"?`,
      text: 'This archives the project and all its units and boxes. You can reactivate it later.',
      confirmText: 'Archive',
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
      title: `Remove project "${loc.name}"?`,
      text: 'This removes the project and everything inside it. The records are kept but marked cancelled.',
      confirmText: 'Remove',
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
    <PageHeader title="Projects" subtitle="Real-estate projects, buildings and sites.">
      <template #actions>
        <Button v-if="canManage" label="New project" icon="pi pi-plus" @click="openCreate" />
      </template>
    </PageHeader>

    <!-- Filter toolbar -->
    <FilterPanel card :active-count="activeFilterCount" class="mb-5">
    <div class="flex flex-wrap items-center gap-2 max-sm:px-4 max-sm:py-3">
      <div class="relative w-full sm:w-64">
        <i
          class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm text-mute"
          aria-hidden="true"
        />
        <InputText
          v-model="store.filters.q"
          placeholder="Search name or code…"
          class="w-full !pl-9"
        />
      </div>
      <BaseSelect
        v-model="store.filters.wilaya_id"
        placeholder="All wilayas"
        aria-label="Filter by wilaya"
        class="w-full sm:w-52"
        :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
      />
      <BaseSelect
        v-model="store.filters.commune_id"
        placeholder="All communes"
        aria-label="Filter by commune"
        class="w-full sm:w-48"
        :disabled="!store.filters.wilaya_id"
        :options="filterCommunes.map((c) => ({ value: c.id, label: c.name }))"
      />
      <BaseSelect
        v-model="store.filters.priority"
        placeholder="All priorities"
        aria-label="Filter by GTM priority"
        class="w-full sm:w-44"
        :options="GTM_PRIORITIES"
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
        title="No projects yet"
        :body="
          canManage
            ? 'Create the first project to start loading inventory.'
            : 'Projects will appear here.'
        "
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
        :label="`${showArchived ? 'Hide' : 'Show'} archived projects`"
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
            <span class="ml-2 text-xs text-mute">
              {{ loc.code }}<template v-if="loc.wilaya"> · {{ loc.wilaya.name }}</template
              ><template v-if="loc.commune"> ({{ loc.commune.name }})</template>
            </span>
            <span class="ml-2 text-xs uppercase text-mute">archived</span>
          </div>
          <Button
            label="Reactivate"
            icon="pi pi-undo"
            size="small"
            outlined
            @click="reactivate(loc)"
          />
        </div>
        <p v-if="!store.archivedItems.length" class="py-2 text-center text-sm text-mute">
          No archived projects.
        </p>
      </div>
    </div>

    <!-- Create / edit drawer -->
    <Drawer
      v-model:visible="showForm"
      position="right"
      class="!w-full sm:!w-[540px]"
      :header="editingId ? 'Edit project' : 'New project'"
    >
      <form v-if="canManage" class="space-y-4" @submit.prevent="submit">
        <div class="grid gap-3 sm:grid-cols-2">
          <BaseInput v-model="form.name" label="Name" required />
          <BaseInput v-model="form.code" label="Code" required />
          <BaseSelect
            v-model="form.wilaya_id"
            label="Wilaya"
            placeholder="— none —"
            :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
          />
          <BaseSelect
            v-model="form.commune_id"
            label="Commune"
            placeholder="— none —"
            :disabled="!form.wilaya_id"
            :options="formCommunes.map((c) => ({ value: c.id, label: c.name }))"
          />
          <BaseSelect
            v-model="form.type_id"
            label="Project type"
            placeholder="— none —"
            :options="projectTypes.map((t) => ({ value: t.id, label: t.label }))"
          />
          <BaseSelect
            v-model="form.contract_type_id"
            label="Contract type"
            placeholder="— none —"
            :options="contractTypes.map((t) => ({ value: t.id, label: t.label }))"
          />
          <BaseMultiSelect
            v-model="form.payment_method_ids"
            label="Payment methods offered"
            placeholder="— none —"
            class="sm:col-span-2"
            :options="projectPaymentMethods.map((m) => ({ value: m.id, label: m.label }))"
          />
          <div>
            <BaseInput v-model="form.address" label="Address" />
            <div v-if="mapsUrl" class="mt-1 flex items-center gap-3">
              <a
                :href="mapsUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-sm text-primary-600 hover:underline dark:text-primary-400"
              >
                <i class="pi pi-map-marker text-xs" aria-hidden="true" />
                Open in Google Maps
              </a>
              <button
                type="button"
                title="Copy Maps link"
                aria-label="Copy Maps link"
                class="inline-flex items-center text-primary-600 hover:underline dark:text-primary-400"
                @click="copyToClipboard(mapsUrl, 'Maps link copied')"
              >
                <i class="pi pi-copy text-xs" aria-hidden="true" />
              </button>
            </div>
          </div>
          <BaseInput
            v-model="form.expected_delivery_date"
            label="Expected delivery date"
            type="date"
            :min="todayInput()"
          />
          <BaseSelect
            v-model="form.gtm_priority"
            label="GTM priority"
            :clearable="false"
            :options="GTM_PRIORITIES"
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
            :label="showMap ? 'Hide map' : 'Pick location on map'"
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
        <BaseTextarea v-model="form.description" label="Description" :rows="3" />
        <div class="flex gap-2 pt-1">
          <Button type="submit" label="Save" icon="pi pi-check" :loading="store.saving" />
          <Button
            type="button"
            label="Cancel"
            severity="secondary"
            outlined
            @click="showForm = false"
          />
        </div>
      </form>
    </Drawer>
  </div>
</template>
