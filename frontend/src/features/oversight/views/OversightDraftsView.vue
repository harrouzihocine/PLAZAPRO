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

const data = ref({})
const loading = ref(true)
const busy = ref(null)
const filters = reactive({ ...defaultOversightRange(), user_id: '' })

async function load() {
  loading.value = true
  try {
    data.value = await oversightApi.drafts({ ...filters })
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not load draft oversight.')
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

async function remove(item) {
  if (
    !(await confirmAction({
      title: 'Clear this draft?',
      text: `${item.user}’s unsaved draft will be removed on their next visit.`,
      confirmText: 'Clear draft',
      danger: true,
    }))
  ) {
    return
  }
  busy.value = item.id
  try {
    await oversightApi.removeDraft(item.id)
    toastSuccess('Draft cleared.')
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Could not clear the draft.')
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
      title="Abandoned drafts"
      subtitle="Unsaved forms left open, by user — clear the stuck ones."
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
      title="Outstanding drafts"
      icon="pi pi-pencil"
      :data="data"
      :columns="cols"
      :row-to="draftLink"
      empty-text="No user is sitting on an unsaved draft."
    >
      <template #action="{ item }">
        <Button
          icon="pi pi-trash"
          text
          rounded
          size="small"
          severity="danger"
          aria-label="Clear draft"
          :loading="busy === item.id"
          @click="remove(item)"
        />
      </template>
    </OversightList>
  </div>
</template>
