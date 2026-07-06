<script setup>
// One anomaly monitor: a titled card with a per-user breakdown (chips) and a
// table of the offending records. Fed the standard { total, by_user, items }
// shape from BuildOversight. Column types drive date/money formatting; a named
// `action` slot renders a per-row control (e.g. "Remove draft").
import { useRouter } from 'vue-router'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { formatDateTime } from '@/utils/format'
import { formatMoney } from '@/features/payments/money'

const props = defineProps({
  title: { type: String, required: true },
  icon: { type: String, default: 'pi pi-flag' },
  data: { type: Object, default: () => ({ total: 0, by_user: [], items: [] }) },
  columns: { type: Array, default: () => [] }, // [{ key, label, type? }]
  emptyText: { type: String, default: 'Nothing to follow up here.' },
  // (item) => a router target (object/path) for the row, or null. When it
  // returns a target the row becomes clickable and opens where the problem is.
  rowTo: { type: Function, default: null },
})

const router = useRouter()

function openRow(item) {
  const to = props.rowTo?.(item)
  if (to) router.push(to)
}

function cell(item, col) {
  const v = item[col.key]
  if (v === null || v === undefined || v === '') return '—'
  if (col.type === 'date') return formatDateTime(v)
  if (col.type === 'money') return formatMoney(v)
  return v
}
function isNum(col) {
  return col.type === 'money' || col.type === 'num'
}
</script>

<template>
  <SectionCard :title="title" :icon="icon">
    <template #actions>
      <span class="num text-xs text-mute">{{ data.total ?? 0 }} total</span>
    </template>

    <div v-if="data.by_user?.length" class="mb-3 flex flex-wrap gap-1.5">
      <span
        v-for="u in data.by_user"
        :key="u.user_id ?? u.name"
        class="rounded-full bg-highlight px-2.5 py-1 text-xs text-ink"
      >
        {{ u.name }} · <span class="num font-semibold">{{ u.count }}</span>
      </span>
    </div>

    <div v-if="data.items?.length" class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-xs text-mute">
            <th v-for="c in columns" :key="c.key" class="py-1.5 pr-3 font-medium">{{ c.label }}</th>
            <th v-if="$slots.action" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="item in data.items"
            :key="item.id"
            class="border-t border-line"
            :class="rowTo && rowTo(item) ? 'cursor-pointer hover:bg-highlight' : ''"
            @click="openRow(item)"
          >
            <td
              v-for="c in columns"
              :key="c.key"
              class="py-2 pr-3 text-ink"
              :class="{ num: isNum(c) }"
            >
              {{ cell(item, c) }}
            </td>
            <td v-if="$slots.action" class="py-1 text-right" @click.stop>
              <slot name="action" :item="item" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <EmptyState v-else icon="pi pi-check-circle" title="All clear" :body="emptyText" />
  </SectionCard>
</template>
