<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import BaseModal from '@/components/base/BaseModal.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import CallLogForm from '@/features/pipeline/components/CallLogForm.vue'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'
import { agentsApi, desireMatchesApi, followUpAgentsApi } from '@/features/clients/api'
import { toastSuccess } from '@/composables/useConfirm'
import { formatPhone } from '@/data/countryCodes'
import { formatDate, initials } from '@/utils/format'
import { formatMoney } from '@/features/payments/money'

// The "Desire matches" board: waiting clients (on the desire list, no deal yet)
// whose criteria now fit available inventory — the reconnect signal that
// complements the unit-match notifications. A company-wide oversight monitor —
// the server gates it behind oversight.matches and lists every waiting client.
//
// The board is role-split around who owns the lead (client.assigned_agent):
//  - a MANAGER (clients.manage) sees the whole company and DELEGATES — assign a
//    waiting client to a sales agent, who is notified and does the calling;
//  - the ASSIGNED agent sees their own book and does the work — "Reconnect &
//    qualify" opens the SAME call-log qualification the rest of the pipeline
//    uses (branch "properties", pre-loaded with the selection), so reconnecting
//    never re-searches inventory already matched here and needs no separate
//    "create project" step: the call itself opens the project when it qualifies
//    or closes into a deal. Only the assignee sees Reconnect, so a manager can
//    never accidentally become the caller — they assign instead.
const store = useClientsStore()
const auth = useAuthStore()

// Delegation is a manager's job; doing the call is the assignee's.
const canAssign = () => auth.can('clients.manage')
const isAssignee = (row) => !!row.client.assigned_agent && row.client.assigned_agent.id === auth.user?.id

const rows = ref([])
const loading = ref(true)
const error = ref('')
const unassignedOnly = ref(false)

// Sales agents a manager can delegate to (calls.log holders — the same
// population as the client "assigned agent" picker).
const followUpAgents = ref([])
const agentOptions = computed(() =>
  followUpAgents.value.map((a) => ({ value: a.id, label: a.name })),
)

async function load() {
  loading.value = true
  try {
    const data = await desireMatchesApi.list()
    // Pre-select every match by default — shortlisting is soft (easy to drop
    // inside the qualify modal or later); the point is to remove clicks, not
    // to ask the agent to re-pick what the matcher already found.
    rows.value = data.map((row) => ({
      ...row,
      matches: row.matches.map((u) => ({ ...u, selected: true })),
      assigning: false,
    }))
    error.value = ''
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Could not load desire matches.'
  } finally {
    loading.value = false
  }
}

const visibleRows = computed(() =>
  unassignedOnly.value ? rows.value.filter((r) => !r.client.assigned_agent) : rows.value,
)

onMounted(async () => {
  if (!store.agents.length) store.agents = await agentsApi.list()
  if (canAssign()) followUpAgents.value = await followUpAgentsApi.list()
  await load()
})

// Manager delegates: set the client's assigned agent (server notifies them).
async function assign(row, agentId) {
  if (!agentId || row.assigning) return
  row.assigning = true
  try {
    const client = await desireMatchesApi.assign(row.client.id, agentId)
    row.client.assigned_agent = client.assigned_agent ?? null
    toastSuccess(`Assigned to ${client.assigned_agent?.name ?? 'the agent'} — they've been notified.`)
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Could not assign the agent.'
  } finally {
    row.assigning = false
  }
}

// The full desire brief — every field DesireFields.vue captures, in the same
// order, so nothing the client told the agent is left off this board. A field
// left blank means "no preference" (only criteria actually set are matched),
// so it's shown as "Any" rather than omitted — that's information too.
function fmtArea(v) {
  return v == null ? null : `${Number(v)} m²`
}

function rangeText(min, max, fmt = (v) => v) {
  if (min == null && max == null) return null
  if (min != null && max != null) return `${fmt(min)} – ${fmt(max)}`
  return min != null ? `≥ ${fmt(min)}` : `≤ ${fmt(max)}`
}

