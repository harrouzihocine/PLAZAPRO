<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { useRouter } from 'vue-router'
import Avatar from 'primevue/avatar'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import BaseSelect from '@/components/base/BaseSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import NativeList from '@/components/ui/NativeList.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import OfflineStamp from '@/components/ui/OfflineStamp.vue'
import { useNativePhone } from '@/composables/useNativeMode'
import ClientFormDrawer from '@/features/clients/components/ClientFormDrawer.vue'
import { formatPhone } from '@/data/countryCodes'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useDynamicList } from '@/composables/useDynamicList'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction } from '@/composables/useConfirm'
import { formatDateTime, initials, countActiveFilters } from '@/utils/format'

const store = useClientsStore()
const auth = useAuthStore()
const router = useRouter()
const { items: sources } = useDynamicList('sources')
const { items: ratings } = useDynamicList('client_ratings')

const canCreate = computed(() => auth.can('clients.create'))
const canManage = computed(() => auth.can('clients.manage'))
// Without clients.view_details a user sees only who the client IS (the name).
const canSeeDetails = computed(() => auth.can('clients.view_details'))
// Client ownership (assigned agent + who created it/when) is back-office-only,
// gated by clients.manage (super-admin / admin / manager).
const canSeeOwnership = computed(() => auth.can('clients.manage'))

const drawerOpen = ref(false)
const editing = ref(null) // null = creating

// Android-shell phones swap the table for tappable cards (NativeList below);
// native tablets and the web keep the full table.
const nativePhone = useNativePhone()
const activeFilterCount = computed(() => countActiveFilters(store.filters))

onMounted(() => store.fetch())
useRefreshable(() => store.fetch()) // pull-to-refresh (APK)

// Filters apply themselves as they change — no "Filter" button. A filter change
// resets to page 1 (results shrink/shift, so the old page number is meaningless).
useAutoFilter(
  () => store.filters,
  () => store.applyFilters(),
)

// Server-side paginator: load the requested page from the API.
function onPage(e) {
  store.goToPage({ page: e.page + 1, rows: e.rows })
}

function openCreate() {
  editing.value = null
  drawerOpen.value = true
}

function openEdit(client) {
  editing.value = client
  drawerOpen.value = true
}

function onSaved(client) {
  // A newly-created client goes straight to their file (edit stays in place).
  if (!editing.value && client?.id) {
    router.push({ name: 'clients.file', params: { id: client.id } })
  }
}

async function removeClient(client) {
  if (
    await confirmAction({
      title: `Cancel client "${client.full_name}"?`,
      text: 'The record and its history are kept.',
      confirmText: 'Cancel client',
      danger: true,
    })
  ) {
    store.cancel(client.id)
  }
}

const hasFilters = computed(() => Object.values(store.filters).some((v) => v !== '' && v !== null))

function resetFilters() {
  // The auto-filter watcher picks the change up and refetches.
  store.filters = { assigned_agent_id: '', source_id: '', rating_id: '', search: '' }
}

function openFile(event) {
  router.push({ name: 'clients.file', params: { id: event.data.id } })
}

// Same deep link as the client file header (phones are stored E.164).
const whatsappLink = (phone) => `https://wa.me/${(phone ?? '').replace(/\D/g, '')}`
</script>

