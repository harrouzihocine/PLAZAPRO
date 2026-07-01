<script setup>
import { computed } from 'vue'
import BaseCard from '@/components/base/BaseCard.vue'
import { useAuthStore } from '@/features/settings/store'

const auth = useAuthStore()
const name = computed(() => auth.user?.name ?? 'there')
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-2xl font-semibold">Welcome, {{ name }}</h1>
    <p class="opacity-70">
      Your role: <span class="text-primary">{{ auth.user?.role?.name ?? '—' }}</span>
    </p>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <BaseCard>
        <h2 class="mb-1 font-medium">My clients</h2>
        <p class="text-3xl font-semibold text-primary">—</p>
        <p class="text-sm opacity-60">Available in Phase 3</p>
      </BaseCard>
      <BaseCard>
        <h2 class="mb-1 font-medium">Upcoming visits</h2>
        <p class="text-3xl font-semibold text-primary">—</p>
        <p class="text-sm opacity-60">Available in Phase 3</p>
      </BaseCard>
      <BaseCard>
        <h2 class="mb-1 font-medium">Payments due</h2>
        <p class="text-3xl font-semibold text-primary">—</p>
        <p class="text-sm opacity-60">Available in Phase 4</p>
      </BaseCard>
    </div>
  </div>
</template>
