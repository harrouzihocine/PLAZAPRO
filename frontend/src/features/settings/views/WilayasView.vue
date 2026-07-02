<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { geographyApi } from '@/features/settings/api'
import { invalidateWilayas, invalidateCommunes } from '@/composables/useGeography'
import { confirmAction, toastError } from '@/composables/useConfirm'

const wilayas = ref([])
const communes = ref([])
const selectedId = ref(null)
const search = ref('')
const loading = ref(false)
const saving = ref(false)

const newWilaya = reactive({ code: '', name: '' })
const newCommune = reactive({ name: '', daira_name: '' })

const selected = computed(() => wilayas.value.find((w) => w.id === selectedId.value) ?? null)
const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return wilayas.value
  return wilayas.value.filter(
    (w) => w.name.toLowerCase().includes(q) || String(w.code).includes(q),
  )
})

onMounted(loadWilayas)

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
    toastError(e.response?.data?.message ?? 'Action failed.')
    throw e
  } finally {
    saving.value = false
  }
}

async function addWilaya() {
  if (!newWilaya.code.trim() || !newWilaya.name.trim()) return
  try {
    await mutate(() => geographyApi.createWilaya({ code: newWilaya.code.trim(), name: newWilaya.name.trim() }))
    newWilaya.code = ''
    newWilaya.name = ''
    await loadWilayas()
  } catch {
    /* surfaced via error */
  }
}

async function renameWilaya() {
  if (!selected.value) return
  try {
    await mutate(() =>
      geographyApi.updateWilaya(selected.value.id, {
        code: selected.value.code,
        name: selected.value.name,
      }),
    )
    await loadWilayas()
  } catch {
    /* surfaced via error */
  }
}

async function removeWilaya(wilaya) {
  if (
    !(await confirmAction({
      title: `Remove wilaya "${wilaya.name}"?`,
      text: 'The record is kept but marked cancelled. It must have no communes or references.',
      confirmText: 'Remove',
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
    /* surfaced via error */
  }
}

async function addCommune() {
  if (!selected.value || !newCommune.name.trim()) return
  try {
    await mutate(() =>
      geographyApi.createCommune(selected.value.id, {
        name: newCommune.name.trim(),
        daira_name: newCommune.daira_name.trim() || null,
      }),
    )
    newCommune.name = ''
    newCommune.daira_name = ''
    communes.value = await geographyApi.communes(selected.value.id)
    await loadWilayas() // refresh communes_count
  } catch {
    /* surfaced via error */
  }
}

async function saveCommune(commune) {
  try {
    await mutate(() =>
      geographyApi.updateCommune(commune.id, { name: commune.name, daira_name: commune.daira_name }),
    )
  } catch {
    /* surfaced via error */
  }
}

async function removeCommune(commune) {
  if (
    !(await confirmAction({
      title: `Remove commune "${commune.name}"?`,
      text: 'The record is kept but marked cancelled. It must have no references.',
      confirmText: 'Remove',
      danger: true,
    }))
  )
    return
  try {
    await mutate(() => geographyApi.cancelCommune(commune.id))
    communes.value = await geographyApi.communes(selected.value.id)
    await loadWilayas()
  } catch {
    /* surfaced via error */
  }
}
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">Wilayas &amp; Communes</h1>
      <p class="opacity-70">Manage Algeria's wilayas and the communes that belong to each one.</p>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-[20rem_1fr]">
      <!-- Wilaya picker -->
      <BaseCard>
        <h2 class="mb-2 font-medium">Wilayas ({{ wilayas.length }})</h2>
        <BaseInput v-model="search" label="Search" class="mb-2" />
        <p v-if="loading" class="py-2 text-center text-sm opacity-60">Loading…</p>
        <nav v-else class="flex max-h-[26rem] flex-col gap-1 overflow-y-auto">
          <button
            v-for="w in filtered"
            :key="w.id"
            class="flex items-center justify-between rounded-token px-3 py-2 text-left hover:bg-bg"
            :class="{ 'bg-bg text-primary': w.id === selectedId }"
            @click="select(w)"
          >
            <span><span class="opacity-50">{{ w.code }}</span> · {{ w.name }}</span>
            <span class="text-xs opacity-50">{{ w.communes_count }}</span>
          </button>
        </nav>

        <!-- Add wilaya -->
        <form class="mt-3 flex flex-col gap-2 border-t border-border pt-3" @submit.prevent="addWilaya">
          <div class="flex gap-2">
            <BaseInput v-model="newWilaya.code" label="Code" class="w-20" />
            <BaseInput v-model="newWilaya.name" label="Name" class="flex-1" />
          </div>
          <BaseButton type="submit" :disabled="saving">Add wilaya</BaseButton>
        </form>
      </BaseCard>

      <!-- Commune manager -->
      <BaseCard v-if="selected">
        <header class="mb-3 flex flex-wrap items-end justify-between gap-2">
          <form class="flex items-end gap-2" @submit.prevent="renameWilaya">
            <BaseInput v-model="selected.code" label="Code" class="w-20" />
            <BaseInput v-model="selected.name" label="Wilaya" class="w-56" />
            <BaseButton type="submit" variant="ghost" :disabled="saving">Save</BaseButton>
          </form>
          <BaseButton variant="ghost" @click="removeWilaya(selected)">Remove wilaya</BaseButton>
        </header>

        <div class="space-y-2">
          <div
            v-for="c in communes"
            :key="c.id"
            class="flex flex-col gap-2 rounded-token border border-border p-2 sm:flex-row sm:items-end"
          >
            <BaseInput v-model="c.name" label="Commune" class="flex-1" />
            <BaseInput v-model="c.daira_name" label="Daïra" class="flex-1" />
            <div class="flex gap-1">
              <BaseButton variant="ghost" :disabled="saving" @click="saveCommune(c)">Save</BaseButton>
              <BaseButton variant="ghost" @click="removeCommune(c)">Remove</BaseButton>
            </div>
          </div>
          <p v-if="!communes.length" class="py-4 text-center text-sm opacity-60">No communes yet.</p>
        </div>

        <!-- Add commune -->
        <form
          class="mt-4 flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:items-end"
          @submit.prevent="addCommune"
        >
          <BaseInput v-model="newCommune.name" label="New commune" class="flex-1" />
          <BaseInput v-model="newCommune.daira_name" label="Daïra (optional)" class="flex-1" />
          <BaseButton type="submit" :disabled="saving">Add commune</BaseButton>
        </form>
      </BaseCard>

      <BaseCard v-else>
        <p class="py-8 text-center text-sm opacity-60">Select a wilaya to manage its communes.</p>
      </BaseCard>
    </div>
  </div>
</template>
