<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'

const store = useClientsStore()
const auth = useAuthStore()
const { items: sources } = useDynamicList('sources')
const { items: ratings } = useDynamicList('client_ratings')

const selectClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

const canCreate = () => auth.can('clients.create')
const canManage = () => auth.can('clients.manage')

const drawerOpen = ref(false)
const editingId = ref(null) // null = creating
const emptyForm = () => ({
  first_name: '',
  last_name: '',
  phone: '',
  email: '',
  source_id: '',
  rating_id: '',
  assigned_agent_id: '',
  notes: '',
})
const form = reactive(emptyForm())

onMounted(() => store.fetch())

function openCreate() {
  editingId.value = null
  Object.assign(form, emptyForm())
  drawerOpen.value = true
}

function openEdit(client) {
  editingId.value = client.id
  Object.assign(form, {
    first_name: client.first_name,
    last_name: client.last_name,
    phone: client.phone,
    email: client.email ?? '',
    source_id: client.source?.id ?? '',
    rating_id: client.rating?.id ?? '',
    assigned_agent_id: client.assigned_agent?.id ?? '',
    notes: client.notes ?? '',
  })
  drawerOpen.value = true
}

async function save() {
  if (!form.first_name.trim() || !form.last_name.trim() || !form.phone.trim()) return
  const payload = {
    first_name: form.first_name.trim(),
    last_name: form.last_name.trim(),
    phone: form.phone.trim(),
    email: form.email.trim() || null,
    source_id: form.source_id || null,
    rating_id: form.rating_id || null,
    assigned_agent_id: form.assigned_agent_id || null,
    notes: form.notes.trim() || null,
  }
  try {
    if (editingId.value) await store.update(editingId.value, payload)
    else await store.create(payload)
    drawerOpen.value = false
  } catch {
    /* error surfaced via store.error */
  }
}

function removeClient(client) {
  if (window.confirm(`Cancel client "${client.full_name}"? The record and its history are kept.`)) {
    store.cancel(client.id)
  }
}

function resetFilters() {
  store.filters = { assigned_agent_id: '', source_id: '', rating_id: '', search: '' }
  store.fetch()
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-xl font-semibold">Clients</h1>
        <p class="opacity-70">Leads and buyers. Searchable by name or phone.</p>
      </div>
      <BaseButton v-if="canCreate()" @click="openCreate">New client</BaseButton>
    </div>

    <p v-if="store.error" class="text-sm text-danger">{{ store.error }}</p>

    <!-- Filters -->
    <BaseCard>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <label class="block lg:col-span-2">
          <span class="mb-1 block text-sm">Search (name or phone)</span>
          <BaseInput v-model="store.filters.search" @keyup.enter="store.fetch()" />
        </label>
        <label class="block">
          <span class="mb-1 block text-sm">Agent</span>
          <select v-model="store.filters.assigned_agent_id" :class="selectClass" @change="store.fetch()">
            <option value="">All</option>
            <option v-for="a in store.agents" :key="a.id" :value="a.id">{{ a.name }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm">Source</span>
          <select v-model="store.filters.source_id" :class="selectClass" @change="store.fetch()">
            <option value="">All</option>
            <option v-for="s in sources" :key="s.id" :value="s.id">{{ s.label }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm">Rating</span>
          <select v-model="store.filters.rating_id" :class="selectClass" @change="store.fetch()">
            <option value="">All</option>
            <option v-for="r in ratings" :key="r.id" :value="r.id">{{ r.label }}</option>
          </select>
        </label>
      </div>
      <div class="mt-3 flex gap-2">
        <BaseButton @click="store.fetch()">Filter</BaseButton>
        <BaseButton variant="ghost" @click="resetFilters">Reset</BaseButton>
      </div>
    </BaseCard>

    <BaseCard>
      <p v-if="store.loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left opacity-60">
            <tr>
              <th class="py-2 pr-3">Name</th>
              <th class="py-2 pr-3">Phone</th>
              <th class="py-2 pr-3">Source</th>
              <th class="py-2 pr-3">Rating</th>
              <th class="py-2 pr-3">Agent</th>
              <th class="py-2 pr-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in store.items" :key="c.id" class="border-t border-border">
              <td class="py-2 pr-3 font-medium">
                <RouterLink :to="{ name: 'clients.file', params: { id: c.id } }" class="hover:text-primary">
                  {{ c.full_name }}
                </RouterLink>
              </td>
              <td class="py-2 pr-3">{{ c.phone }}</td>
              <td class="py-2 pr-3">{{ c.source?.label ?? '—' }}</td>
              <td class="py-2 pr-3">{{ c.rating?.label ?? '—' }}</td>
              <td class="py-2 pr-3">{{ c.assigned_agent?.name ?? '—' }}</td>
              <td class="py-2 pr-3">
                <div v-if="canManage()" class="flex justify-end gap-1">
                  <BaseButton variant="ghost" @click="openEdit(c)">Edit</BaseButton>
                  <BaseButton variant="ghost" @click="removeClient(c)">Remove</BaseButton>
                </div>
              </td>
            </tr>
            <tr v-if="!store.items.length">
              <td colspan="6" class="py-4 text-center text-sm opacity-60">No clients match.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </BaseCard>

    <!-- Create / edit drawer -->
    <div v-if="drawerOpen" class="fixed inset-0 z-40 flex justify-end bg-black/40" @click.self="drawerOpen = false">
      <div class="h-full w-full max-w-md overflow-y-auto bg-bg p-4 shadow-lg sm:p-6">
        <h2 class="mb-4 text-lg font-semibold">{{ editingId ? 'Edit client' : 'New client' }}</h2>
        <form class="space-y-3" @submit.prevent="save">
          <div class="grid grid-cols-2 gap-3">
            <BaseInput v-model="form.first_name" label="First name" />
            <BaseInput v-model="form.last_name" label="Last name" />
          </div>
          <BaseInput v-model="form.phone" label="Phone" />
          <BaseInput v-model="form.email" label="Email" type="email" />

          <label class="block">
            <span class="mb-1 block text-sm">Source</span>
            <select v-model="form.source_id" :class="selectClass" aria-label="Source">
              <option value="">None</option>
              <option v-for="s in sources" :key="s.id" :value="s.id">{{ s.label }}</option>
            </select>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm">Rating</span>
            <select v-model="form.rating_id" :class="selectClass" aria-label="Rating">
              <option value="">None</option>
              <option v-for="r in ratings" :key="r.id" :value="r.id">{{ r.label }}</option>
            </select>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm">Assigned agent</span>
            <select v-model="form.assigned_agent_id" :class="selectClass" aria-label="Assigned agent">
              <option value="">Unassigned</option>
              <option v-for="a in store.agents" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </label>

          <label class="block">
            <span class="mb-1 block text-sm">Notes</span>
            <textarea v-model="form.notes" rows="3" :class="selectClass"></textarea>
          </label>

          <div class="flex gap-2 pt-2">
            <BaseButton type="submit" :disabled="store.saving">Save</BaseButton>
            <BaseButton type="button" variant="ghost" @click="drawerOpen = false">Cancel</BaseButton>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
