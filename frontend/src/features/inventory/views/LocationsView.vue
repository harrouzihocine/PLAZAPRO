<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Skeleton from 'primevue/skeleton'
import BaseSelect from '@/components/base/BaseSelect.vue'
import OfflineStamp from '@/components/ui/OfflineStamp.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { gtmPriorityOptions } from '@/features/inventory/api'
import { countActiveFilters } from '@/utils/format'
import LocationCard from '@/features/inventory/components/LocationCard.vue'
import LocationFormDrawer from '@/features/inventory/components/LocationFormDrawer.vue'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'
import { t } from '@/i18n'

const store = useLocationsStore()
const auth = useAuthStore()
const { wilayas } = useWilayas()
const { communes: filterCommunes, load: loadFilterCommunes } = useCommunes()

const canManage = auth.can('locations.manage')

// The create/edit form lives in LocationFormDrawer; here we only choose which
// project it edits (null = create) and whether it is open.
const formLocation = ref(null)
const showForm = ref(false)
// Archived projects are lazy-loaded the first time the section is opened.
const showArchived = ref(false)

onMounted(() => store.fetch())
useRefreshable(() => store.fetch()) // pull-to-refresh (APK)

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
  formLocation.value = null
  showForm.value = true
}

function openEdit(loc) {
  formLocation.value = loc
  showForm.value = true
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
    <LocationFormDrawer v-if="canManage" v-model:visible="showForm" :location="formLocation" />
  </div>
</template>
