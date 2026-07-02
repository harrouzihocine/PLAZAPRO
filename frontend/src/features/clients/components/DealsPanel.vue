<script setup>
import { onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import PaymentsPanel from '@/features/payments/components/PaymentsPanel.vue'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'

const props = defineProps({ clientId: { type: [String, Number], required: true } })
const store = useClientsStore()
const auth = useAuthStore()

const canManage = () => auth.can('clients.manage')
const canViewPayments = () => auth.can('versements.view')

// Archived deals are lazy-loaded the first time the section is opened.
const showArchived = ref(false)

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

// Archive: reversible. Hides the deal and everything inside it until reactivated.
async function archiveDeal(project) {
  if (
    await confirmAction({
      title: 'Archive this deal?',
      text: 'This archives the deal and everything inside it. You can reactivate it later.',
      confirmText: 'Archive',
    })
  ) {
    store.archiveProject(props.clientId, project.id)
  }
}

function reactivateDeal(project) {
  store.reactivateProject(props.clientId, project.id)
}

// Remove: terminal. Cancels the deal and its contents (kept + audited, not deleted).
async function removeDeal(project) {
  if (
    await confirmAction({
      title: 'Remove this deal?',
      text: 'This removes the deal and everything inside it. The records are kept but marked cancelled.',
      confirmText: 'Remove',
      danger: true,
    })
  ) {
    store.cancelProject(props.clientId, project.id)
  }
}

function toggleArchived() {
  showArchived.value = !showArchived.value
  if (showArchived.value) store.loadArchivedProjects(props.clientId)
}
</script>

<template>
  <BaseCard>
    <div class="mb-2 flex items-center justify-between">
      <h2 class="text-sm font-semibold uppercase opacity-60">Deals</h2>
      <BaseButton v-if="canManage()" variant="ghost" @click="newDeal">New deal</BaseButton>
    </div>

    <div class="space-y-2">
      <div v-for="p in store.projects" :key="p.id" class="rounded-token border border-border p-2">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <div class="flex-1">
            <span class="rounded-token px-2 py-0.5 text-xs font-medium" :class="stageClass[p.stage]">
              {{ p.stage }}
            </span>
            <span v-if="p.location" class="ml-2 text-sm">{{ p.location.name }}</span>
            <GtmPriorityBadge
              v-if="p.location?.gtm_priority"
              :priority="p.location.gtm_priority"
              class="ml-2"
            />
            <span v-if="p.unit" class="ml-2 text-sm">{{ p.unit.reference }}</span>
            <span v-if="p.total_price" class="ml-2 text-sm opacity-70">{{ p.total_price }}</span>
            <span v-if="p.location?.expected_delivery_date" class="ml-2 text-xs opacity-60">
              🏁 Delivery {{ p.location.expected_delivery_date }}
            </span>
          </div>
          <div v-if="canManage()" class="flex items-center gap-1">
            <BaseSelect
              v-if="p.allowed_next.length"
              :model-value="''"
              class="w-44 shrink-0"
              aria-label="Advance stage"
              placeholder="Advance…"
              :clearable="false"
              :options="p.allowed_next.map((s) => ({ value: s, label: s }))"
              @change="(v) => advance(p, v)"
            />
            <BaseButton variant="ghost" @click="archiveDeal(p)">Archive</BaseButton>
            <BaseButton variant="ghost" @click="removeDeal(p)">Remove</BaseButton>
          </div>
        </div>

        <!-- Payments (Phase 4): schedule + versements + receipts, per deal. Shown
             once a deal has an agreed total price and the user can view payments. -->
        <PaymentsPanel
          v-if="canViewPayments() && p.total_price && p.status === 'active'"
          :project-id="p.id"
          :total-price="p.total_price"
          class="mt-2"
        />
      </div>
      <p v-if="!store.projects.length" class="py-2 text-sm opacity-60">No deals yet.</p>
    </div>

    <!-- Archived deals: hidden by default, reactivatable one by one. -->
    <div v-if="canManage()" class="mt-3 border-t border-border pt-2">
      <button
        type="button"
        class="text-xs font-semibold uppercase opacity-60 hover:opacity-100"
        @click="toggleArchived"
      >
        {{ showArchived ? 'Hide' : 'Show' }} archived
      </button>

      <div v-if="showArchived" class="mt-2 space-y-2">
        <div
          v-for="p in store.archivedProjects"
          :key="p.id"
          class="rounded-token border border-dashed border-border p-2 opacity-80"
        >
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="flex-1">
              <span class="rounded-token px-2 py-0.5 text-xs font-medium" :class="stageClass[p.stage]">
                {{ p.stage }}
              </span>
              <span v-if="p.unit" class="ml-2 text-sm">{{ p.unit.reference }}</span>
              <span v-if="p.total_price" class="ml-2 text-sm opacity-70">{{ p.total_price }}</span>
              <span class="ml-2 text-xs uppercase opacity-50">archived</span>
            </div>
            <BaseButton variant="ghost" @click="reactivateDeal(p)">Reactivate</BaseButton>
          </div>
        </div>
        <p v-if="!store.archivedProjects.length" class="py-1 text-sm opacity-60">No archived deals.</p>
      </div>
    </div>
  </BaseCard>
</template>
