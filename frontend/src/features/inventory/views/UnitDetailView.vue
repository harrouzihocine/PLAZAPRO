<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { RouterLink } from 'vue-router'
import Skeleton from 'primevue/skeleton'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ActivityTimeline from '@/components/ui/ActivityTimeline.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import MediaGallery from '@/features/inventory/components/MediaGallery.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import ShareToChat from '@/features/collaboration/components/ShareToChat.vue'
import PaymentsPanel from '@/features/payments/components/PaymentsPanel.vue'
import UnitProjectLogs from '@/features/inventory/components/UnitProjectLogs.vue'
import FeedbackPanel from '@/features/analytics/components/FeedbackPanel.vue'
import { unitsApi } from '@/features/inventory/api'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { useAuthStore } from '@/features/settings/store'
import { formatDate } from '@/utils/format'
import { formatMoney } from '@/features/payments/money'
import { t } from '@/i18n'
import { statusMeta } from '@/utils/status'

// One unit's page: commercial status up top, how it has moved through the
// pipeline (stats), the money collected against it (payments), the full spec
// sheet + own media, and the complete audit history — organized into tabs.
const props = defineProps({ id: { type: [String, Number], required: true } })
const units = useUnitsStore()
const auth = useAuthStore()

const insights = ref(null)
const insightsError = ref(false)
// Pipeline statistics + Voice-of-Client analytics are commercial intelligence
// behind their own grant; the backend also drops insights.stats without it.
const canSeeStats = auth.can('units.stats')

// One-line context under the sale status: how many projects hold it, or that a
// deposit Reserved it.
const saleHint = computed(() => {
  const s = insights.value?.stats
  if (!s) return ''
  if (s.sale_status === 'reserved') return t('inventory.reservedDeposit')
  if (s.interested_count > 1) return t('inventory.nProjects', { n: s.interested_count })
  return ''
})

