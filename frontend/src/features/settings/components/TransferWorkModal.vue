<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { usersApi } from '@/features/settings/api'
import { useAuthStore } from '@/features/settings/store'
import { toastError } from '@/composables/useConfirm'
import { formatDateTime, humanize } from '@/utils/format'
import { t } from '@/i18n'

// Offboarding wizard: what the user did (career — stays under their name
// forever) and what they still own (the open book), then the hand-over: pick
// an active successor and move everything in one shot. The confirm + API call
// live in UsersView (the `transfer` emit), like the other settings modals.
const props = defineProps({
  user: { type: Object, required: true }, // the leaver
  users: { type: Array, default: () => [] }, // successor candidates (users list)
  saving: { type: Boolean, default: false },
})
const emit = defineEmits(['transfer', 'close'])

const auth = useAuthStore()
const workload = ref(null)
const loading = ref(true)
const successorId = ref('')
const sameRoleOnly = ref(true)
const dispatchToPool = ref(false)
const deactivateAfter = ref(Boolean(props.user.is_active))

onMounted(async () => {
  try {
    workload.value = await usersApi.workload(props.user.id)
  } catch (e) {
    toastError(e.response?.data?.message ?? t('transfer.loadFailed'))
    emit('close')
  } finally {
    loading.value = false
  }
})

// Active colleagues only. "Same role" narrows to the natural successors and
// falls back to everyone when the leaver's role has no other active member.
const candidates = computed(() => {
  const pool = props.users.filter((u) => u.id !== props.user.id && u.is_active)
  if (!sameRoleOnly.value) return pool
  const sameRole = pool.filter((u) => u.role?.id === props.user.role?.id)
  return sameRole.length ? sameRole : pool
})

// Resolved from the VISIBLE candidates, not the raw users list: re-narrowing
// the role filter after picking someone must not keep a hidden selection live.
const successor = computed(() => candidates.value.find((u) => u.id === successorId.value) ?? null)

const open = computed(() => workload.value?.open ?? null)
const openTotal = computed(() => workload.value?.open_total ?? 0)
// Exact backend count — the items list is capped, so it can't be derived there.
const hasPoolablePlans = computed(() => (open.value?.next_actions.poolable_total ?? 0) > 0)

// Deactivation runs through the users.manage endpoint — a transfer-only
// holder would 403 after a committed transfer, so don't offer it to them.
const canDeactivate = computed(
  () => Boolean(props.user.is_active) && auth.can('users.manage'),
)

const careerStats = computed(() => {
  const c = workload.value?.career
  if (!c) return []
  return [
    { label: t('transfer.clientsCreated'), value: c.clients_created },
    { label: t('transfer.projectsOpened'), value: c.projects_opened },
    { label: t('transfer.callsLogged'), value: c.calls_logged },
    { label: t('transfer.visitsConducted'), value: c.visits_conducted },
    { label: t('transfer.dealsWon'), value: `${c.deals_won} / ${c.deals_opened}` },
    { label: t('transfer.paymentsRecorded'), value: c.payments_recorded },
  ]
})

const sections = computed(() => {
  if (!open.value) return []
  return [
    { key: 'clients', title: t('transfer.clientsToFollow'), data: open.value.clients },
    { key: 'projects', title: t('transfer.projectSeats'), data: open.value.projects },
    { key: 'next_actions', title: t('pipeline.tabNextActions'), data: open.value.next_actions },
    { key: 'visits', title: t('transfer.scheduledVisits'), data: open.value.visits },
    { key: 'tasks', title: t('transfer.openTasks'), data: open.value.tasks },
  ].filter((s) => s.data.total > 0)
})

function lineFor(key, item) {
  switch (key) {
    case 'clients':
      return [item.name, item.phone].filter(Boolean).join(' · ')
    case 'projects':
      return [item.client, item.location].filter(Boolean).join(' · ')
    case 'next_actions':
      return [humanize(item.type), item.client, formatDateTime(item.due_at)]
        .filter(Boolean)
        .join(' · ')
    case 'visits':
      return [humanize(item.type), item.client, item.unit, formatDateTime(item.scheduled_at)]
        .filter(Boolean)
        .join(' · ')
    default:
      return [item.title, formatDateTime(item.due_at)].filter(Boolean).join(' · ')
  }
}

