<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DynamicListItemRow from '@/features/settings/components/DynamicListItemRow.vue'
import ListItemFormModal from '@/features/settings/components/ListItemFormModal.vue'
import { useDynamicListsStore } from '@/features/settings/dynamicListsStore'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { i18n, t } from '@/i18n'

const store = useDynamicListsStore()

const selected = computed(() => store.selected)

// System list names translate via the dictionary (settings.listNames.<key>);
// custom lists an admin created keep their stored name.
function listName(list) {
  const key = `settings.listNames.${list.key}`
  return i18n.global.te(key) ? t(key) : list.name
}

// Modal state: closed, or open on a specific item (null item = creating).
const modalOpen = ref(false)
const modalItem = ref(null)

onMounted(() => store.fetchLists())
useRefreshable(() => store.fetchLists()) // pull-to-refresh + reconnect self-heal

function openCreate() {
  modalItem.value = null
  modalOpen.value = true
}

function openEdit(item) {
  modalItem.value = item
  modalOpen.value = true
}

async function onSave(payload) {
  try {
    if (modalItem.value) await store.updateItem(modalItem.value.id, payload)
    else await store.addItem(payload) // value omitted → generated server-side
    modalOpen.value = false
  } catch {
    /* error surfaced via store.error toast */
  }
}

function toggle(item) {
  return item.is_active
    ? store.deactivateItem(item.id)
    : store.updateItem(item.id, { is_active: true })
}

function move(index, dir) {
  const items = [...store.items]
  const target = index + dir
  if (target < 0 || target >= items.length) return
  ;[items[index], items[target]] = [items[target], items[index]]
  store.reorder(items.map((i) => i.id))
}
</script>

<template>
  <div>
    <PageHeader :title="$t('settings.lists')" :subtitle="$t('settings.listsSubtitle')" />

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
      <!-- List picker -->
      <SectionCard :title="$t('settings.lists')" icon="pi pi-list" flush class="self-start">
        <nav class="flex flex-col gap-0.5 p-2">
          <button
            v-for="list in store.lists"
            :key="list.key"
            type="button"
            class="flex items-center justify-between rounded-lg px-3 py-2 text-start text-sm transition-colors"
            :class="
              list.key === store.selectedKey
                ? 'bg-highlight font-semibold text-ink'
                : 'text-mute hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800'
            "
            @click="store.select(list.key)"
          >
            <span class="truncate">{{ listName(list) }}</span>
            <i
              v-if="list.is_system"
              class="pi pi-lock shrink-0 text-xs text-mute"
:title="$t('settings.systemList')"
              aria-hidden="true"
            />
          </button>
        </nav>
      </SectionCard>

      <!-- Item manager -->
      <SectionCard v-if="selected">
        <template #header>
          <div class="min-w-0">
            <h2 class="text-sm font-semibold text-ink">{{ listName(selected) }}</h2>
            <p class="mt-0.5 truncate text-xs text-mute">
              <code class="rounded bg-surface-100 px-1 dark:bg-surface-800">{{ selected.key }}</code>
              <span v-if="selected.description"> — {{ selected.description }}</span>
            </p>
          </div>
        </template>
        <template #actions>
          <Button :label="$t('settings.addItem')" icon="pi pi-plus" size="small" @click="openCreate" />
        </template>

        <div class="space-y-2">
          <DynamicListItemRow
            v-for="(item, index) in store.items"
            :key="item.id"
            :item="item"
            :is-first="index === 0"
            :is-last="index === store.items.length - 1"
            @edit="openEdit(item)"
            @toggle="toggle(item)"
            @move="(dir) => move(index, dir)"
          />
          <EmptyState
            v-if="!store.items.length"
            icon="pi pi-list"
:title="$t('settings.noItemsTitle')"
            :body="$t('settings.noItemsBody')"
          />
        </div>
      </SectionCard>
    </div>

    <ListItemFormModal
      v-if="modalOpen"
      :item="modalItem"
      :saving="store.saving"
      @save="onSave"
      @close="modalOpen = false"
    />
  </div>
</template>
