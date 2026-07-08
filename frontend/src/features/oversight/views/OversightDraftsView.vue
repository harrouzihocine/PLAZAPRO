<script setup>
import { onMounted, reactive, ref } from 'vue'
import Button from 'primevue/button'
import PageHeader from '@/components/ui/PageHeader.vue'
import OversightList from '@/features/oversight/components/OversightList.vue'
import OversightFilters from '@/features/oversight/components/OversightFilters.vue'
import { oversightApi } from '@/features/oversight/api'
import { defaultOversightRange } from '@/features/oversight/dateRange'
import { confirmAction, toastError, toastSuccess } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { t } from '@/i18n'

const data = ref({})
const loading = ref(true)
const busy = ref(null)
const filters = reactive({ ...defaultOversightRange(), user_id: '' })

async function load() {
  loading.value = true
  try {
    data.value = await oversightApi.drafts({ ...filters })
  } catch (e) {
    toastError(e.response?.data?.message ?? t('oversight.draftsLoadFailed'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

async function remove(item) {
  if (
    !(await confirmAction({
      title: t('oversight.clearDraftTitle'),
      text: `${item.user}’s unsaved draft will be removed on their next visit.`,
      confirmText: t('oversight.clearDraft'),
      danger: true,
    }))
  ) {
    return
  }
  busy.value = item.id
  try {
    await oversightApi.removeDraft(item.id)
    toastSuccess(t('oversight.draftCleared'))
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('oversight.clearDraftFailed'))
  } finally {
    busy.value = null
  }
}

// A draft row opens the page it lived on (so a supervisor can see the context).
const draftLink = (item) => item.path || null

const cols = [
  { key: 'user', label: 'User' },
  { key: 'label', label: 'Draft' },
  { key: 'updated_at', label: 'Since', type: 'date' },
]
</script>

<template>
  <div>
    <PageHeader
:title="$t('oversight.draftsTitle')"
      :subtitle="$t('oversight.draftsSubtitle')"
    />
    <OversightFilters
      v-model:from="filters.from"
      v-model:to="filters.to"
      v-model:user-id="filters.user_id"
      @apply="load"
    />
    <p v-if="loading" class="py-6 text-center text-sm text-mute">Loading…</p>
    <OversightList
      v-else
:title="$t('oversight.outstandingDrafts')"
      icon="pi pi-pencil"
      :data="data"
      :columns="cols"
      :row-to="draftLink"
      :empty-text="$t('oversight.draftsEmpty')"
    >
      <template #action="{ item }">
        <Button
          icon="pi pi-trash"
          text
          rounded
          size="small"
          severity="danger"
:aria-label="$t('oversight.clearDraft')"
          :loading="busy === item.id"
          @click="remove(item)"
        />
      </template>
    </OversightList>
  </div>
</template>
