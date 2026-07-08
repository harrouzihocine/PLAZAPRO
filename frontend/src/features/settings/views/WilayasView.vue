<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import WilayaFormModal from '@/features/settings/components/WilayaFormModal.vue'
import CommuneFormModal from '@/features/settings/components/CommuneFormModal.vue'
import { geographyApi } from '@/features/settings/api'
import { invalidateWilayas, invalidateCommunes } from '@/composables/useGeography'
import { confirmAction, toastError } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { t } from '@/i18n'

const wilayas = ref([])
const communes = ref([])
const selectedId = ref(null)
const search = ref('')
const loading = ref(false)
const saving = ref(false)

// Modal state for each level (null item = creating).
const wilayaModalOpen = ref(false)
const wilayaModalItem = ref(null)
const communeModalOpen = ref(false)
const communeModalItem = ref(null)

const selected = computed(() => wilayas.value.find((w) => w.id === selectedId.value) ?? null)
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return wilayas.value
  return wilayas.value.filter((w) => w.name.toLowerCase().includes(q) || String(w.code).includes(q))
})

onMounted(loadWilayas)
useRefreshable(loadWilayas) // pull-to-refresh + reconnect self-heal

async function loadWilayas() {
  loading.value = true
  try {
    wilayas.value = await geographyApi.wilayas()
  } finally {
    loading.value = false
  }
}

async function select(wilaya) {
  selectedId.value = wilaya.id
  communes.value = await geographyApi.communes(wilaya.id)
}

// Run a write, then refresh the affected lists and drop the app-wide geo caches
// so dropdowns elsewhere pick up the change on their next use.
async function mutate(fn) {
  saving.value = true
  try {
    await fn()
    invalidateWilayas()
    if (selectedId.value) invalidateCommunes(selectedId.value)
  } catch (e) {
    toastError(e.response?.data?.message ?? t('common.actionFailed'))
    throw e
  } finally {
    saving.value = false
  }
}

/* --- Wilaya --- */
function openCreateWilaya() {
  wilayaModalItem.value = null
  wilayaModalOpen.value = true
}
function openEditWilaya() {
  wilayaModalItem.value = selected.value
  wilayaModalOpen.value = true
}
async function saveWilaya(payload) {
  try {
    await mutate(() =>
      wilayaModalItem.value
        ? geographyApi.updateWilaya(wilayaModalItem.value.id, payload)
        : geographyApi.createWilaya(payload),
    )
    await loadWilayas()
    wilayaModalOpen.value = false
  } catch {
    /* surfaced via toast */
  }
}
async function removeWilaya(wilaya) {
  if (
    !(await confirmAction({
      title: t('geoAdmin.removeWilayaTitle', { name: wilaya.name }),
      text: t('geoAdmin.removeWilayaText'),
      confirmText: t('common.remove'),
      danger: true,
    }))
  )
    return
  try {
    await mutate(() => geographyApi.cancelWilaya(wilaya.id))
    if (selectedId.value === wilaya.id) {
      selectedId.value = null
      communes.value = []
    }
    await loadWilayas()
  } catch {
    /* surfaced via toast */
  }
}

/* --- Commune --- */
function openCreateCommune() {
  communeModalItem.value = null
  communeModalOpen.value = true
}
function openEditCommune(commune) {
  communeModalItem.value = commune
  communeModalOpen.value = true
}
async function saveCommune(payload) {
  try {
    await mutate(() =>
      communeModalItem.value
        ? geographyApi.updateCommune(communeModalItem.value.id, payload)
        : geographyApi.createCommune(selected.value.id, payload),
    )
    communes.value = await geographyApi.communes(selected.value.id)
    await loadWilayas() // refresh communes_count
    communeModalOpen.value = false
  } catch {
    /* surfaced via toast */
  }
}
async function removeCommune(commune) {
  if (
    !(await confirmAction({
      title: t('geoAdmin.removeCommuneTitle', { name: commune.name }),
      text: t('geoAdmin.removeCommuneText'),
      confirmText: t('common.remove'),
      danger: true,
    }))
  )
    return
  try {
    await mutate(() => geographyApi.cancelCommune(commune.id))
    communes.value = await geographyApi.communes(selected.value.id)
    await loadWilayas()
  } catch {
    /* surfaced via toast */
  }
}
</script>

