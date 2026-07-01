<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useAuthStore } from '@/features/settings/store'

const store = useLocationsStore()
const auth = useAuthStore()
const { items: areas } = useDynamicList('areas')

const canManage = auth.can('locations.manage')

const blank = { name: '', code: '', area_id: '', address: '', description: '' }
const form = reactive({ ...blank })
const editingId = ref(null)
const showForm = ref(false)

onMounted(() => store.fetch())

function openCreate() {
  Object.assign(form, blank)
  editingId.value = null
  showForm.value = true
}

function openEdit(loc) {
  Object.assign(form, {
    name: loc.name,
    code: loc.code,
    area_id: loc.area_id ?? '',
    address: loc.address ?? '',
    description: loc.description ?? '',
  })
  editingId.value = loc.id
  showForm.value = true
}

async function submit() {
  const payload = {
    name: form.name.trim(),
    code: form.code.trim(),
    area_id: form.area_id || null,
    address: form.address.trim() || null,
    description: form.description.trim() || null,
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

function remove(loc) {
  if (window.confirm(`Cancel project "${loc.name}"? The record is kept but marked cancelled.`)) {
    store.cancel(loc.id)
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h1 class="text-xl font-semibold">Projects</h1>
        <p class="opacity-70">Real-estate projects, buildings and sites.</p>
      </div>
      <BaseButton v-if="canManage" @click="openCreate">New project</BaseButton>
    </div>

    <div class="flex flex-wrap gap-2">
      <BaseInput
        v-model="store.filters.q"
        label="Search"
        class="flex-1 min-w-[12rem]"
        @keyup.enter="store.fetch()"
      />
      <label class="block">
        <span class="mb-1 block text-sm">Area</span>
        <select
          v-model="store.filters.area_id"
          class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink"
          @change="store.fetch()"
        >
          <option value="">All areas</option>
          <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.label }}</option>
        </select>
      </label>
    </div>

    <p v-if="store.error" class="text-sm text-danger">{{ store.error }}</p>

    <BaseCard v-if="showForm && canManage">
      <form class="space-y-3" @submit.prevent="submit">
        <h2 class="font-semibold">{{ editingId ? 'Edit project' : 'New project' }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <BaseInput v-model="form.name" label="Name" />
          <BaseInput v-model="form.code" label="Code" />
          <label class="block">
            <span class="mb-1 block text-sm">Area</span>
            <select
              v-model="form.area_id"
              class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink"
            >
              <option value="">— none —</option>
              <option v-for="a in areas" :key="a.id" :value="a.id">{{ a.label }}</option>
            </select>
          </label>
          <BaseInput v-model="form.address" label="Address" />
        </div>
        <BaseInput v-model="form.description" label="Description" />
        <div class="flex gap-2">
          <BaseButton type="submit" :disabled="store.saving">Save</BaseButton>
          <BaseButton type="button" variant="ghost" @click="showForm = false">Cancel</BaseButton>
        </div>
      </form>
    </BaseCard>

    <BaseCard>
      <p v-if="store.loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <div v-else class="space-y-2">
        <div
          v-for="loc in store.items"
          :key="loc.id"
          class="flex flex-col gap-2 rounded-token border border-border p-3 sm:flex-row sm:items-center"
        >
          <div class="flex-1">
            <RouterLink
              :to="{ name: 'inventory.location', params: { id: loc.id } }"
              class="font-medium hover:text-primary"
            >
              {{ loc.name }}
            </RouterLink>
            <span class="ml-2 text-xs opacity-60">
              {{ loc.code }}<template v-if="loc.area"> · {{ loc.area.label }}</template>
            </span>
          </div>
          <div v-if="canManage" class="flex items-center gap-1">
            <BaseButton variant="ghost" @click="openEdit(loc)">Edit</BaseButton>
            <BaseButton variant="ghost" @click="remove(loc)">Remove</BaseButton>
          </div>
        </div>
        <p v-if="!store.items.length" class="py-4 text-center text-sm opacity-60">
          No projects yet.
        </p>
      </div>
    </BaseCard>
  </div>
</template>
