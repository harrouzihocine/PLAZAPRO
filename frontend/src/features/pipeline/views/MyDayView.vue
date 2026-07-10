<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Swal from 'sweetalert2'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { pipelineApi } from '@/features/pipeline/api'
import { VISIT_STATUS_LABEL_KEYS, VISIT_STATUS_TONES } from '@/features/pipeline/dispatchStatus'
import { useAuthStore } from '@/features/settings/store'
import { BASE_SWAL_OPTS, toastError, toastSuccess } from '@/composables/useConfirm'
import { useDutyTracking } from '@/composables/useDutyTracking'
import { getEcho } from '@/composables/useEcho'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { formatDateTime } from '@/utils/format'
import { t } from '@/i18n'

// The field agent's day: the duty switch (share location while on), and
// today's visits as a stepper — Accept → On my way → Arrived (the geofence
// usually stamps arrival before the tap) → complete from the project page.
// Declining asks why and returns the task to the dispatcher's pool.

const auth = useAuthStore()
const duty = useDutyTracking()

const loading = ref(true)
const visits = ref([])
const routeOrder = ref([])
const orderMode = ref('time') // 'time' | 'route'
const busyId = ref(null)
const dutyBusy = ref(false)

async function load() {
  loading.value = true
  try {
    const data = await pipelineApi.myDay()
    visits.value = data.visits
    routeOrder.value = data.route_order ?? []
    syncEnRouteCadence()
  } finally {
    loading.value = false
  }
}

onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

const ordered = computed(() => {
  if (orderMode.value !== 'route' || !routeOrder.value.length) return visits.value
  const rank = new Map(routeOrder.value.map((id, i) => [id, i]))
  return [...visits.value].sort(
    (a, b) => (rank.get(a.id) ?? 999) - (rank.get(b.id) ?? 999),
  )
})

const openCount = computed(() => visits.value.filter((v) => v.status !== 'done').length)

// Tighter GPS cadence while something is en route.
function syncEnRouteCadence() {
  duty.setEnRoute(visits.value.some((v) => v.status === 'en_route'))
}

async function toggleDuty(on) {
  dutyBusy.value = true
  try {
    await duty.setDuty(on)
  } catch (e) {
    toastError(e.response?.data?.message ?? t('myday.dutyFailed'))
  } finally {
    dutyBusy.value = false
  }
}

async function step(visit, action) {
  busyId.value = visit.id
  try {
    const data = await action(visit.id)
    Object.assign(visit, {
      status: data.status,
      accepted_at: data.accepted_at,
      en_route_at: data.en_route_at,
      arrived_at: data.arrived_at,
    })
    syncEnRouteCadence()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('common.actionFailed'))
  } finally {
    busyId.value = null
  }
}

async function decline(visit) {
  const { value: reason, isConfirmed } = await Swal.fire({
    ...BASE_SWAL_OPTS,
    title: t('myday.declineTitle'),
    text: t('myday.declineHint'),
    input: 'text',
    inputAttributes: { maxlength: 255 },
    showCancelButton: true,
    confirmButtonText: t('myday.decline'),
    cancelButtonText: t('common.cancel'),
    inputValidator: (v) => (v?.trim() ? undefined : t('myday.declineReasonRequired')),
  })
  if (!isConfirmed) return

  busyId.value = visit.id
  try {
    await pipelineApi.declineVisit(visit.id, reason.trim())
    toastSuccess(t('myday.declined'))
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('common.actionFailed'))
  } finally {
    busyId.value = null
  }
}

// Geofence arrivals land while the phone is open: reflect them live.
let channel = null
onMounted(() => {
  const echo = getEcho()
  if (!echo || !auth.user?.id) return
  channel = echo.private(`users.${auth.user.id}`)
  channel.listen('.visit.lifecycle', (e) => {
    const visit = visits.value.find((v) => v.id === e.visit_id)
    if (visit) {
      visit.status = e.status
      syncEnRouteCadence()
    }
  })
})
onBeforeUnmount(() => {
  channel?.stopListening('.visit.lifecycle')
})

const statusTone = (s) => VISIT_STATUS_TONES[s] ?? VISIT_STATUS_TONES.assigned
const statusLabel = (s) => t(VISIT_STATUS_LABEL_KEYS[s] ?? VISIT_STATUS_LABEL_KEYS.assigned)
</script>

