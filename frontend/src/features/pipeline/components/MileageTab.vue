<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import SectionCard from '@/components/ui/SectionCard.vue'
import { pipelineApi } from '@/features/pipeline/api'
import { toastError } from '@/composables/useConfirm'
import { intlLocale, todayInput } from '@/utils/format'
import { t } from '@/i18n'

// Km driven per agent per day — fuel/allowance visibility over the duty
// paths. History comes from the nightly aggregates; today is computed live
// from the breadcrumbs, so the number moves during the day.

function daysAgoInput(n) {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const from = ref(daysAgoInput(6))
const until = ref(todayInput())
const loading = ref(false)
const agents = ref([])
const open = ref(new Set()) // agent ids with the day breakdown expanded

async function load() {
  if (!from.value || !until.value || from.value > until.value) return
  loading.value = true
  try {
    const data = await pipelineApi.dispatchMileage(from.value, until.value)
    agents.value = data.agents
  } catch (e) {
    toastError(e.response?.data?.message ?? t('common.actionFailed'))
  } finally {
    loading.value = false
  }
}

function toggle(id) {
  const next = new Set(open.value)
  next.has(id) ? next.delete(id) : next.add(id)
  open.value = next
}

const dutyHours = (minutes) => (minutes / 60).toFixed(1)
const dayLabel = (day) =>
  new Date(`${day}T12:00:00`).toLocaleDateString(intlLocale(), { weekday: 'short', day: 'numeric', month: 'short' })

onMounted(load)
</script>

<template>
  <SectionCard :title="$t('dispatch.mileageTitle')" icon="pi pi-gauge">
    <p class="mb-3 text-xs text-mute">{{ $t('dispatch.mileageHint') }}</p>

    <div class="mb-4 flex flex-wrap items-center gap-2">
      <input
        v-model="from"
        type="date"
        :max="until"
        class="rounded-md border border-line bg-card px-2 py-1.5 text-sm text-ink outline-none focus:border-primary"
        :aria-label="$t('dispatch.mileageFrom')"
      />
      <span class="text-xs text-mute">→</span>
      <input
        v-model="until"
        type="date"
        :min="from"
        :max="todayInput()"
        class="rounded-md border border-line bg-card px-2 py-1.5 text-sm text-ink outline-none focus:border-primary"
        :aria-label="$t('dispatch.mileageUntil')"
      />
      <Button :label="$t('common.apply')" icon="pi pi-refresh" size="small" :loading="loading" @click="load" />
    </div>

    <p v-if="loading" class="py-6 text-center text-sm text-mute">{{ $t('common.loading') }}</p>
    <p v-else-if="!agents.length" class="py-6 text-center text-sm text-mute">{{ $t('dispatch.mileageEmpty') }}</p>

    <div v-else class="overflow-x-auto">
      <table class="w-full min-w-[480px] border-collapse text-sm">
        <thead>
          <tr class="border-b border-line text-start text-xs uppercase tracking-wide text-mute">
            <th class="py-2 pe-3 text-start">{{ $t('clients.agent') }}</th>
            <th class="num py-2 pe-3 text-end">{{ $t('dispatch.mileageKm') }}</th>
            <th class="num py-2 pe-3 text-end">{{ $t('dispatch.mileageDutyHours') }}</th>
            <th class="w-8" />
          </tr>
        </thead>
        <tbody>
          <template v-for="a in agents" :key="a.id">
            <tr class="cursor-pointer border-b border-line hover:bg-line/30" @click="toggle(a.id)">
              <td class="py-2.5 pe-3 font-medium text-ink">{{ a.name }}</td>
              <td class="num py-2.5 pe-3 text-end text-ink">{{ a.total_km }}</td>
              <td class="num py-2.5 pe-3 text-end text-mute">{{ dutyHours(a.total_duty_minutes) }}</td>
              <td class="py-2.5 text-center text-mute">
                <i :class="open.has(a.id) ? 'pi pi-chevron-up' : 'pi pi-chevron-down'" class="text-xs" aria-hidden="true" />
              </td>
            </tr>
            <tr v-if="open.has(a.id)">
              <td colspan="4" class="border-b border-line bg-line/10 px-3 py-2">
                <p v-if="!a.days.length" class="text-xs text-mute">{{ $t('dispatch.mileageNoDays') }}</p>
                <div v-else class="grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
                  <div v-for="d in a.days" :key="d.day" class="flex items-baseline justify-between gap-3 text-xs">
                    <span class="text-mute">{{ dayLabel(d.day) }}</span>
                    <span class="num text-ink">
                      {{ $t('dispatch.mileageKmValue', { km: d.km }) }}
                      <span class="text-mute"> · {{ $t('dispatch.mileageHoursValue', { h: dutyHours(d.duty_minutes) }) }}</span>
                    </span>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </SectionCard>
</template>
