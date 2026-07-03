<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import BaseCard from '@/components/base/BaseCard.vue'
import { formatPhone } from '@/data/countryCodes'
import { desireMatchesApi } from '@/features/clients/api'

// The "Desire matches" board: waiting clients (on the desire list, no deal yet)
// whose criteria now fit available inventory — the reconnect signal that
// complements the unit-match notifications. Agent-scoped on the server.
const rows = ref([])
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    rows.value = await desireMatchesApi.list()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Could not load desire matches.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-xl font-semibold">Desire matches</h1>
      <p class="opacity-70">Waiting clients whose wishlist now fits available inventory. Reconnect and open a deal.</p>
    </div>

    <BaseCard v-if="error"><p class="text-danger">{{ error }}</p></BaseCard>
    <p v-else-if="loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
    <p v-else-if="!rows.length" class="py-4 text-center text-sm opacity-60">
      No waiting clients match available inventory right now.
    </p>

    <BaseCard v-for="row in rows" v-else :key="row.client.id">
      <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
        <RouterLink :to="{ name: 'clients.file', params: { id: row.client.id } }" class="font-semibold hover:text-primary">
          {{ row.client.full_name }}
        </RouterLink>
        <span class="text-sm opacity-70">{{ formatPhone(row.client.phone) }}</span>
      </div>
      <ul class="space-y-1">
        <li v-for="u in row.matches" :key="u.id" class="flex items-center justify-between rounded-token border border-border px-2 py-1 text-sm">
          <span>
            <RouterLink :to="{ name: 'inventory.unit', params: { id: u.id } }" class="font-medium hover:text-primary">
              {{ u.reference }}
            </RouterLink>
            <span v-if="u.location" class="ml-2 opacity-60">{{ u.location }}</span>
          </span>
          <span class="opacity-70">{{ u.price }}</span>
        </li>
      </ul>
    </BaseCard>
  </div>
</template>
