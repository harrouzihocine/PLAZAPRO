<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import DynamicListItemRow from '@/features/settings/components/DynamicListItemRow.vue'
import { useDynamicListsStore } from '@/features/settings/dynamicListsStore'

const store = useDynamicListsStore()

const newLabel = ref('')
const newValue = ref('')

const selected = computed(() => store.selected)

onMounted(() => store.fetchLists())

// Suggest a stable machine value from the label if the user hasn't typed one.
function suggestValue() {
  if (!newValue.value) {
    newValue.value = newLabel.value
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '_')
      .replace(/^_+|_+$/g, '')
  }
}

async function addItem() {
  if (!newLabel.value.trim() || !newValue.value.trim()) return
  try {
    await store.addItem({ label: newLabel.value.trim(), value: newValue.value.trim() })
    newLabel.value = ''
    newValue.value = ''
  } catch {
    /* error is surfaced via store.error */
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
    <PageHeader title="Lists" subtitle="Manage the dropdown options used across the app." />

    <div class="grid grid-cols-1 gap-5 md:grid-cols-[17rem_1fr]">
      <!-- List picker -->
      <SectionCard title="Lists" icon="pi pi-list" flush class="self-start">
        <nav class="flex flex-col gap-0.5 p-2">
          <button
            v-for="list in store.lists"
            :key="list.key"
            type="button"
            class="flex items-center justify-between rounded-lg px-3 py-2 text-left text-sm transition-colors"
            :class="
              list.key === store.selectedKey
                ? 'bg-highlight font-semibold text-ink'
                : 'text-mute hover:bg-surface-100 hover:text-ink dark:hover:bg-surface-800'
            "
            @click="store.select(list.key)"
          >
            <span class="truncate">{{ list.name }}</span>
            <i
              v-if="list.is_system"
              class="pi pi-lock shrink-0 text-xs text-mute"
              title="System list"
              aria-hidden="true"
            />
          </button>
        </nav>
      </SectionCard>

      <!-- Item manager -->
      <SectionCard v-if="selected">
        <template #header>
          <div>
            <h2 class="text-sm font-semibold text-ink">{{ selected.name }}</h2>
            <p class="mt-0.5 text-xs text-mute">
              key:
              <code class="rounded bg-surface-100 px-1 dark:bg-surface-800">{{
                selected.key
              }}</code>
              <span v-if="selected.description"> — {{ selected.description }}</span>
            </p>
          </div>
        </template>

        <div class="space-y-2">
          <DynamicListItemRow
            v-for="(item, index) in store.items"
            :key="item.id"
            :item="item"
            :is-first="index === 0"
            :is-last="index === store.items.length - 1"
            @save="(payload) => store.updateItem(item.id, payload)"
            @toggle="toggle(item)"
            @move="(dir) => move(index, dir)"
          />
          <p v-if="!store.items.length" class="py-4 text-center text-sm text-mute">No items yet.</p>
        </div>

        <!-- Add item -->
        <form
          class="mt-4 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row"
          @submit.prevent="addItem"
        >
          <BaseInput v-model="newLabel" label="Label" class="flex-1" @blur="suggestValue" />
          <BaseInput v-model="newValue" label="Value" class="flex-1" />
          <div class="flex items-end">
            <BaseButton type="submit" :disabled="store.saving">Add item</BaseButton>
          </div>
        </form>
      </SectionCard>
    </div>
  </div>
</template>