async function load() {
  units.fetchOne(props.id)
  try {
    insights.value = await unitsApi.insights(props.id)
    insightsError.value = false
  } catch (e) {
    // Fail soft (the spec sheet + media still render) but never silently: a
    // swallowed error here makes the whole stats/payments block vanish with no
    // clue why. Surface it so a real failure is diagnosable.
    insights.value = null
    insightsError.value = true
    console.error('Failed to load unit insights', e)
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh (APK)
</script>

<template>
  <div>
    <div v-if="!units.current" class="space-y-4">
      <Skeleton width="14rem" height="2rem" />
      <Skeleton height="10rem" />
    </div>

    <template v-else>
      <PageHeader
        :title="$t('search.unitTitle', { ref: units.current.reference })"
        :back="
          units.current.location_id
            ? { name: 'inventory.location', params: { id: units.current.location_id } }
            : { name: 'inventory.units' }
        "
      >
        <template #back-label>{{ units.current.location?.name || $t('nav.units') }}</template>
        <template #badges>
          <SaleStatusBadge
            :status="units.current.sale_status"
            :interested-count="units.current.interested_count"
          />
          <GtmPriorityBadge
            v-if="units.current.gtm_priority"
            :priority="units.current.gtm_priority"
          />
          <StatusTag v-if="units.current.status !== 'active'" :value="units.current.status" />
        </template>
        <template #subtitle>
          <template v-if="units.current.location">
            {{ units.current.location.name }}
            <template v-if="units.current.location.wilaya">
              · {{ units.current.location.wilaya }}
              <template v-if="units.current.location.commune">
                ({{ units.current.location.commune }})
              </template>
            </template>
          </template>
        </template>
        <template #actions>
          <ShareToChat
            v-if="auth.can('chat.use')"
            subject-type="unit"
            :subject-id="Number(id)"
:label="$t('project.share')"
          />
        </template>
      </PageHeader>

      <!-- The numbers a seller quotes first -->
      <div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <StatCard
          v-if="units.current.price_semi_fini != null"
          :label="$t('inventory.priceSemiFini')"
          :value="formatMoney(units.current.price_semi_fini)"
          icon="pi pi-money-bill"
        />
        <StatCard
          v-if="units.current.price_fini != null"
          :label="$t('inventory.priceFini')"
          :value="formatMoney(units.current.price_fini)"
          icon="pi pi-money-bill"
          tone="success"
        />
        <StatCard
:label="$t('desire.area')"
          :value="units.current.area_sqm ? `${units.current.area_sqm} m²` : '—'"
          icon="pi pi-expand"
          tone="info"
        />
        <StatCard :label="$t('inventory.projectType')" :value="units.current.location?.type || '—'" icon="pi pi-home" />
        <StatCard :label="$t('inventory.floor')" :value="units.current.floor || '—'" icon="pi pi-building" />
      </div>

      <!-- Insights failed to load: say so rather than silently dropping the block. -->
      <div
        v-if="insightsError"
        class="mb-5 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
      >
        <i class="pi pi-exclamation-triangle" aria-hidden="true" />
        {{ $t('inventory.unitInsightsFailed') }}
      </div>

      <!-- How the unit has moved: interest holds, shortlists, deals. The stats
           block only exists in the response for units.stats holders. -->
      <div v-if="insights?.stats" class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <StatCard
:label="$t('inventory.interestHolds')"
          :value="insights.stats.reservations"
          icon="pi pi-thumbs-up"
          :tone="insights.stats.has_active_hold ? 'warning' : 'default'"
          :hint="insights.stats.has_active_hold ? $t('inventory.activeHold') : ''"
        />
        <StatCard :label="$t('inventory.timesShortlisted')" :value="insights.stats.times_shortlisted" icon="pi pi-star" tone="info" />
        <StatCard :label="$t('inventory.inDeals')" :value="insights.stats.deals" icon="pi pi-briefcase" />
        <StatCard
:label="$t('inventory.saleStatus')"
          :value="insights.stats.sale_status ? statusMeta(insights.stats.sale_status).label : '—'"
          icon="pi pi-tag"
          :tone="
            insights.stats.sale_status === 'sold'
              ? 'success'
              : insights.stats.sale_status === 'reserved'
                ? 'warning'
                : 'default'
          "
          :hint="saleHint"
        />
      </div>

      <Tabs value="overview" scrollable lazy>
        <TabList>
          <Tab value="overview">{{ $t('inventory.tabOverview') }}</Tab>
          <Tab v-if="insights?.payments" value="payments">{{ $t('nav.payments') }}</Tab>
          <Tab v-if="canSeeStats" value="feedback">{{ $t('inventory.tabVoiceOfClient') }}</Tab>
          <Tab value="project-logs">{{ $t('inventory.projectLogs') }}</Tab>
          <Tab value="activity">{{ $t('inventory.tabActivity') }}</Tab>
        </TabList>
        <TabPanels>
          <!-- Overview: media, spec sheet, parent project -->
          <TabPanel value="overview">
            <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
              <div class="space-y-5 xl:col-span-2">
                <MediaGallery
                  mediable-type="units"
                  :mediable-id="props.id"
                  :can-manage="auth.can('media.manage')"
                />

                <SectionCard :title="$t('inventory.specifications')" icon="pi pi-list">
                  <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                    <div>
                      <dt class="text-xs text-mute">{{ $t('inventory.reference') }}</dt>
                      <dd class="mt-0.5 font-medium text-ink">{{ units.current.reference }}</dd>
                    </div>
                    <div v-if="units.current.room_number">
                      <dt class="text-xs text-mute">{{ $t('inventory.roomNumber') }}</dt>
                      <dd class="mt-0.5 text-ink">{{ units.current.room_number }}</dd>
                    </div>
                    <div v-if="units.current.block">
                      <dt class="text-xs text-mute">{{ $t('inventory.block') }}</dt>
                      <dd class="mt-0.5 text-ink">{{ units.current.block }}</dd>
                    </div>
                    <div v-if="units.current.stack_floor != null">
                      <dt class="text-xs text-mute">{{ $t('inventory.stackFloor') }}</dt>
                      <dd class="num mt-0.5 text-ink">{{ units.current.stack_floor }}</dd>
                    </div>
                    <div v-if="units.current.position != null">
                      <dt class="text-xs text-mute">{{ $t('inventory.position') }}</dt>
                      <dd class="num mt-0.5 text-ink">{{ units.current.position }}</dd>
                    </div>
                    <div>
                      <dt class="text-xs text-mute">{{ $t('clients.created') }}</dt>
                      <dd class="mt-0.5 text-ink">{{ formatDate(units.current.created_at) }}</dd>
                    </div>
                  </dl>
                </SectionCard>
              </div>

              <div class="space-y-5">
                <SectionCard v-if="units.current.location" :title="$t('inventory.project')" icon="pi pi-building">
                  <RouterLink
                    :to="{ name: 'inventory.location', params: { id: units.current.location_id } }"
                    class="font-medium text-ink hover:underline"
                  >
                    {{ units.current.location.name }}
                  </RouterLink>
                  <dl class="mt-3 space-y-2.5 text-sm">
                    <div
                      v-if="units.current.location.contract_type"
                      class="flex justify-between gap-3"
                    >
                      <dt class="text-mute">{{ $t('inventory.contract') }}</dt>
                      <dd class="text-ink">{{ units.current.location.contract_type }}</dd>
                    </div>
                    <div
                      v-if="units.current.location.expected_delivery_date"
                      class="flex justify-between gap-3"
                    >
                      <dt class="text-mute">{{ $t('inventory.delivery') }}</dt>
                      <dd class="text-ink">
                        {{ formatDate(units.current.location.expected_delivery_date) }}
                      </dd>
                    </div>
                    <div v-if="units.current.location.gtm_priority" class="flex justify-between gap-3">
                      <dt class="text-mute">{{ $t('tasks.priority') }}</dt>
                      <dd><GtmPriorityBadge :priority="units.current.location.gtm_priority" /></dd>
                    </div>
                  </dl>
                </SectionCard>
              </div>
            </div>
          </TabPanel>

          <!-- Payments: the buying project's schedule + versements, in full -->
          <TabPanel v-if="insights?.payments" value="payments">
            <template v-if="insights.payments.has_data && insights.payments.project_id">
              <!-- At-a-glance totals -->
              <div class="mb-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <StatCard
:label="$t('inventory.collected')"
                  :value="formatMoney(insights.payments.collected)"
                  icon="pi pi-money-bill"
                  tone="success"
                />
                <StatCard
:label="$t('status.scheduled')"
                  :value="formatMoney(insights.payments.schedule_total)"
                  icon="pi pi-calendar"
                />
                <StatCard
:label="$t('status.paid')"
                  :value="formatMoney(insights.payments.schedule_paid)"
                  icon="pi pi-check-circle"
                  tone="info"
                />
                <StatCard
:label="$t('inventory.balance')"
                  :value="formatMoney(insights.payments.balance)"
                  icon="pi pi-hourglass"
                  :tone="Number(insights.payments.balance) > 0 ? 'warning' : 'default'"
                />
              </div>

              <!-- THIS unit's full payment detail (schedule + versements) — the
                   panel is unit-scoped so another apartment sold on the same
                   project never mixes into these figures. -->
              <PaymentsPanel
                :project-id="insights.payments.project_id"
                :total-price="insights.payments.total_price"
                :unit-id="Number(id)"
              />

              <RouterLink
                v-if="insights.payments.client_id"
                :to="{
                  name: 'clients.project',
                  params: {
                    id: insights.payments.client_id,
                    projectId: insights.payments.project_id,
                  },
                }"
                class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:underline"
              >
                <i class="pi pi-external-link text-xs" aria-hidden="true" />
                {{ insights.payments.client_name || $t('inventory.openBuyingProject') }}
              </RouterLink>
            </template>
            <SectionCard v-else :title="$t('nav.payments')" icon="pi pi-wallet">
              <EmptyState
                icon="pi pi-wallet"
:title="$t('inventory.noPaymentsTitle')"
                :body="$t('inventory.noPaymentsBody')"
              />
            </SectionCard>
          </TabPanel>

          <!-- Voice of Client for this unit: objections, sentiment, verbatims. -->
          <TabPanel v-if="canSeeStats" value="feedback">
            <FeedbackPanel :id="props.id" scope="unit" />
          </TabPanel>

          <!-- Every client project that has touched this unit, each with its own
               interaction timeline (calls + visits) in the project's design. -->
          <TabPanel value="project-logs">
            <UnitProjectLogs :unit-id="Number(props.id)" />
          </TabPanel>

          <!-- The record's full audit history: price corrections, status flips, holds. -->
          <TabPanel value="activity">
            <SectionCard :title="$t('project.history')" icon="pi pi-clock">
              <ActivityTimeline :id="Number(props.id)" type="unit" />
            </SectionCard>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </template>
  </div>
</template>
