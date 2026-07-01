<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import BaseCard from '@/components/base/BaseCard.vue'
import DealsPanel from '@/features/clients/components/DealsPanel.vue'
import DesirePanel from '@/features/clients/components/DesirePanel.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import TimelinePanel from '@/features/pipeline/components/TimelinePanel.vue'

const props = defineProps({ id: { type: [String, Number], required: true } })
const store = useClientsStore()

onMounted(() => store.load(props.id))
</script>

<template>
  <div class="space-y-4">
    <RouterLink :to="{ name: 'clients' }" class="text-sm opacity-70 hover:text-primary">← All clients</RouterLink>

    <p v-if="store.loading && !store.current" class="py-4 text-center text-sm opacity-60">Loading…</p>

    <template v-else-if="store.current">
      <!-- Profile -->
      <div class="flex flex-col gap-1">
        <div class="flex items-center gap-2">
          <h1 class="text-xl font-semibold">{{ store.current.full_name }}</h1>
          <span
            v-if="store.current.status === 'cancelled'"
            class="rounded-token bg-surface px-2 py-0.5 text-xs opacity-70"
          >
            cancelled
          </span>
        </div>
        <p class="opacity-70">{{ store.current.phone }}<template v-if="store.current.email"> · {{ store.current.email }}</template></p>
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <BaseCard class="lg:col-span-1">
          <h2 class="mb-3 text-sm font-semibold uppercase opacity-60">Profile</h2>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-2">
              <dt class="opacity-60">Source</dt>
              <dd>{{ store.current.source?.label ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="opacity-60">Rating</dt>
              <dd>{{ store.current.rating?.label ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-2">
              <dt class="opacity-60">Assigned agent</dt>
              <dd>{{ store.current.assigned_agent?.name ?? 'Unassigned' }}</dd>
            </div>
          </dl>
          <p v-if="store.current.notes" class="mt-3 border-t border-border pt-3 text-sm opacity-80">
            {{ store.current.notes }}
          </p>
        </BaseCard>

        <div class="space-y-4 lg:col-span-2">
          <!-- Payments (Phase 4) is added to this column as that phase lands. -->
          <DealsPanel :client-id="store.current.id" />
          <DesirePanel :client-id="store.current.id" />
          <TimelinePanel :client-id="store.current.id" />
        </div>
      </div>
    </template>

    <p v-else class="py-4 text-center text-sm opacity-60">Client not found.</p>
  </div>
</template>
