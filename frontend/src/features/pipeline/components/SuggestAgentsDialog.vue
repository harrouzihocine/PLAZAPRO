<script setup>
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import { pipelineApi } from '@/features/pipeline/api'
import { AGENT_STATUS_DOTS, AGENT_STATUS_LABEL_KEYS } from '@/features/pipeline/dispatchStatus'
import { toastError } from '@/composables/useConfirm'
import { t } from '@/i18n'

// The assignment assist: ranked agents for one pending in-site plan —
// distance from their last fix, load today, familiarity with the target
// site(s). Suggest-only: picking one RECORDS a board move; the dispatcher
// still presses Save. Off-duty agents trail the list but stay pickable.

const props = defineProps({
  visible: { type: Boolean, default: false },
  actionId: { type: Number, default: null },
})
const emit = defineEmits(['update:visible', 'pick'])

const loading = ref(false)
const sites = ref([])
const candidates = ref([])

watch(
  () => [props.visible, props.actionId],
  async ([visible, actionId]) => {
    if (!visible || !actionId) return
    loading.value = true
    sites.value = []
    candidates.value = []
    try {
      const data = await pipelineApi.dispatchSuggest(actionId)
      sites.value = data.sites
      candidates.value = data.candidates
    } catch (e) {
      toastError(e.response?.data?.message ?? t('common.actionFailed'))
      emit('update:visible', false)
    } finally {
      loading.value = false
    }
  },
)

const statusDot = (s) => AGENT_STATUS_DOTS[s] ?? AGENT_STATUS_DOTS.off_duty
const statusLabel = (s) => t(AGENT_STATUS_LABEL_KEYS[s] ?? AGENT_STATUS_LABEL_KEYS.off_duty)
const hasPinnedSite = () => sites.value.some((s) => s.lat !== null && s.lng !== null)
</script>

<template>
  <Dialog
    :visible="visible"
    modal
    :header="$t('dispatch.suggestTitle')"
    class="w-[34rem] max-w-[95vw]"
    :dismissable-mask="true"
    @update:visible="emit('update:visible', $event)"
  >
    <p class="mb-3 text-xs text-mute">{{ $t('dispatch.suggestHint') }}</p>

    <p v-if="sites.length && !hasPinnedSite()" class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
      <i class="pi pi-exclamation-triangle me-1" aria-hidden="true" />
      {{ $t('dispatch.noPin') }}
    </p>

    <p v-if="loading" class="py-6 text-center text-sm text-mute">{{ $t('common.loading') }}</p>

    <ul v-else class="divide-y divide-line">
      <li
        v-for="(c, i) in candidates"
        :key="c.id"
        class="flex items-center gap-3 py-2.5"
      >
        <span class="num w-5 text-center text-xs text-mute">{{ i + 1 }}</span>
        <span class="relative inline-flex h-2.5 w-2.5 shrink-0">
          <span class="inline-flex h-2.5 w-2.5 rounded-full" :class="statusDot(c.status)" />
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-ink">{{ c.name }}</p>
          <p class="num flex flex-wrap gap-x-3 text-xs text-mute">
            <span>{{ statusLabel(c.status) }}</span>
            <span v-if="c.distance_km !== null">
              <i class="pi pi-map-marker text-[10px]" aria-hidden="true" />
              {{ $t('dispatch.distanceAway', { km: c.distance_km }) }}
              <template v-if="c.eta_minutes !== null"> · {{ $t('dispatch.etaMin', { n: c.eta_minutes }) }}</template>
            </span>
            <span v-else>{{ $t('dispatch.noPosition') }}</span>
            <span>{{ $t('dispatch.loadToday', { n: c.today_load }) }}</span>
            <span v-if="c.familiarity > 0" class="text-emerald-600 dark:text-emerald-400">
              {{ $t('dispatch.visitedBefore', { n: c.familiarity }) }}
            </span>
          </p>
        </div>
        <Button
          :label="$t('dispatch.suggestAssign')"
          size="small"
          :outlined="i !== 0"
          @click="emit('pick', c.id)"
        />
      </li>
      <li v-if="!candidates.length" class="py-6 text-center text-sm text-mute">
        {{ $t('dispatch.noAgents') }}
      </li>
    </ul>
  </Dialog>
</template>
