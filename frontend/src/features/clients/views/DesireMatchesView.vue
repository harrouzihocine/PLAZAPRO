<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Avatar from 'primevue/avatar'
import Skeleton from 'primevue/skeleton'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { formatPhone } from '@/data/countryCodes'
import { initials } from '@/utils/format'
import { formatMoney } from '@/features/payments/money'
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
  <div>
    <PageHeader
      title="Desire matches"
      subtitle="Waiting clients whose wishlist now fits available inventory — reconnect and open a deal."
    />

    <SectionCard v-if="error">
      <EmptyState icon="pi pi-exclamation-triangle" :title="error" />
    </SectionCard>

    <div v-else-if="loading" class="space-y-4">
      <Skeleton v-for="i in 3" :key="i" height="7rem" />
    </div>

    <SectionCard v-else-if="!rows.length">
      <EmptyState
        icon="pi pi-heart"
        title="No matches right now"
        body="When new inventory fits a waiting client's wishlist, they show up here."
      />
    </SectionCard>

    <div v-else class="space-y-4">
      <SectionCard v-for="row in rows" :key="row.client.id" flush>
        <template #header>
          <RouterLink
            :to="{ name: 'clients.file', params: { id: row.client.id } }"
            class="group flex items-center gap-3"
          >
            <Avatar
              :label="initials(row.client.full_name)"
              shape="circle"
              class="!bg-highlight !text-primary-700 dark:!text-primary-300"
            />
            <span>
              <span class="block text-sm font-semibold text-ink group-hover:underline">
                {{ row.client.full_name }}
              </span>
              <span class="num block text-xs text-mute">{{ formatPhone(row.client.phone) }}</span>
            </span>
          </RouterLink>
        </template>

        <ul class="divide-y divide-line">
          <li
            v-for="u in row.matches"
            :key="u.id"
            class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm sm:px-5"
          >
            <span class="flex min-w-0 items-center gap-2">
              <i class="pi pi-home shrink-0 text-mute" aria-hidden="true" />
              <RouterLink
                :to="{ name: 'inventory.unit', params: { id: u.id } }"
                class="font-medium text-ink hover:underline"
              >
                {{ u.reference }}
              </RouterLink>
              <span v-if="u.location" class="truncate text-mute">{{ u.location }}</span>
            </span>
            <span class="num shrink-0 text-mute">{{ formatMoney(u.price) }}</span>
          </li>
        </ul>
      </SectionCard>
    </div>
  </div>
</template>
