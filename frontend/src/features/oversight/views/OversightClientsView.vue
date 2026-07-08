<script setup>
import { onMounted, reactive, ref } from 'vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import OversightList from '@/features/oversight/components/OversightList.vue'
import OversightFilters from '@/features/oversight/components/OversightFilters.vue'
import { oversightApi } from '@/features/oversight/api'
import { defaultOversightRange } from '@/features/oversight/dateRange'
import { toastError } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { t } from '@/i18n'

const data = ref({ empty: {}, no_name: {} })
const loading = ref(true)
const filters = reactive({ ...defaultOversightRange(), user_id: '' })

async function load() {
  loading.value = true
  try {
    data.value = await oversightApi.clients({ ...filters })
  } catch (e) {
    toastError(e.response?.data?.message ?? t('oversight.clientsLoadFailed'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

const clientLink = (item) => ({ name: 'clients.file', params: { id: item.link.client_id } })

const cols = [
  { key: 'name', label: t('clients.client') },
  { key: 'phone', label: 'Phone', type: 'num' },
  { key: 'created_by', label: t('clients.createdBy') },
  { key: 'created_at', label: t('clients.created'), type: 'date' },
]
</script>

<template>
  <div>
    <PageHeader :title="$t('nav.clientQuality')" :subtitle="$t('oversight.clientsSubtitle')" />
    <OversightFilters
      v-model:from="filters.from"
      v-model:to="filters.to"
      v-model:user-id="filters.user_id"
      @apply="load"
    />
    <p v-if="loading" class="py-6 text-center text-sm text-mute">Loading…</p>
    <div v-else class="space-y-4">
      <OversightList
:title="$t('oversight.emptyClients')"
        icon="pi pi-user-minus"
        :data="data.empty"
        :columns="cols"
        :row-to="clientLink"
        empty-text="No empty clients — every captured lead has activity."
      />
      <OversightList
:title="$t('oversight.noNameClients')"
        icon="pi pi-id-card"
        :data="data.no_name"
        :columns="cols"
        :row-to="clientLink"
        empty-text="Every client was captured with a name."
      />
    </div>
  </div>
</template>