<template>
  <div>
    <PageHeader :title="$t('myday.title')" :subtitle="$t('myday.subtitle')" />

    <!-- Duty switch: the location-sharing contract, stated plainly. -->
    <SectionCard class="mb-5">
      <div class="flex flex-wrap items-center gap-3">
        <ToggleSwitch
          :model-value="duty.onDuty.value"
          :disabled="dutyBusy || !duty.supported"
          :aria-label="$t('myday.dutySwitch')"
          @update:model-value="toggleDuty"
        />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-ink">
            {{ duty.onDuty.value ? $t('myday.onDuty') : $t('myday.offDuty') }}
            <span
              v-if="duty.onDuty.value"
              class="ms-1 inline-block h-2 w-2 animate-pulse rounded-full bg-emerald-500 align-middle"
              aria-hidden="true"
            />
          </p>
          <p class="text-xs text-mute">{{ $t('myday.dutyHint') }}</p>
          <p v-if="duty.geoDenied.value" class="mt-1 text-xs font-medium text-danger">
            {{ $t('myday.geoDenied') }}
          </p>
          <p v-else-if="!duty.supported" class="mt-1 text-xs font-medium text-danger">
            {{ $t('myday.geoUnsupported') }}
          </p>
        </div>
      </div>
    </SectionCard>

    <!-- Today's visits -->
    <SectionCard
      :title="$t('myday.visitsTitle', { n: openCount })"
      icon="pi pi-map-marker"
    >
      <template #actions>
        <div v-if="routeOrder.length > 1" class="flex gap-1">
          <Button
            :label="$t('myday.byTime')"
            size="small"
            :severity="orderMode === 'time' ? 'primary' : 'secondary'"
            :outlined="orderMode !== 'time'"
            icon="pi pi-clock"
            @click="orderMode = 'time'"
          />
          <Button
            :label="$t('myday.byRoute')"
            size="small"
            :severity="orderMode === 'route' ? 'primary' : 'secondary'"
            :outlined="orderMode !== 'route'"
            icon="pi pi-directions"
            @click="orderMode = 'route'"
          />
        </div>
      </template>

      <p v-if="loading" class="py-6 text-center text-sm text-mute">{{ $t('common.loading') }}</p>
      <p v-else-if="!visits.length" class="py-6 text-center text-sm text-mute">
        <i class="pi pi-check-circle me-1 text-success" aria-hidden="true" />
        {{ $t('myday.noVisits') }}
      </p>

      <ul v-else class="space-y-3">
        <li
          v-for="visit in ordered"
          :key="visit.id"
          class="rounded-xl border border-line p-3"
          :class="visit.status === 'done' ? 'opacity-60' : ''"
        >
          <div class="flex flex-wrap items-center gap-2">
            <i
              :class="visit.type === 'office' ? 'pi pi-building text-sky-500' : 'pi pi-map-marker text-amber-500'"
              aria-hidden="true"
            />
            <span class="min-w-0 flex-1 truncate font-medium text-ink">{{ visit.client ?? '—' }}</span>
            <span
              class="rounded-full px-2 py-0.5 text-xs font-medium"
              :class="statusTone(visit.status)"
            >
              {{ statusLabel(visit.status) }}
            </span>
          </div>

          <div class="num mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-mute">
            <span :class="visit.is_overdue ? 'font-medium text-danger' : ''">
              <i class="pi pi-clock text-[10px]" aria-hidden="true" />
              {{ visit.is_overdue ? $t('myday.overdue') + ' · ' : '' }}{{ visit.time ?? formatDateTime(visit.scheduled_at) }}
            </span>
            <span v-if="visit.unit">{{ visit.unit }}</span>
            <span v-if="visit.site?.name" class="truncate">
              <i class="pi pi-building text-[10px]" aria-hidden="true" /> {{ visit.site.name }}
            </span>
          </div>

          <!-- The stepper + tools. Completion stays on the project page (the
               full outcome form lives there). -->
          <div v-if="visit.type === 'in_site'" class="mt-2.5 flex flex-wrap items-center gap-1.5">
            <Button
              v-if="visit.status === 'assigned'"
              :label="$t('myday.accept')"
              icon="pi pi-check"
              size="small"
              :loading="busyId === visit.id"
              @click="step(visit, pipelineApi.acceptVisit)"
            />
            <Button
              v-if="visit.status === 'accepted'"
              :label="$t('myday.enRoute')"
              icon="pi pi-directions"
              size="small"
              :loading="busyId === visit.id"
              @click="step(visit, pipelineApi.enRouteVisit)"
            />
            <Button
              v-if="visit.status === 'en_route'"
              :label="$t('myday.arrived')"
              icon="pi pi-flag"
              size="small"
              :loading="busyId === visit.id"
              @click="step(visit, pipelineApi.arrivedVisit)"
            />
            <a
              v-if="visit.site?.maps_url && visit.status !== 'done'"
              :href="visit.site.maps_url"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-xs font-medium text-primary-600 hover:bg-highlight dark:text-primary-400"
            >
              <i class="pi pi-map" aria-hidden="true" /> {{ $t('myday.navigate') }}
            </a>
            <Button
              v-if="visit.can_decline && visit.status !== 'done'"
              :label="$t('myday.decline')"
              icon="pi pi-times"
              size="small"
              text
              severity="danger"
              :loading="busyId === visit.id"
              @click="decline(visit)"
            />
          </div>

          <div class="mt-2">
            <RouterLink
              v-if="visit.link"
              :to="visit.link"
              class="inline-flex items-center gap-1.5 text-xs text-primary-600 hover:underline dark:text-primary-400"
            >
              <i class="pi pi-folder-open" aria-hidden="true" /> {{ $t('dispatch.openProject') }}
            </RouterLink>
          </div>
        </li>
      </ul>
    </SectionCard>
  </div>
</template>
