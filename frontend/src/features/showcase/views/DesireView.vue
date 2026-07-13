<script setup>
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useShowcaseStore } from '../store'
import { useSeoMeta } from '../composables/useSeoMeta'
import DesireForm from '../components/DesireForm.vue'

// "Find me a home" — the desire page. For the visitor who browsed the
// portfolio and didn't find their apartment: describe it, leave a number,
// and the team calls back the moment something fits.

const showcase = useShowcaseStore()
const { t } = useI18n()

onMounted(() => showcase.loadConfig())

useSeoMeta(() => ({
  title: `${t('showcase.desire.title')} — ${showcase.company.name || 'PLAZA PRO'}`,
  description: t('showcase.desire.subtitle'),
}))
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 pb-20 pt-28 sm:px-6">
    <div class="grid gap-10 lg:grid-cols-5">
      <!-- Pitch + how it works -->
      <div class="lg:col-span-2">
        <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
          {{ $t('showcase.desire.kicker') }}
        </p>
        <h1 class="mt-2 text-3xl font-bold text-ink sm:text-4xl">{{ $t('showcase.desire.title') }}</h1>
        <p class="mt-4 text-mute">{{ $t('showcase.desire.subtitle') }}</p>

        <ol class="mt-8 space-y-5">
          <li v-for="n in 3" :key="n" class="flex items-start gap-3">
            <span class="num mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-500/15 text-sm font-bold text-primary-500">
              {{ n }}
            </span>
            <div>
              <p class="font-semibold text-ink">{{ $t(`showcase.desire.how${n}Title`) }}</p>
              <p class="text-sm text-mute">{{ $t(`showcase.desire.how${n}Body`) }}</p>
            </div>
          </li>
        </ol>
      </div>

      <!-- The wizard -->
      <div class="lg:col-span-3">
        <div class="rounded-2xl border border-line bg-card p-6 shadow-card sm:p-8">
          <DesireForm />
        </div>
      </div>
    </div>
  </div>
</template>
