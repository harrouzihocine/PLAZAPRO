<script setup>
import { computed, onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
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
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold">Lists</h1>
      <p class="opacity-70">Manage the dropdown options used across the app.</p>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-[16rem_1fr]">
      <!-- List picker -->
      <BaseCard>
        <h2 class="mb-2 font-medium">Lists</h2>
        <nav class="flex flex-col gap-1">
          <button
            v-for="list in store.lists"
            :key="list.key"
            class="flex items-center justify-between rounded-token px-3 py-2 text-left hover:bg-bg"
            :class="{ 'bg-bg text-primary': list.key === store.selectedKey }"
            @click="store.select(list.key)"
          >
            <span>{{ list.name }}</span>
            <span v-if="list.is_system" class="text-xs opacity-50" title="System list">🔒</span>
          </button>
        </nav>
      </BaseCard>

      <!-- Item manager -->
      <BaseCard v-if="selected">
        <header class="mb-3">
          <h2 class="font-medium">{{ selected.name }}</h2>
          <p class="text-sm opacity-60">
            key: <code>{{ selected.key }}</code>
            <span v-if="selected.description"> — {{ selected.description }}</span>
          </p>
        </header>


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
          <p v-if="!store.items.length" class="py-4 text-center text-sm opacity-60">
            No items yet.
          </p>
        </div>

        <!-- Add item -->
        <form
          class="mt-4 flex flex-col gap-2 border-t border-border pt-4 sm:flex-row"
          @submit.prevent="addItem"
        >
          <BaseInput v-model="newLabel" label="Label" class="flex-1" @blur="suggestValue" />
          <BaseInput v-model="newValue" label="Value" class="flex-1" />
          <div class="flex items-end">
            <BaseButton type="submit" :disabled="store.saving">Add item</BaseButton>
          </div>
        </form>
      </BaseCard>
    </div>
  </div>
</template>