function desireFields(d) {
  return [
    { label: 'Wilaya', value: d.wilaya?.name ?? 'Any' },
    { label: 'Commune', value: d.commune?.name ?? 'Any' },
    { label: 'Preferred sites', value: d.locations?.length ? d.locations.map((l) => l.name).join(', ') : 'Any site' },
    { label: 'Project type', value: d.type?.label ?? 'Any' },
    { label: 'Room number', value: d.room_number?.label ?? 'Any' },
    { label: 'Contract type', value: d.contract_type?.label ?? 'Any' },
    { label: 'Floor', value: d.floor?.label ?? 'Any' },
    { label: 'Area', value: rangeText(d.area_min, d.area_max, fmtArea) ?? 'Any', numeric: true },
    { label: 'Rooms (min)', value: d.rooms_min ?? 'Any', numeric: true },
    { label: 'Budget', value: rangeText(d.budget_min, d.budget_max, formatMoney) ?? 'Any', numeric: true },
  ]
}

// A unit's own push overrides its project's; only surface it when it's worth
// interrupting for (medium/low priority is the default, not a signal).
const isPushed = (u) => u.gtm_priority === 'high' || u.gtm_priority === 'critical'

function locationLine(u) {
  const commune = [u.location?.commune, u.location?.wilaya].filter(Boolean).join(', ')
  return [u.location?.name, commune].filter(Boolean).join(' · ')
}

// Same "full card" label ProjectUnitsPicker / CallLogForm already use, so the
// qualify modal shows exactly what this page showed — no re-description.
function unitLabel(u) {
  return [u.reference, u.type, u.floor, u.area_sqm ? `${u.area_sqm} m²` : null, u.price ? formatMoney(u.price) : null]
    .filter(Boolean)
    .join(' · ')
}

function onHoldNote(u) {
  if (u.sale_status === 'reserved' && u.reserved_expires_at) return `Reserved until ${formatDate(u.reserved_expires_at)} — open as a backup`
  if (u.sale_status === 'interested') return 'Another client is interested — still open as a backup'
  return null
}

const selectedCount = (row) => row.matches.filter((u) => u.selected).length

// --- Reconnect & qualify: the same call-log modal every other entry point uses. ---
const reconnectRow = ref(null)
const reconnectOpen = ref(false)

function openReconnect(row) {
  reconnectRow.value = row
  reconnectOpen.value = true
}

const reconnectProperties = computed(() => {
  if (!reconnectRow.value) return []
  return reconnectRow.value.matches
    .filter((u) => u.selected)
    .map((u) => ({
      shortlistable_type: 'unit',
      shortlistable_id: u.id,
      label: unitLabel(u),
      location_id: u.location?.id ?? null,
    }))
})

async function submitReconnect(payload) {
  const clientId = reconnectRow.value.client.id
  await store.logCall(clientId, payload)
  reconnectOpen.value = false
  reconnectRow.value = null
  toastSuccess('Call logged — the client is qualified.')
  await load() // a resolved match drops off the board (its desire closes)
}
</script>

