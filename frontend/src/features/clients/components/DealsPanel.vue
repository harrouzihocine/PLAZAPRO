<script setup>
import { onMounted } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'

const props = defineProps({ clientId: { type: [String, Number], required: true } })
const store = useClientsStore()
const auth = useAuthStore()

const canManage = () => auth.can('clients.manage')

// Stage → token-based badge classes, matching SaleStatusBadge's house style.
const stageClass = {
  lead: 'bg-border text-ink',
  negotiating: 'bg-warning/15 text-warning',
  reserved: 'bg-primary/15 text-primary',
  won: 'bg-success/15 text-success',
  lost: 'bg-danger/15 text-danger',
}

onMounted(() => store.loadProjects(props.clientId))

function newDeal() {
  store.createProject(props.clientId)
}

function advance(project, stage) {
  if (!stage) return
  store.advanceProject(props.clientId, project.id, stage)
}

function cancelDeal(project) {
  if (window.confirm('Cancel this deal? The record is kept.')) {
    store.cancelProject(props.clientId, project.id)
  }
}
</script>

<template>
  <BaseCard>
    <div class="mb-2 flex items-center justify-between">
      <h2 class="text-sm font-semibold uppercase opacity-60">Deals</h2>
      <BaseButton v-if="canManage()" variant="ghost" @click="newDeal">New deal</BaseButton>
    </div>

    <div class="space-y-2">
      <div
        v-for="p in store.projects"
        :key="p.id"
        class="flex flex-col gap-2 rounded-token border border-border p-2 sm:flex-row sm:items-center"
      >
        <div class="flex-1">
          <span class="rounded-token px-2 py-0.5 text-xs font-medium" :class="stageClass[p.stage]">
            {{ p.stage }}
          </span>
          <span v-if="p.unit" class="ml-2 text-sm">{{ p.unit.reference }}</span>
          <span v-if="p.total_price" class="ml-2 text-sm opacity-70">{{ p.total_price }}</span>
        </div>
        <div v-if="canManage()" class="flex items-center gap-1">
          <select
            v-if="p.allowed_next.length"
            :class="'rounded-token border border-border bg-bg px-2 py-1 text-sm text-ink'"
            aria-label="Advance stage"
            @change="advance(p, $event.target.value)"
          >
            <option value="">Advance…</option>
            <option v-for="s in p.allowed_next" :key="s" :value="s">{{ s }}</option>
          </select>
          <BaseButton variant="ghost" @click="cancelDeal(p)">Remove</BaseButton>
        </div>
      </div>
      <p v-if="!store.projects.length" class="py-2 text-sm opacity-60">No deals yet.</p>
    </div>
  </BaseCard>
</template>
