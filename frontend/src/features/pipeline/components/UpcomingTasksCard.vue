<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import SectionCard from '@/components/ui/SectionCard.vue'
import { pipelineApi } from '@/features/pipeline/api'
import { formatDate } from '@/utils/format'
import { t } from '@/i18n'

// The Android app's "what do I do next" fast lane: the user's own next tasks
// (calls, visits, to-dos) over the coming days, pinned at the top of the
// dashboard. Each row deep-links to where the work is actually done — the
// client project workspace or the tasks board. Renders nothing while empty.
const MAX_ITEMS = 5
const DAYS = 5

const days = ref([])
const loaded = ref(false)

onMounted(async () => {
  try {
    days.value = await pipelineApi.myAgenda({ days: DAYS })
  } catch {
    days.value = [] // agenda is best-effort — never break the dashboard
  } finally {
    loaded.value = true
  }
})

const ICONS = {
  call: 'pi pi-phone',
  office_visit: 'pi pi-building',
  in_site_visit: 'pi pi-map-marker',
  task: 'pi pi-check-square',
}
const kindLabel = (kind) =>
  ({
    call: t('pipeline.typeCall'),
    office_visit: t('status.office_visit'),
    in_site_visit: t('status.in_site_visit'),
    task: t('pipeline.typeTask'),
  })[kind]

function dayTag(date) {
  const today = new Date()
  const tomorrow = new Date(today)
  tomorrow.setDate(today.getDate() + 1)
  const d = new Date(`${date}T00:00`)
  if (d.toDateString() === today.toDateString()) return { key: 'today', label: t('common.today') }
  if (d.toDateString() === tomorrow.toDateString()) return { key: 'tomorrow', label: t('common.tomorrow') }
  return { key: date, label: formatDate(d) }
}

const items = computed(() =>
  days.value
    .flatMap((d) =>
      (d.items ?? []).map((it) => ({ ...it, date: d.date, dayTag: dayTag(d.date).label, isToday: dayTag(d.date).key === 'today' })),
    )
    .slice(0, MAX_ITEMS),
)
const total = computed(() => days.value.reduce((n, d) => n + (d.items?.length ?? 0), 0))
</script>

<template>
  <SectionCard v-if="loaded && items.length" :title="$t('pipeline.myNextTasks')" icon="pi pi-flag" flush>
    <template #actions>
      <RouterLink to="/tasks" class="text-xs font-medium text-primary-600 dark:text-primary-400">
        {{ $t('pipeline.allTasks') }}
        <i class="pi pi-arrow-right text-[10px]" aria-hidden="true" />
      </RouterLink>
    </template>

    <ul class="divide-y divide-line">
      <li v-for="(it, i) in items" :key="i">
        <component
          :is="it.link ? RouterLink : 'div'"
          :to="it.link || undefined"
          class="flex items-center gap-3 px-4 py-3"
          :class="it.link && 'active:bg-highlight'"
        >
          <span
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
            :class="
              it.isToday
                ? 'bg-highlight text-primary-600 dark:text-primary-400'
                : 'bg-surface-100 text-mute dark:bg-surface-800'
            "
          >
            <i :class="ICONS[it.kind] ?? 'pi pi-flag'" aria-hidden="true" />
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium text-ink">
              {{ it.client || it.label || kindLabel(it.kind) || $t('pipeline.typeTask') }}
            </span>
            <span class="block text-xs text-mute">
              {{ kindLabel(it.kind) ?? it.kind }}
              <span :class="it.isToday && 'font-semibold text-primary-600 dark:text-primary-400'">
                · {{ it.dayTag }}</span
              >
              <template v-if="it.time"> · {{ it.time }}</template>
            </span>
          </span>
          <i v-if="it.link" class="pi pi-chevron-right shrink-0 text-xs text-mute" aria-hidden="true" />
        </component>
      </li>
    </ul>
    <p v-if="total > items.length" class="border-t border-line px-4 py-2 text-xs text-mute">
      {{ $t('pipeline.moreComingDays', { n: total - items.length }) }}
    </p>
  </SectionCard>
</template>
