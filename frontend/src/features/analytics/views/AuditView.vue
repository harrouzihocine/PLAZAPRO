<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import BaseInput from '@/components/base/BaseInput.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import FilterPanel from '@/components/ui/FilterPanel.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAutoFilter } from '@/composables/useAutoFilter'
import { useAuditStore } from '@/features/analytics/auditStore'
import { useAuthStore } from '@/features/settings/store'
import { formatDateTime, countActiveFilters } from '@/utils/format'

const store = useAuditStore()
const activeFilterCount = computed(() => countActiveFilters(store.filters))
const auth = useAuthStore()
const expanded = ref(null)

function toggle(id) {
  expanded.value = expanded.value === id ? null : id
}

// Short class name for the (fully-qualified) subject type, e.g. "Client".
function shortSubject(type) {
  return type ? type.split('\\').pop() : '—'
}

// Flatten a changes object ({ before, after }) into rows for the diff view.
function diffRows(changes) {
  if (!changes) return []
  const before = changes.before ?? {}
  const after = changes.after ?? {}
  const keys = new Set([...Object.keys(before), ...Object.keys(after)])
  return [...keys].map((key) => ({ key, before: before[key], after: after[key] }))
}

function fmtVal(v) {
  if (v === null || v === undefined) return '—'
  return typeof v === 'object' ? JSON.stringify(v) : String(v)
}

const ACTION_SEVERITY = {
  create: 'success',
  update: 'info',
  cancel: 'danger',
  archive: 'secondary',
  export: 'warn',
}

onMounted(store.fetch)

// Filters apply themselves as they change — no "Apply" button.
useAutoFilter(() => store.filters, () => store.applyFilters())
</script>

<template>
  <div>
    <PageHeader title="Audit trail" subtitle="The append-only log of every change in the system.">
      <template #actions>
        <Button
          v-if="auth.can('audit.export')"
          :label="store.exporting ? 'Exporting…' : 'Export CSV'"
          icon="pi pi-download"
          severity="secondary"
          outlined
          :loading="store.exporting"
          @click="store.exportCsv"
        />
      </template>
    </PageHeader>

    <SectionCard flush>
      <!-- Filters -->
      <FilterPanel :active-count="activeFilterCount">
      <div
        class="grid grid-cols-2 items-end gap-2 border-b border-line px-4 py-3 sm:grid-cols-3 sm:px-5 lg:grid-cols-6"
      >
        <BaseInput v-model="store.filters.action" label="Action" />
        <BaseInput v-model="store.filters.subject_type" label="Subject type" />
        <BaseInput v-model="store.filters.user_id" label="User ID" type="number" />
        <BaseInput v-model="store.filters.from" label="From" type="date" />
        <BaseInput v-model="store.filters.to" label="To" type="date" />
      </div>
      </FilterPanel>

      <p v-if="store.loading" class="py-8 text-center text-sm text-mute">Loading…</p>
      <EmptyState
        v-else-if="!store.items.length"
        icon="pi pi-shield"
        title="No audit entries match these filters"
      />

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs text-mute">
              <th class="px-4 py-2.5 font-medium sm:px-5">When</th>
              <th class="py-2.5 pr-3 font-medium">User</th>
              <th class="py-2.5 pr-3 font-medium">Role</th>
              <th class="py-2.5 pr-3 font-medium">Action</th>
              <th class="py-2.5 pr-3 font-medium">Subject</th>
              <th class="py-2.5 pr-4"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in store.items" :key="row.id">
              <tr class="border-t border-line">
                <td class="num whitespace-nowrap px-4 py-2.5 sm:px-5">
                  {{ formatDateTime(row.created_at) }}
                </td>
                <td class="py-2.5 pr-3">{{ row.user_name ?? row.user_id ?? '—' }}</td>
                <td class="py-2.5 pr-3 text-mute">{{ row.role_at_time ?? '—' }}</td>
                <td class="py-2.5 pr-3">
                  <Tag :value="row.action" :severity="ACTION_SEVERITY[row.action] ?? 'secondary'" />
                </td>
                <td class="py-2.5 pr-3">
                  {{ shortSubject(row.subject_type)
                  }}<span v-if="row.subject_id" class="num text-mute"> #{{ row.subject_id }}</span>
                </td>
                <td class="py-2.5 pr-4 text-right">
                  <Button
                    v-if="row.changes"
                    :label="expanded === row.id ? 'Hide' : 'Diff'"
                    :icon="expanded === row.id ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
                    text
                    size="small"
                    @click="toggle(row.id)"
                  />
                </td>
              </tr>
              <tr
                v-if="expanded === row.id && row.changes"
                class="border-t border-line bg-surface-50 dark:bg-surface-900"
              >
                <td colspan="6" class="px-4 py-3 sm:px-5">
                  <table class="w-full text-xs">
                    <thead>
                      <tr class="text-left text-mute">
                        <th class="py-1 pr-3 font-medium">Field</th>
                        <th class="py-1 pr-3 font-medium">Before</th>
                        <th class="py-1 font-medium">After</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr
                        v-for="d in diffRows(row.changes)"
                        :key="d.key"
                        class="border-t border-line"
                      >
                        <td class="py-1.5 pr-3 font-medium text-ink">{{ d.key }}</td>
                        <td class="num py-1.5 pr-3 text-mute">{{ fmtVal(d.before) }}</td>
                        <td class="num py-1.5 font-medium text-success">{{ fmtVal(d.after) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <!-- Pagination -->
        <div
          v-if="store.meta.last_page > 1"
          class="flex items-center justify-between border-t border-line px-4 py-3 text-sm sm:px-5"
        >
          <Button
            label="Previous"
            icon="pi pi-chevron-left"
            text
            size="small"
            :disabled="store.page <= 1"
            @click="store.goToPage(store.page - 1)"
          />
          <span class="num text-mute">
            Page {{ store.meta.current_page }} / {{ store.meta.last_page }}
          </span>
          <Button
            label="Next"
            icon="pi pi-chevron-right"
            icon-pos="right"
            text
            size="small"
            :disabled="store.page >= store.meta.last_page"
            @click="store.goToPage(store.page + 1)"
          />
        </div>
      </div>
    </SectionCard>
  </div>
</template>
