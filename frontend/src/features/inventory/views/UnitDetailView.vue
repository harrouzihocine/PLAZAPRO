<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import BaseCard from '@/components/base/BaseCard.vue'
import GtmPriorityBadge from '@/features/inventory/components/GtmPriorityBadge.vue'
import MediaGallery from '@/features/inventory/components/MediaGallery.vue'
import SaleStatusBadge from '@/features/inventory/components/SaleStatusBadge.vue'
import { useUnitsStore } from '@/features/inventory/unitsStore'
import { useAuthStore } from '@/features/settings/store'

const props = defineProps({ id: { type: [String, Number], required: true } })
const units = useUnitsStore()
const auth = useAuthStore()

onMounted(() => units.fetchOne(props.id))
</script>

<template>
  <div class="space-y-4">
    <template v-if="units.current">
      <RouterLink
        v-if="units.current.location_id"
        :to="{ name: 'inventory.location', params: { id: units.current.location_id } }"
        class="text-sm opacity-70 hover:text-primary"
      >
        ← {{ units.current.location?.name || 'Project' }}
      </RouterLink>

      <div class="flex flex-wrap items-center gap-2">
        <h1 class="text-xl font-semibold">{{ units.current.reference }}</h1>
        <SaleStatusBadge :status="units.current.sale_status" />
        <GtmPriorityBadge
          v-if="units.current.gtm_priority"
          :priority="units.current.gtm_priority"
        />
      </div>

      <BaseCard>
        <dl class="grid gap-2 sm:grid-cols-3 lg:grid-cols-4">
          <div>
            <dt class="text-xs opacity-60">Type</dt>
            <dd>{{ units.current.type || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs opacity-60">Floor</dt>
            <dd>{{ units.current.floor || '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs opacity-60">Area (m²)</dt>
            <dd>{{ units.current.area_sqm ?? '—' }}</dd>
          </div>
          <div v-if="units.current.rooms != null">
            <dt class="text-xs opacity-60">Rooms</dt>
            <dd>{{ units.current.rooms }}</dd>
          </div>
          <div>
            <dt class="text-xs opacity-60">Price</dt>
            <dd>{{ units.current.price ?? '—' }}</dd>
          </div>
          <div v-if="units.current.block">
            <dt class="text-xs opacity-60">Block</dt>
            <dd>{{ units.current.block }}</dd>
          </div>
          <div v-if="units.current.stack_floor != null">
            <dt class="text-xs opacity-60">Stack floor</dt>
            <dd>{{ units.current.stack_floor }}</dd>
          </div>
          <div v-if="units.current.position != null">
            <dt class="text-xs opacity-60">Position</dt>
            <dd>{{ units.current.position }}</dd>
          </div>
          <div v-if="units.current.location?.expected_delivery_date">
            <dt class="text-xs opacity-60">Project delivery</dt>
            <dd>{{ units.current.location.expected_delivery_date }}</dd>
          </div>
          <div v-if="units.current.location?.gtm_priority">
            <dt class="text-xs opacity-60">Project priority</dt>
            <dd><GtmPriorityBadge :priority="units.current.location.gtm_priority" /></dd>
          </div>
        </dl>
      </BaseCard>

      <!-- This unit's own media (separate from the project's) -->
      <MediaGallery
        mediable-type="units"
        :mediable-id="props.id"
        :can-manage="auth.can('media.manage')"
      />
    </template>

    <p v-else class="py-4 text-center text-sm opacity-60">Loading…</p>
  </div>
</template>