<template>
  <div>
    <PageHeader
:title="$t('geoAdmin.title')"
      :subtitle="$t('geoAdmin.subtitle')"
    >
      <template #actions>
        <Button :label="$t('geoAdmin.addWilaya')" icon="pi pi-plus" class="native-fab" @click="openCreateWilaya" />
      </template>
    </PageHeader>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[20rem_minmax(0,1fr)]">
      <!-- Wilaya picker -->
      <SectionCard :title="`Wilayas (${wilayas.length})`" icon="pi pi-map" class="self-start">
        <BaseInput v-model="search" :placeholder="$t('geoAdmin.searchWilayas')" class="mb-3" />
        <p v-if="loading" class="py-2 text-center text-sm text-mute">Loading…</p>
        <nav v-else class="-mx-1 flex max-h-[26rem] flex-col gap-0.5 overflow-y-auto px-1">
          <button
            v-for="w in filtered"
            :key="w.id"
            type="button"
            class="flex items-center justify-between rounded-lg px-3 py-2 text-start text-sm transition-colors"
            :class="
              w.id === selectedId
                ? 'bg-highlight font-semibold text-ink'
                : 'text-mute hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800'
            "
            @click="select(w)"
          >
            <span class="truncate">
              <span class="num text-mute">{{ w.code }}</span> · {{ w.name }}
            </span>
            <span class="num text-xs text-mute">{{ w.communes_count }}</span>
          </button>
          <p v-if="!filtered.length" class="py-3 text-center text-sm text-mute">No wilayas found.</p>
        </nav>
      </SectionCard>

      <!-- Commune manager -->
      <SectionCard v-if="selected">
        <template #header>
          <div class="min-w-0">
            <h2 class="text-sm font-semibold text-ink">
              <span class="num text-mute">{{ selected.code }}</span> · {{ selected.name }}
            </h2>
            <p class="mt-0.5 text-xs text-mute">{{ communes.length }} communes</p>
          </div>
        </template>
        <template #actions>
          <Button
            icon="pi pi-pencil"
:label="$t('common.edit')"
            size="small"
            severity="secondary"
            outlined
            @click="openEditWilaya"
          />
          <Button
            icon="pi pi-ban"
:label="$t('common.remove')"
            size="small"
            severity="danger"
            outlined
            @click="removeWilaya(selected)"
          />
          <Button :label="$t('geoAdmin.addCommune')" icon="pi pi-plus" size="small" @click="openCreateCommune" />
        </template>

        <div class="space-y-2">
          <div
            v-for="c in communes"
            :key="c.id"
            class="flex items-center gap-3 rounded-xl border border-line px-3 py-2.5"
          >
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-ink">{{ c.name }}</p>
              <p v-if="c.daira_name" class="truncate text-xs text-mute">Daïra: {{ c.daira_name }}</p>
            </div>
            <Button
              icon="pi pi-pencil"
              text
              rounded
              size="small"
              severity="secondary"
:aria-label="$t('geoAdmin.editCommune')"
              @click="openEditCommune(c)"
            />
            <Button
              icon="pi pi-ban"
              text
              rounded
              size="small"
              severity="danger"
:aria-label="$t('geoAdmin.removeCommune')"
              @click="removeCommune(c)"
            />
          </div>
          <EmptyState
            v-if="!communes.length"
            icon="pi pi-map-marker"
:title="$t('geoAdmin.noCommunes')"
            :body="$t('geoAdmin.noCommunesBody')"
          />
        </div>
      </SectionCard>

      <SectionCard v-else>
        <EmptyState
          icon="pi pi-map"
:title="$t('geoAdmin.selectWilaya')"
          :body="$t('geoAdmin.selectWilayaBody')"
        />
      </SectionCard>
    </div>

    <WilayaFormModal
      v-if="wilayaModalOpen"
      :wilaya="wilayaModalItem"
      :saving="saving"
      @save="saveWilaya"
      @close="wilayaModalOpen = false"
    />
    <CommuneFormModal
      v-if="communeModalOpen"
      :commune="communeModalItem"
      :wilaya-name="selected?.name"
      :saving="saving"
      @save="saveCommune"
      @close="communeModalOpen = false"
    />
  </div>
</template>
