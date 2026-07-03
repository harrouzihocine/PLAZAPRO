<script setup>
import { onMounted, reactive, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import BasePhoneInput from '@/components/base/BasePhoneInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { formatPhone } from '@/data/countryCodes'
import { useDynamicList } from '@/composables/useDynamicList'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'

const store = useClientsStore()
const auth = useAuthStore()
const router = useRouter()
const { items: sources } = useDynamicList('sources')
const { items: ratings } = useDynamicList('client_ratings')
const { items: interestOptions } = useDynamicList('property_interests')

const selectClass =
  'w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary'

const canCreate = () => auth.can('clients.create')
const canManage = () => auth.can('clients.manage')
// Client ownership (assigned agent + who created it/when) is back-office-only,
// gated by clients.manage (super-admin / admin / manager).
const canSeeOwnership = () => auth.can('clients.manage')
const fmtDateTime = (v) =>
  v ? new Date(v).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'

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
  interests: [],
})
const form = reactive(emptyForm())

// Toggle a property-interest id (apartment / box / local) on the form.
function toggleInterest(id) {
  const i = form.interests.indexOf(id)
  if (i === -1) form.interests.push(id)
  else form.interests.splice(i, 1)
}

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
    interests: [...(client.interests ?? [])],
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
    interests: form.interests,
  }
  try {
    if (editingId.value) {
      await store.update(editingId.value, payload)
      drawerOpen.value = false
    } else {
      const created = await store.create(payload)
      drawerOpen.value = false
      router.push({ name: 'clients.file', params: { id: created.id } })
    }
  } catch {
    /* error surfaced via store.error */
  }
}

async function removeClient(client) {
  if (
    await confirmAction({
      title: `Cancel client "${client.full_name}"?`,
      text: 'The record and its history are kept.',
      confirmText: 'Cancel client',
      danger: true,
    })
  ) {
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


    <!-- Filters -->
    <BaseCard>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <label class="block lg:col-span-2">
          <span class="mb-1 block text-sm">Search (name or phone)</span>
          <BaseInput v-model="store.filters.search" @keyup.enter="store.fetch()" />
        </label>
        <BaseSelect
          v-if="canSeeOwnership()"
          v-model="store.filters.assigned_agent_id"
          label="Agent"
          placeholder="All"
          :options="store.followUpAgents.map((a) => ({ value: a.id, label: a.name }))"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.source_id"
          label="Source"
          placeholder="All"
          :options="sources.map((s) => ({ value: s.id, label: s.label }))"
          @change="store.fetch()"
        />
        <BaseSelect
          v-model="store.filters.rating_id"
          label="Rating"
          placeholder="All"
          :options="ratings.map((r) => ({ value: r.id, label: r.label }))"
          @change="store.fetch()"
        />
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
              <th v-if="canSeeOwnership()" class="py-2 pr-3">Agent</th>
              <th v-if="canSeeOwnership()" class="py-2 pr-3">Created by</th>
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
              <td class="py-2 pr-3">{{ formatPhone(c.phone) }}</td>
              <td class="py-2 pr-3">{{ c.source?.label ?? '—' }}</td>
              <td class="py-2 pr-3">{{ c.rating?.label ?? '—' }}</td>
              <td v-if="canSeeOwnership()" class="py-2 pr-3">{{ c.assigned_agent?.name ?? '—' }}</td>
              <td v-if="canSeeOwnership()" class="py-2 pr-3">
                <div>{{ c.created_by?.name ?? '—' }}</div>
                <div class="text-xs opacity-60">{{ fmtDateTime(c.created_at) }}</div>
              </td>
              <td class="py-2 pr-3">
                <div v-if="canManage()" class="flex justify-end gap-1">
                  <BaseButton variant="ghost" @click="openEdit(c)">Edit</BaseButton>
                  <BaseButton variant="ghost" @click="removeClient(c)">Remove</BaseButton>
                </div>
              </td>
            </tr>
            <tr v-if="!store.items.length">
              <td :colspan="canSeeOwnership() ? 7 : 5" class="py-4 text-center text-sm opacity-60">No clients match.</td>
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
            <BaseInput v-model="form.last_name" label="Last name" capitalize />
            <BaseInput v-model="form.first_name" label="First name" capitalize />
          </div>
          <BasePhoneInput v-model="form.phone" label="Phone" />
          <BaseInput v-model="form.email" label="Email" type="email" />

          <BaseSelect
            v-model="form.source_id"
            label="Source"
            placeholder="None"
            :options="sources.map((s) => ({ value: s.id, label: s.label }))"
          />

          <BaseSelect
            v-model="form.rating_id"
            label="Rating"
            placeholder="None"
            :options="ratings.map((r) => ({ value: r.id, label: r.label }))"
          />

          <!-- What the client is shopping for (drives qualification & shortlist). -->
          <fieldset v-if="interestOptions.length">
            <legend class="mb-1 block text-sm">Interested in</legend>
            <div class="flex flex-wrap gap-3">
              <label
                v-for="opt in interestOptions"
                :key="opt.id"
                class="flex items-center gap-1.5 text-sm"
              >
                <input
                  type="checkbox"
                  :checked="form.interests.includes(opt.id)"
                  @change="toggleInterest(opt.id)"
                />
                {{ opt.label }}
              </label>
            </div>
          </fieldset>

          <BaseSelect
            v-if="canSeeOwnership()"
            v-model="form.assigned_agent_id"
            label="Assigned agent"
            placeholder="Unassigned"
            :options="store.followUpAgents.map((a) => ({ value: a.id, label: a.name }))"
          />

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