<template>
  <div>
    <OfflineStamp :at="store.offlineAt" />
    <PageHeader title="Clients" subtitle="Leads and buyers — searchable by name or phone.">
      <template #actions>
        <Button
          v-if="canCreate"
          label="New client"
          icon="pi pi-plus"
          data-testid="new-client"
          @click="openCreate"
        />
      </template>
    </PageHeader>

    <SectionCard flush>
      <!-- Filter toolbar -->
      <FilterPanel :active-count="activeFilterCount">
      <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3 sm:px-5">
        <div class="relative w-full sm:w-72">
          <i
            class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm text-mute"
            aria-hidden="true"
          />
          <InputText
            v-model="store.filters.search"
            placeholder="Search name or phone…"
            class="w-full !pl-9"
          />
        </div>
        <BaseSelect
          v-if="canSeeOwnership"
          v-model="store.filters.assigned_agent_id"
          placeholder="All agents"
          aria-label="Filter by agent"
          class="w-full sm:w-44"
          :options="store.followUpAgents.map((a) => ({ value: a.id, label: a.name }))"
        />
        <BaseSelect
          v-if="canSeeDetails"
          v-model="store.filters.source_id"
          placeholder="All sources"
          aria-label="Filter by source"
          class="w-full sm:w-44"
          :options="sources.map((s) => ({ value: s.id, label: s.label, icon: s.meta?.icon }))"
        />
        <BaseSelect
          v-if="canSeeDetails"
          v-model="store.filters.rating_id"
          placeholder="All ratings"
          aria-label="Filter by rating"
          class="w-full sm:w-40"
          :options="ratings.map((r) => ({ value: r.id, label: r.label }))"
        />
        <Button
          v-if="hasFilters"
          icon="pi pi-filter-slash"
          text
          severity="secondary"
          aria-label="Reset filters"
          @click="resetFilters"
        />
      </div>
      </FilterPanel>

      <!-- APK phones: card list, one client per card, tap to open the file. -->
      <NativeList
        v-if="nativePhone"
        :items="store.items"
        :loading="store.loading"
        :rows="store.rows"
        :page="store.page"
        :total="store.total"
        clickable
        empty-icon="pi pi-users"
        empty-title="No clients match"
        empty-body="Adjust the filters or add a new client."
        @page="onPage"
        @item-click="(c) => openFile({ data: c })"
      >
        <template #item="{ item }">
          <div class="flex items-center gap-3">
            <Avatar
              :label="initials(item.full_name)"
              shape="circle"
              size="large"
              class="shrink-0 !bg-highlight !text-primary-700 dark:!text-primary-300"
            />
            <div class="min-w-0 flex-1">
              <p class="truncate font-medium text-ink">{{ item.full_name }}</p>
              <p v-if="canSeeDetails && item.phone" class="num mt-0.5 text-sm text-mute">
                {{ formatPhone(item.phone) }}
              </p>
              <p
                v-if="canSeeDetails && (item.source || item.rating)"
                class="mt-1.5 flex flex-wrap items-center gap-1.5"
              >
                <Tag
                  v-if="item.source"
                  :icon="item.source.icon || undefined"
                  :value="item.source.label"
                  severity="secondary"
                />
                <Tag v-if="item.rating" :value="item.rating.label" severity="secondary" />
              </p>
              <p v-if="canSeeOwnership" class="mt-1 truncate text-xs text-mute">
                <i class="pi pi-user text-[10px]" aria-hidden="true" />
                {{ item.assigned_agent?.name ?? 'Unassigned' }}
              </p>
            </div>
            <!-- One-tap call / WhatsApp — the reason this list exists on a phone. -->
            <span v-if="canSeeDetails && item.phone" class="flex shrink-0 items-center gap-2">
              <a
                :href="`tel:${item.phone}`"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-highlight text-primary-600 active:opacity-70 dark:text-primary-400"
                :aria-label="`Call ${item.full_name}`"
                @click.stop
              >
                <i class="pi pi-phone" aria-hidden="true" />
              </a>
              <a
                :href="whatsappLink(item.phone)"
                target="_blank"
                rel="noopener"
                class="flex h-11 w-11 items-center justify-center rounded-full bg-green-500/10 text-green-600 active:opacity-70 dark:text-green-400"
                :aria-label="`WhatsApp ${item.full_name}`"
                @click.stop
              >
                <i class="pi pi-whatsapp" aria-hidden="true" />
              </a>
            </span>
          </div>
        </template>
      </NativeList>

      <DataTable
        v-else
        :value="store.items"
        :loading="store.loading"
        lazy
        paginator
        :rows="store.rows"
        :first="(store.page - 1) * store.rows"
        :total-records="store.total"
        :rows-per-page-options="[25, 50, 100]"
        data-key="id"
        class="cursor-pointer"
        @page="onPage"
        @row-click="openFile"
      >
        <template #empty>
          <EmptyState
            icon="pi pi-users"
            title="No clients match"
            body="Adjust the filters or add a new client."
          />
        </template>

        <Column header="Client">
          <template #body="{ data }">
            <span class="flex items-center gap-3">
              <Avatar
                :label="initials(data.full_name)"
                shape="circle"
                class="shrink-0 !bg-highlight !text-primary-700 dark:!text-primary-300"
              />
              <span class="min-w-0">
                <span class="block truncate font-medium text-ink">{{ data.full_name }}</span>
                <span v-if="data.email" class="block truncate text-xs text-mute">
                  {{ data.email }}
                </span>
              </span>
            </span>
          </template>
        </Column>

        <Column v-if="canSeeDetails" header="Phone">
          <template #body="{ data }">
            <span class="num whitespace-nowrap">{{ formatPhone(data.phone) }}</span>
          </template>
        </Column>

        <Column v-if="canSeeDetails" header="Source">
          <template #body="{ data }">
            <Tag
              v-if="data.source"
              :icon="data.source.icon || undefined"
              :value="data.source.label"
              severity="secondary"
            />
            <span v-else class="text-mute">—</span>
          </template>
        </Column>

        <Column v-if="canSeeDetails" header="Rating">
          <template #body="{ data }">
            <span v-if="data.rating" class="text-ink">{{ data.rating.label }}</span>
            <span v-else class="text-mute">—</span>
          </template>
        </Column>

        <Column v-if="canSeeOwnership" header="Agent">
          <template #body="{ data }">
            {{ data.assigned_agent?.name ?? '—' }}
          </template>
        </Column>

        <Column v-if="canSeeOwnership" header="Created">
          <template #body="{ data }">
            <span class="block text-sm">{{ data.created_by?.name ?? '—' }}</span>
            <span class="block text-xs text-mute">{{ formatDateTime(data.created_at) }}</span>
          </template>
        </Column>

        <Column v-if="canManage" header="" class="w-24">
          <template #body="{ data }">
            <span class="flex justify-end gap-1">
              <Button
                icon="pi pi-pencil"
                text
                rounded
                severity="secondary"
                size="small"
                aria-label="Edit client"
                @click.stop="openEdit(data)"
              />
              <Button
                icon="pi pi-ban"
                text
                rounded
                severity="danger"
                size="small"
                aria-label="Cancel client"
                @click.stop="removeClient(data)"
              />
            </span>
          </template>
        </Column>
      </DataTable>
    </SectionCard>

    <ClientFormDrawer v-model:visible="drawerOpen" :client="editing" @saved="onSaved" />
  </div>
</template>
