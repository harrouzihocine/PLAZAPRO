<script setup>
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import { pipelineApi } from '@/features/pipeline/api'
import { AGENT_STATUS_DOTS } from '@/features/pipeline/dispatchStatus'
import { toastError } from '@/composables/useConfirm'
import { t } from '@/i18n'

// The day optimizer: the whole pending in-site pool split across the on-duty
// agents into balanced, geographically sensible routes (road minutes via the
// routing engine). A PROPOSAL only — applying records ordinary board moves,
// the dispatcher still presses Save. Nothing is ever auto-dispatched.

const props = defineProps({
  visible: { type: Boolean, default: false },
})
const emit = defineEmits(['update:visible', 'apply'])

const loading = ref(false)
const proposals = ref([])
const skipped = ref([])
const routed = ref(false)
const poolSize = ref(0)

watch(
  () => props.visible,
  async (visible) => {
    if (!visible) return
    loading.value = true
    proposals.value = []
    skipped.value = []
    try {
      const data = await pipelineApi.dispatchPlanPreview()
      proposals.value = data.proposals
      skipped.value = data.skipped
      routed.value = data.routed
      poolSize.value = data.pool_size
    } catch (e) {
      toastError(e.response?.data?.message ?? t('common.actionFailed'))
      emit('update:visible', false)
    } finally {
      loading.value = false
    }
  },
)

const statusDot = (s) => AGENT_STATUS_DOTS[s] ?? AGENT_STATUS_DOTS.off_duty
const plannedCount = () => proposals.value.reduce((n, p) => n + p.stops.length, 0)

function apply() {
  const stops = proposals.value.flatMap((p) =>
    p.stops.map((s) => ({ action_id: s.action_id, agent_id: p.agent.id })),
  )
  emit('apply', stops)
  emit('update:visible', false)
}
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :header="$t('dispatch.planDayTitle')"
    class="w-[44rem] max-w-[95vw]"
    :dismissable-mask="true"
    @update:visible="emit('update:visible', $event)"
  >
    <p class="mb-3 text-xs text-mute">
      {{ $t('dispatch.planDayHint') }}
      <template v-if="!loading && !routed && plannedCount()"> · {{ $t('dispatch.estimatedDistance') }}</template>
    </p>

    <p v-if="loading" class="py-6 text-center text-sm text-mute">{{ $t('common.loading') }}</p>

    <template v-else>
      <p v-if="!poolSize" class="py-6 text-center text-sm text-mute">
        <i class="pi pi-check-circle me-1 text-success" aria-hidden="true" />
        {{ $t('dispatch.noPending') }}
      </p>
      <p v-else-if="!proposals.length" class="py-6 text-center text-sm text-mute">
        {{ $t('dispatch.planDayNoAgents') }}
      </p>

      <div v-else class="space-y-4">
        <div v-for="p in proposals" :key="p.agent.id" class="rounded-xl border border-line p-3">
          <div class="mb-2 flex flex-wrap items-center gap-2">
            <span class="inline-block h-2.5 w-2.5 rounded-full" :class="statusDot(p.agent.status)" />
            <span class="font-medium text-ink">{{ p.agent.name }}</span>
            <Tag v-if="p.existing_load" severity="secondary" :value="$t('dispatch.planDayExisting', { n: p.existing_load })" />
            <span v-if="p.stops.length" class="num ms-auto text-xs text-mute">
              {{ $t('dispatch.planDayTotal', { min: p.total_minutes, km: p.total_km }) }}
            </span>
          </div>
          <p v-if="!p.stops.length" class="text-xs text-mute">{{ $t('dispatch.planDayNoStops') }}</p>
          <ol v-else class="space-y-1.5">
            <li v-for="(s, i) in p.stops" :key="s.action_id" class="flex items-center gap-2 text-sm">
              <span class="num flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary-100 text-[11px] font-semibold text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">{{ i + 1 }}</span>
              <span class="min-w-0 flex-1 truncate text-ink">
                {{ s.client ?? '—' }} <span class="text-mute">· {{ s.site.name }}</span>
              </span>
              <span class="num shrink-0 text-xs text-mute">
                {{ $t('dispatch.etaMin', { n: s.minutes_from_prev }) }}<template v-if="s.km_from_prev !== null"> · {{ $t('dispatch.distanceAway', { km: s.km_from_prev }) }}</template>
              </span>
            </li>
          </ol>
        </div>

        <!-- Plans the optimizer could not place: sites with no map pin. -->
        <p v-if="skipped.length" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
          <i class="pi pi-exclamation-triangle me-1" aria-hidden="true" />
          {{ $t('dispatch.planDaySkipped', { names: skipped.map((s) => s.client ?? '—').join(', ') }) }}
        </p>
      </div>
    </template>

    <template #footer>
      <Button :label="$t('common.cancel')" severity="secondary" outlined @click="emit('update:visible', false)" />
      <Button
        :label="$t('dispatch.planDayApply', { n: plannedCount() })"
        icon="pi pi-check"
        :disabled="loading || !plannedCount()"
        @click="apply"
      />
    </template>
  </Dialog>
</template>
