<script setup>
import { RouterLink } from 'vue-router'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'

// Payments live on each deal (inside the project workspace); this page is the
// signpost that explains the flow and links there.
const STEPS = [
  {
    icon: 'pi pi-calendar-plus',
    title: 'Plan',
    body: "Set an instalment plan that reconciles to the deal's agreed price.",
  },
  {
    icon: 'pi pi-wallet',
    title: 'Record',
    body: 'Record payments against instalments; balances are computed server-side.',
  },
  {
    icon: 'pi pi-history',
    title: 'Correct',
    body: 'Corrections keep full history (cancel-and-duplicate) — records are never edited.',
  },
  {
    icon: 'pi pi-file-pdf',
    title: 'Receipt',
    body: 'Generate a branded receipt PDF for any payment.',
  },
]
</script>

<template>
  <div>
    <PageHeader
      title="Payments"
      subtitle="Instalment plans, versements and branded receipts are managed on each won deal, inside its project workspace."
    />

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <SectionCard v-for="s in STEPS" :key="s.title">
        <span
          class="flex h-10 w-10 items-center justify-center rounded-lg bg-highlight text-primary-700 dark:text-primary-300"
        >
          <i :class="s.icon" aria-hidden="true" />
        </span>
        <h2 class="mt-3 text-sm font-semibold text-ink">{{ s.title }}</h2>
        <p class="mt-1 text-sm text-mute">{{ s.body }}</p>
      </SectionCard>
    </div>

    <SectionCard class="mt-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-mute">
          Open a client, enter the won project, and the payments panel is right there.
        </p>
        <RouterLink
          :to="{ name: 'clients' }"
          class="inline-flex items-center gap-1.5 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
        >
          Go to clients
          <i class="pi pi-arrow-right text-xs" aria-hidden="true" />
        </RouterLink>
      </div>
    </SectionCard>
  </div>
</template>
