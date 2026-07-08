<script setup>
import { computed } from 'vue'
import Button from 'primevue/button'
import EmptyState from '@/components/ui/EmptyState.vue'

// Touch-first stand-in for DataTable inside the Android shell on phones:
// stacked tappable cards instead of columns. Speaks the same server-side
// paging contract as DataTable (`page` event with { page: 0-based, rows }), so
// a view keeps one store and swaps only the markup:
//
//   <NativeList v-if="nativePhone" :items="store.items" … @page="onPage">
//     <template #item="{ item }">…card…</template>
//   </NativeList>
//   <DataTable v-else …>
//
// Rows/paging props are optional — omit them for plain (non-paginated) lists.
const props = defineProps({
  items: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  // Server-side paging (all three required for the pager to render).
  rows: { type: Number, default: null },
  page: { type: Number, default: null }, // 1-based, as the stores keep it
  total: { type: Number, default: null },
  // Empty-state copy (or override the #empty slot entirely).
  emptyIcon: { type: String, default: 'pi pi-inbox' },
  emptyTitle: { type: String, default: null }, // null → localized "Nothing here"
  emptyBody: { type: String, default: '' },
  // Rows navigate somewhere — show the chevron affordance.
  clickable: { type: Boolean, default: false },
})
const emit = defineEmits(['page', 'item-click'])

const pages = computed(() =>
  props.rows && props.total !== null ? Math.max(1, Math.ceil(props.total / props.rows)) : 1,
)
const paged = computed(() => props.rows !== null && props.page !== null && props.total !== null)

function go(page) {
  // Same payload shape as DataTable's @page so views reuse their onPage handler.
  emit('page', { page: page - 1, rows: props.rows })
}
</script>

<template>
  <div>
    <p v-if="loading && !items.length" class="flex items-center justify-center gap-2 py-10 text-sm text-mute">
      <i class="pi pi-spinner pi-spin" aria-hidden="true" /> {{ $t('common.loading') }}
    </p>

    <template v-else-if="items.length">
      <ul class="divide-y divide-line" :class="loading && 'opacity-60'">
        <li
          v-for="item in items"
          :key="item.id"
          class="relative flex items-center gap-3 px-4 py-3.5 transition-colors"
          :class="clickable && 'cursor-pointer active:bg-highlight'"
          @click="clickable && emit('item-click', item)"
        >
          <div class="min-w-0 flex-1">
            <slot name="item" :item="item" />
          </div>
          <i
            v-if="clickable"
            class="pi pi-chevron-right shrink-0 text-xs text-mute"
            aria-hidden="true"
          />
        </li>
      </ul>

      <!-- Thumb-sized pager -->
      <div
        v-if="paged && pages > 1"
        class="flex items-center justify-between gap-2 border-t border-line px-4 py-2.5"
      >
        <Button
          icon="pi pi-chevron-left"
          text
          rounded
          severity="secondary"
          :disabled="page <= 1 || loading"
          :aria-label="$t('common.previousPage')"
          @click="go(page - 1)"
        />
        <span class="num text-sm text-mute">{{ page }} / {{ pages }}</span>
        <Button
          icon="pi pi-chevron-right"
          text
          rounded
          severity="secondary"
          :disabled="page >= pages || loading"
          :aria-label="$t('common.nextPage')"
          @click="go(page + 1)"
        />
      </div>
    </template>

    <slot v-else name="empty">
      <EmptyState :icon="emptyIcon" :title="emptyTitle ?? $t('common.nothingHere')" :body="emptyBody" />
    </slot>
  </div>
</template>