function submit() {
  if (!successor.value) return
  emit('transfer', {
    successor_id: successor.value.id,
    successor_name: successor.value.name,
    dispatch_to_pool: dispatchToPool.value && hasPoolablePlans.value,
    deactivate: deactivateAfter.value && canDeactivate.value,
    open_total: openTotal.value,
  })
}
</script>

<template>
  <BaseModal :title="$t('transfer.modalTitle', { name: user.name })" size="max-w-2xl" @close="emit('close')">
    <div v-if="loading" class="flex items-center justify-center py-12">
      <i class="pi pi-spinner pi-spin text-2xl text-mute" :aria-label="$t('common.loading')" />
    </div>

    <div v-else-if="workload" class="space-y-5">
      <!-- Career: the record that stays under their name forever. -->
      <div>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">
          {{ $t('transfer.careerRecord', { name: user.name }) }}
        </h3>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
          <div
            v-for="stat in careerStats"
            :key="stat.label"
            class="rounded-lg bg-highlight px-3 py-2"
          >
            <p class="text-lg font-semibold text-ink">{{ stat.value }}</p>
            <p class="text-xs text-mute">{{ stat.label }}</p>
          </div>
        </div>
      </div>

      <!-- The open book: everything a successor must pick up. -->
      <EmptyState
        v-if="!openTotal"
        icon="pi pi-check-circle"
:title="$t('transfer.noOpenWork')"
        :body="$t('transfer.noOpenWorkBody')"
      />

      <template v-else>
        <div>
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">
            {{ $t('transfer.openWorkToHand', { n: openTotal }) }}
          </h3>
          <div class="space-y-3">
            <div
              v-for="section in sections"
              :key="section.key"
              class="rounded-lg border border-line"
            >
              <p class="border-b border-line px-3 py-2 text-sm font-medium text-ink">
                {{ section.title }}
                <span class="text-mute">({{ section.data.total }})</span>
              </p>
              <ul class="max-h-36 divide-y divide-line overflow-y-auto">
                <li
                  v-for="item in section.data.items"
                  :key="item.id"
                  class="flex items-center gap-2 px-3 py-1.5 text-sm text-ink"
                >
                  <span class="min-w-0 flex-1 truncate">{{ lineFor(section.key, item) }}</span>
                  <Tag
                    v-if="section.key === 'projects'"
                    :value="humanize(item.step)"
                    severity="info"
                    class="shrink-0"
                  />
                  <Tag
                    v-if="item.is_frozen"
                    value="frozen"
                    severity="secondary"
                    class="shrink-0"
                  />
                  <Tag v-if="item.is_overdue" value="overdue" severity="danger" class="shrink-0" />
                </li>
              </ul>
            </div>
          </div>
        </div>

        <!-- The successor. -->
        <div class="space-y-3">
          <BaseSelect
            v-model="successorId"
:label="$t('transfer.handTo')"
            required
:placeholder="$t('transfer.chooseSuccessor')"
            :clearable="false"
            :options="
              candidates.map((u) => ({
                value: u.id,
                label: `${u.name}${u.role ? ` — ${u.role.name}` : ''}`,
              }))
            "
          />
          <label class="flex cursor-pointer items-center gap-2 text-sm text-ink">
            <Checkbox v-model="sameRoleOnly" binary />
            Only show people with the same role ({{ user.role?.name ?? '—' }})
          </label>
          <label
            v-if="hasPoolablePlans"
            class="flex cursor-pointer items-center gap-2 text-sm text-ink"
          >
            <Checkbox v-model="dispatchToPool" binary />
            Return field-visit plans to the dispatch pool instead (a dispatcher re-assigns them)
          </label>
        </div>
      </template>

      <!-- Only alongside a real transfer: with no open work there is no submit,
           so the row's own Deactivate button is the honest path. -->
      <label
        v-if="openTotal && canDeactivate"
        class="flex cursor-pointer items-center gap-2 text-sm text-ink"
      >
        <Checkbox v-model="deactivateAfter" binary />
        Deactivate {{ user.name }}'s account right after
      </label>

      <div class="flex justify-end gap-2 pt-2">
        <Button :label="$t('common.close')" severity="secondary" outlined @click="emit('close')" />
        <Button
          v-if="openTotal"
:label="$t('transfer.transferEverything')"
          icon="pi pi-arrow-right-arrow-left"
          :disabled="!successor || saving"
          :loading="saving"
          @click="submit"
        />
      </div>
    </div>
  </BaseModal>
</template>
