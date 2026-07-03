<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import Skeleton from 'primevue/skeleton'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatCard from '@/components/ui/StatCard.vue'
import ActivityTimeline from '@/components/ui/ActivityTimeline.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import MediaGallery from '@/features/inventory/components/MediaGallery.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import ShareToChat from '@/features/collaboration/components/ShareToChat.vue'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { useAuthStore } from '@/features/settings/store'
import { formatDate } from '@/utils/format'
import { formatMoney } from '@/features/payments/money'

// One unit's page: commercial status up top, the full spec sheet, its own media
// (separate from the project's) and the complete audit history of the record.
const props = defineProps({ id: { type: [String, Number], required: true } })
const units = useUnitsStore()
const auth = useAuthStore()

onMounted(() => units.fetchOne(props.id))
</script>

<template>
  <div>
    <div v-if="!units.current" class="space-y-4">
      <Skeleton width="14rem" height="2rem" />
      <Skeleton height="10rem" />
    </div>

    <template v-else>
      <PageHeader
        :title="`Unit ${units.current.reference}`"
        :back="
          units.current.location_id
            ? { name: 'inventory.location', params: { id: units.current.location_id } }
            : { name: 'inventory.units' }
        "
      >
        <template #back-label>{{ units.current.location?.name || 'Units' }}</template>
        <template #badges>
          <SaleStatusBadge :status="units.current.sale_status" />
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
            label="Share"
          />
        </template>
      </PageHeader>

      <!-- The numbers a seller quotes first -->
      <div class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <StatCard label="Price" :value="formatMoney(units.current.price)" icon="pi pi-money-bill" />
        <StatCard
          label="Area"
          :value="units.current.area_sqm ? `${units.current.area_sqm} m²` : '—'"
          icon="pi pi-expand"
          tone="info"
        />
        <StatCard
          label="Type"
          :value="units.current.type || '—'"
          icon="pi pi-home"
          tone="default"
        />
        <StatCard
          label="Floor"
          :value="units.current.floor || '—'"
          icon="pi pi-building"
          tone="default"
        />
      </div>

      <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="space-y-5 xl:col-span-2">
          <!-- This unit's own media (separate from the project's) -->
          <MediaGallery
            mediable-type="units"
            :mediable-id="props.id"
            :can-manage="auth.can('media.manage')"
          />

          <SectionCard title="Specifications" icon="pi pi-list">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
              <div>
                <dt class="text-xs text-mute">Reference</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ units.current.reference }}</dd>
              </div>
              <div v-if="units.current.rooms != null">
                <dt class="text-xs text-mute">Rooms</dt>
                <dd class="num mt-0.5 text-ink">{{ units.current.rooms }}</dd>
              </div>
              <div v-if="units.current.block">
                <dt class="text-xs text-mute">Block</dt>
                <dd class="mt-0.5 text-ink">{{ units.current.block }}</dd>
              </div>
              <div v-if="units.current.stack_floor != null">
                <dt class="text-xs text-mute">Stack floor</dt>
                <dd class="num mt-0.5 text-ink">{{ units.current.stack_floor }}</dd>
              </div>
              <div v-if="units.current.position != null">
                <dt class="text-xs text-mute">Position</dt>
                <dd class="num mt-0.5 text-ink">{{ units.current.position }}</dd>
              </div>
              <div>
                <dt class="text-xs text-mute">Created</dt>
                <dd class="mt-0.5 text-ink">{{ formatDate(units.current.created_at) }}</dd>
              </div>
            </dl>
          </SectionCard>
        </div>

        <div class="space-y-5">
          <SectionCard v-if="units.current.location" title="Project" icon="pi pi-building">
            <RouterLink
              :to="{ name: 'inventory.location', params: { id: units.current.location_id } }"
              class="font-medium text-ink hover:underline"
            >
              {{ units.current.location.name }}
            </RouterLink>
            <dl class="mt-3 space-y-2.5 text-sm">
              <div v-if="units.current.location.contract_type" class="flex justify-between gap-3">
                <dt class="text-mute">Contract</dt>
                <dd class="text-ink">{{ units.current.location.contract_type }}</dd>
              </div>
              <div
                v-if="units.current.location.expected_delivery_date"
                class="flex justify-between gap-3"
              >
                <dt class="text-mute">Delivery</dt>
                <dd class="text-ink">
                  {{ formatDate(units.current.location.expected_delivery_date) }}
                </dd>
              </div>
              <div v-if="units.current.location.gtm_priority" class="flex justify-between gap-3">
                <dt class="text-mute">Priority</dt>
                <dd><GtmPriorityBadge :priority="units.current.location.gtm_priority" /></dd>
              </div>
            </dl>
          </SectionCard>

          <!-- The record's full audit history: price corrections, status flips, holds. -->
          <SectionCard title="History" icon="pi pi-clock">
            <ActivityTimeline :id="Number(props.id)" type="unit" />
          </SectionCard>
        </div>
      </div>
    </template>
  </div>
</template>
