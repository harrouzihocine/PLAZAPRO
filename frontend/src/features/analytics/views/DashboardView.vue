<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseCard from '@/components/base/BaseCard.vue'
import { analyticsApi } from '@/features/analytics/api'
import { formatMoney } from '@/features/payments/money'
import { useAuthStore } from '@/features/settings/store'

const auth = useAuthStore()
const name = computed(() => auth.user?.name ?? 'there')

const data = ref(null)
const loading = ref(true)
const denied = ref(false)
const error = ref('')

// Full class strings (not interpolated) so Tailwind's scanner keeps them.
const stageStyles = {
  lead: 'bg-border text-ink',
  negotiating: 'bg-warning/15 text-warning',
  reserved: 'bg-primary/15 text-primary',
  won: 'bg-success/15 text-success',
  lost: 'bg-danger/15 text-danger',
}

const scopeLabel = computed(() =>
  data.value?.scope === 'agent' ? 'Your book' : 'Company-wide',
)

function fmtDateTime(value) {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
}

onMounted(async () => {
  try {
    data.value = await analyticsApi.dashboard()
  } catch (e) {
    if (e.response?.status === 403) denied.value = true
    else error.value = 'Could not load the dashboard. Please try again.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
      <div>
        <h1 class="text-2xl font-semibold">Welcome, {{ name }}</h1>
        <p class="opacity-70">
          Role: <span class="text-primary">{{ auth.user?.role?.name ?? '—' }}</span>
        </p>
      </div>
      <span v-if="data" class="rounded-token border border-border px-3 py-1 text-sm opacity-80">
        {{ scopeLabel }}
      </span>
    </div>

    <p v-if="loading" class="py-6 text-center text-sm opacity-60">Loading…</p>

    <BaseCard v-else-if="denied">
      <p class="text-sm opacity-70">You don't have access to dashboard metrics.</p>
    </BaseCard>

    <BaseCard v-else-if="error">
      <p class="text-sm text-danger">{{ error }}</p>
    </BaseCard>

    <template v-else-if="data">
      <!-- KPI cards -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <BaseCard>
          <h2 class="mb-1 text-sm font-medium opacity-70">Clients</h2>
          <p class="text-3xl font-semibold text-primary">{{ data.kpis.clients }}</p>
        </BaseCard>
        <BaseCard>
          <h2 class="mb-1 text-sm font-medium opacity-70">Upcoming visits</h2>
          <p class="text-3xl font-semibold text-primary">{{ data.kpis.upcoming_visits }}</p>
        </BaseCard>
        <BaseCard>
          <h2 class="mb-1 text-sm font-medium opacity-70">Overdue actions</h2>
          <p
            class="text-3xl font-semibold"
            :class="data.kpis.overdue_actions > 0 ? 'text-danger' : 'text-primary'"
          >
            {{ data.kpis.overdue_actions }}
          </p>
        </BaseCard>
        <BaseCard>
          <h2 class="mb-1 text-sm font-medium opacity-70">Payments due</h2>
          <p class="text-3xl font-semibold text-primary">{{ data.kpis.payments_due.count }}</p>
          <p class="text-sm opacity-70">{{ formatMoney(data.kpis.payments_due.amount) }}</p>
        </BaseCard>
      </div>

      <!-- Deals by stage -->
      <BaseCard>
        <h2 class="mb-3 font-medium">Deals by stage</h2>
        <div class="flex flex-wrap gap-2">
          <span
            v-for="(count, stage) in data.deals_by_stage"
            :key="stage"
            class="rounded-token px-3 py-1 text-sm capitalize"
            :class="stageStyles[stage]"
          >
            {{ stage }}: <strong>{{ count }}</strong>
          </span>
        </div>
      </BaseCard>

      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <!-- Upcoming visits -->
        <BaseCard>
          <h2 class="mb-3 font-medium">Next visits</h2>
          <p v-if="!data.upcoming_visits.length" class="text-sm opacity-60">Nothing scheduled.</p>
          <ul v-else class="space-y-2 text-sm">
            <li
              v-for="v in data.upcoming_visits"
              :key="v.id"
              class="flex items-center justify-between gap-2 border-b border-border pb-2 last:border-0"
            >
              <span>
                <span class="font-medium">{{ v.client ?? '—' }}</span>
                <span class="opacity-60"> · {{ v.type }}{{ v.unit ? ` · ${v.unit}` : '' }}</span>
              </span>
              <span class="shrink-0 opacity-70">{{ fmtDateTime(v.scheduled_at) }}</span>
            </li>
          </ul>
        </BaseCard>

        <!-- Overdue actions -->
        <BaseCard>
          <h2 class="mb-3 font-medium">Overdue actions</h2>
          <p v-if="!data.overdue_actions.length" class="text-sm opacity-60">All caught up.</p>
          <ul v-else class="space-y-2 text-sm">
            <li
              v-for="a in data.overdue_actions"
              :key="a.id"
              class="flex items-center justify-between gap-2 border-b border-border pb-2 last:border-0"
            >
              <span>
                <span class="font-medium">{{ a.type.replace('_', ' ') }}</span>
                <span class="opacity-60"> · {{ a.assigned_to ?? '—' }}</span>
              </span>
              <span class="shrink-0 text-danger">{{ fmtDateTime(a.due_at) }}</span>
            </li>
          </ul>
        </BaseCard>
      </div>
    </template>
  </div>
</template>
