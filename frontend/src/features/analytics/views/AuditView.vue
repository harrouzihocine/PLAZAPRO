<script setup>
import { onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useAuditStore } from '@/features/analytics/auditStore'
import { useAuthStore } from '@/features/settings/store'

const store = useAuditStore()
const auth = useAuthStore()
const expanded = ref(null)

function toggle(id) {
  expanded.value = expanded.value === id ? null : id
}

function fmtDateTime(value) {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
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

onMounted(store.fetch)
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h1 class="text-2xl font-semibold">Audit trail</h1>
      <BaseButton
        v-if="auth.can('audit.export')"
        variant="ghost"
        :disabled="store.exporting"
        @click="store.exportCsv"
      >
        {{ store.exporting ? 'Exporting…' : 'Export CSV' }}
      </BaseButton>
    </div>

    <BaseCard>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        <BaseInput v-model="store.filters.action" label="Action" />
        <BaseInput v-model="store.filters.subject_type" label="Subject type" />
        <BaseInput v-model="store.filters.user_id" label="User ID" type="number" />
        <BaseInput v-model="store.filters.from" label="From" type="date" />
        <BaseInput v-model="store.filters.to" label="To" type="date" />
      </div>
      <div class="mt-2">
        <BaseButton @click="store.applyFilters">Apply filters</BaseButton>
      </div>
    </BaseCard>

    <p v-if="store.error" class="text-sm text-danger">{{ store.error }}</p>

    <BaseCard>
      <p v-if="store.loading" class="py-4 text-center text-sm opacity-60">Loading…</p>
      <p v-else-if="!store.items.length" class="py-4 text-center text-sm opacity-60">
        No audit entries match these filters.
      </p>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left opacity-60">
            <tr>
              <th class="py-2 pr-3">When</th>
              <th class="py-2 pr-3">User</th>
              <th class="py-2 pr-3">Role</th>
              <th class="py-2 pr-3">Action</th>
              <th class="py-2 pr-3">Subject</th>
              <th class="py-2"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="row in store.items" :key="row.id">
              <tr class="border-t border-border">
                <td class="py-2 pr-3 whitespace-nowrap">{{ fmtDateTime(row.created_at) }}</td>
                <td class="py-2 pr-3">{{ row.user_id ?? '—' }}</td>
                <td class="py-2 pr-3">{{ row.role_at_time ?? '—' }}</td>
                <td class="py-2 pr-3 font-medium capitalize">{{ row.action }}</td>
                <td class="py-2 pr-3">
                  {{ shortSubject(row.subject_type) }}<span v-if="row.subject_id" class="opacity-60"> #{{ row.subject_id }}</span>
                </td>
                <td class="py-2 text-right">
                  <button
                    v-if="row.changes"
                    type="button"
                    class="text-primary underline"
                    @click="toggle(row.id)"
                  >
                    {{ expanded === row.id ? 'Hide' : 'Diff' }}
                  </button>
                </td>
              </tr>
              <tr v-if="expanded === row.id && row.changes" class="border-t border-border bg-bg">
                <td colspan="6" class="p-3">
                  <table class="w-full text-xs">
                    <thead class="text-left opacity-60">
                      <tr>
                        <th class="py-1 pr-3">Field</th>
                        <th class="py-1 pr-3">Before</th>
                        <th class="py-1">After</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="d in diffRows(row.changes)" :key="d.key" class="border-t border-border">
                        <td class="py-1 pr-3 font-medium">{{ d.key }}</td>
                        <td class="py-1 pr-3 opacity-70">{{ fmtVal(d.before) }}</td>
                        <td class="py-1 text-success">{{ fmtVal(d.after) }}</td>
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
          class="mt-3 flex items-center justify-between text-sm"
        >
          <BaseButton
            variant="ghost"
            :disabled="store.page <= 1"
            @click="store.goToPage(store.page - 1)"
          >
            Previous
          </BaseButton>
          <span class="opacity-70">Page {{ store.meta.current_page }} / {{ store.meta.last_page }}</span>
          <BaseButton
            variant="ghost"
            :disabled="store.page >= store.meta.last_page"
            @click="store.goToPage(store.page + 1)"
          >
            Next
          </BaseButton>
        </div>
      </div>
    </BaseCard>
  </div>
</template>