<template>
  <div>
    <PageHeader
      title="Desire matches"
      subtitle="Waiting clients whose wishlist now fits available inventory — assign an agent to reconnect."
    >
      <template v-if="canAssign()" #actions>
        <Button
          :label="unassignedOnly ? 'Showing unassigned' : 'Unassigned only'"
          :icon="unassignedOnly ? 'pi pi-filter-fill' : 'pi pi-filter'"
          size="small"
          severity="secondary"
          :outlined="!unassignedOnly"
          @click="unassignedOnly = !unassignedOnly"
        />
      </template>
    </PageHeader>

    <SectionCard v-if="error">
      <EmptyState icon="pi pi-exclamation-triangle" :title="error" />
    </SectionCard>

    <div v-else-if="loading" class="space-y-4">
      <Skeleton v-for="i in 3" :key="i" height="7rem" />
    </div>

    <SectionCard v-else-if="!visibleRows.length">
      <EmptyState
        icon="pi pi-heart"
        :title="unassignedOnly ? 'Nothing waiting to be assigned' : 'No matches right now'"
        :body="
          unassignedOnly
            ? 'Every waiting client with a match already has an agent on it.'
            : 'When new inventory fits a waiting client\'s wishlist, they show up here.'
        "
      />
    </SectionCard>

    <div v-else class="space-y-4">
      <SectionCard v-for="row in visibleRows" :key="row.client.id" flush>
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

          <div class="flex flex-col items-end gap-1.5">
            <RouterLink
              v-if="row.origin_project"
              :to="{ name: 'clients.project', params: { id: row.client.id, projectId: row.origin_project.id } }"
              class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
            >
              <i class="pi pi-folder-open text-[10px]" aria-hidden="true" />
              Originating project<template v-if="row.origin_project.label"> — {{ row.origin_project.label }}</template>
            </RouterLink>

            <!-- Who owns this lead. The assignee sees it as their own; a manager
                 uses the picker below to (re)assign. -->
            <span
              v-if="row.client.assigned_agent"
              class="inline-flex items-center gap-1.5 rounded-full bg-highlight px-2.5 py-1 text-xs text-ink"
            >
              <i class="pi pi-user text-[10px] text-mute" aria-hidden="true" />
              <template v-if="isAssignee(row)">Assigned to you</template>
              <template v-else>{{ row.client.assigned_agent.name }}</template>
            </span>
            <span
              v-else
              class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-line px-2.5 py-1 text-xs text-mute"
            >
              <i class="pi pi-user-plus text-[10px]" aria-hidden="true" /> Unassigned
            </span>
          </div>
        </template>

        <div class="space-y-3 px-4 py-4 sm:px-5">
          <!-- Every field the desire form captures — nothing summarized away.
               A blank field means "no preference", shown as "Any", not omitted. -->
          <div class="rounded-xl border border-line bg-highlight/40 p-3">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">What they're looking for</p>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs sm:grid-cols-3 lg:grid-cols-5">
              <div v-for="f in desireFields(row.desire)" :key="f.label">
                <dt class="text-[10px] uppercase tracking-wide text-mute">{{ f.label }}</dt>
                <dd class="text-ink" :class="{ num: f.numeric }">{{ f.value }}</dd>
              </div>
            </dl>
            <p v-if="row.desire.notes" class="mt-2 whitespace-pre-line border-t border-line pt-2 text-xs text-ink">
              <span class="font-semibold text-mute">Notes — </span>{{ row.desire.notes }}
            </p>
          </div>

          <ul class="grid gap-3 sm:grid-cols-2">
            <li
              v-for="u in row.matches"
              :key="u.id"
              class="rounded-xl border p-3 transition-colors"
              :class="u.selected ? 'border-primary bg-highlight' : 'border-line'"
            >
              <label class="flex cursor-pointer items-start gap-2.5">
                <input v-model="u.selected" type="checkbox" class="mt-1 h-4 w-4 shrink-0 accent-primary" />
                <span class="min-w-0 flex-1">
                  <span class="flex items-start justify-between gap-2">
                    <span class="min-w-0">
                      <span class="block truncate text-sm font-semibold text-ink">{{ u.reference }}</span>
                      <span class="block truncate text-xs text-mute">{{ locationLine(u) }}</span>
                    </span>
                    <span class="num shrink-0 text-sm font-semibold text-primary-700 dark:text-primary-300">
                      {{ formatMoney(u.price) }}
                    </span>
                  </span>

                  <span class="mt-2 flex flex-wrap items-center gap-1.5">
                    <Tag v-if="u.best_match" severity="contrast" icon="pi pi-star-fill" value="Best match" />
                    <StatusTag :value="u.sale_status" />
                    <StatusTag v-if="isPushed(u)" :value="u.gtm_priority" />
                  </span>

                  <span class="mt-2 grid grid-cols-3 gap-2 border-t border-line pt-2 text-xs">
                    <span>
                      <span class="block text-[10px] uppercase tracking-wide text-mute">Type</span>
                      <span class="text-ink">{{ u.type ?? '—' }}</span>
                    </span>
                    <span>
                      <span class="block text-[10px] uppercase tracking-wide text-mute">Floor</span>
                      <span class="text-ink">{{ u.floor ?? '—' }}</span>
                    </span>
                    <span>
                      <span class="block text-[10px] uppercase tracking-wide text-mute">Area</span>
                      <span class="num text-ink">{{ u.area_sqm ? `${u.area_sqm} m²` : '—' }}</span>
                    </span>
                  </span>

                  <span v-if="onHoldNote(u)" class="mt-1.5 block text-xs text-mute">{{ onHoldNote(u) }}</span>
                </span>
              </label>

              <RouterLink
                :to="{ name: 'inventory.unit', params: { id: u.id } }"
                class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
              >
                View unit <i class="pi pi-arrow-right text-[10px]" aria-hidden="true" />
              </RouterLink>
            </li>
          </ul>

          <p v-if="row.more_count" class="text-xs text-mute">
            +{{ row.more_count }} more {{ row.more_count === 1 ? 'match' : 'matches' }} not shown.
          </p>

          <div class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3">
            <span class="text-xs text-mute">{{ selectedCount(row) }} of {{ row.matches.length }} selected</span>

            <div class="flex flex-wrap items-center gap-2">
              <!-- Manager: delegate. Assign (or reassign) a sales agent, who is
                   notified and takes the lead onto their own board. -->
              <div v-if="canAssign()" class="w-56">
                <BaseSelect
                  :model-value="row.client.assigned_agent?.id ?? ''"
                  :options="agentOptions"
                  :placeholder="row.client.assigned_agent ? 'Reassign to…' : 'Assign to an agent…'"
                  :disabled="row.assigning"
                  @change="(v) => assign(row, v)"
                />
              </div>

              <!-- Assignee: do the work. Only the owner sees Reconnect, so a
                   manager can't accidentally become the caller. -->
              <Button
                v-if="isAssignee(row)"
                label="Reconnect & qualify"
                icon="pi pi-replay"
                size="small"
                :disabled="!selectedCount(row)"
                @click="openReconnect(row)"
              />
              <span
                v-else-if="!canAssign()"
                class="text-xs text-mute"
              >
                Waiting on {{ row.client.assigned_agent?.name ?? 'an agent' }} to reconnect.
              </span>
            </div>
          </div>
        </div>
      </SectionCard>
    </div>

    <!-- Reconnect & qualify — the standard call-log qualification, pre-loaded
         with the matches picked above. No project exists yet: the call itself
         opens one the moment it qualifies with properties or closes into a
         deal (EnsureActiveClientProject), the same rule as every other call. -->
    <BaseModal
      v-if="reconnectOpen"
      title="Reconnect — log the call"
      @close="reconnectOpen = false; reconnectRow = null"
    >
      <p class="mb-4 text-sm text-mute">
        Logging this call is what reopens the client — the properties picked below ride with it
        as the shortlist (or an open deal, if it goes that far).
      </p>
      <CallLogForm
        :client="reconnectRow?.client"
        :field-agents="store.agents"
        :saving="store.saving"
        :desire="reconnectRow?.desire"
        :initial-properties="reconnectProperties"
        :draft-key="`call-log:desire-match:${reconnectRow?.client.id}`"
        @submit="submitReconnect"
        @cancel="reconnectOpen = false; reconnectRow = null"
      />
    </BaseModal>
  </div>
</template>
