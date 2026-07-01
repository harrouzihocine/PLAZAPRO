<script setup>
import { RouterLink } from 'vue-router'
import BaseCard from '@/components/base/BaseCard.vue'
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">Payments</h1>
    <BaseCard>
      <p class="text-sm opacity-80">
        Instalment plans, versements (payments) and branded receipts are managed on
        each deal, inside the client file.
      </p>
      <p class="mt-3 text-sm">
        <RouterLink :to="{ name: 'clients' }" class="text-primary hover:underline">
          Go to clients →
        </RouterLink>
      </p>
      <ul class="mt-4 list-disc space-y-1 pl-5 text-sm opacity-70">
        <li>Set an instalment plan that reconciles to the deal's agreed price.</li>
        <li>Record payments against instalments; balances are computed server-side.</li>
        <li>Corrections keep full history (cancel-and-duplicate) — records are never edited.</li>
        <li>Generate a branded receipt PDF for any payment.</li>
      </ul>
    </BaseCard>
  </div>
</template>
