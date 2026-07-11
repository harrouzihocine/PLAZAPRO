<script setup>
import { ref } from 'vue'
import Button from 'primevue/button'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import OfficeInviteActions from '@/features/pipeline/components/OfficeInviteActions.vue'
import { clientsApi } from '@/features/clients/api'
import { formatPhone } from '@/data/countryCodes'

// The dashboard's prominent entry point: "Invite a client to the office" without
// needing a planned visit first. Pick any client (server-scoped to the caller's
// own book), then hand off over WhatsApp / SMS / copy via OfficeInviteActions.
// A generic invite (no fixed date) — the date-filled version still lives on the
// planned-visit buttons (dashboard rows + client timeline).
const open = ref(false)
const query = ref('')
const results = ref([])
const searching = ref(false)
const selected = ref(null)
let debounce = null

function reset() {
  query.value = ''
  results.value = []
  selected.value = null
}

function onOpen() {
  reset()
  open.value = true
}

function onQuery(value) {
  query.value = value
  clearTimeout(debounce)
  const term = value.trim()
  if (term.length < 2) {
    results.value = []
    return
  }
  debounce = setTimeout(async () => {
    searching.value = true
    try {
      const res = await clientsApi.list({ search: term, per_page: 8 })
      results.value = res.data ?? []
    } catch {
      results.value = []
    } finally {
      searching.value = false
    }
  }, 250)
}

function pick(client) {
  selected.value = client
}
</script>

<template>
  <span>
    <Button
      :label="$t('officeInvite.quickAction')"
      icon="pi pi-send"
      size="small"
      severity="secondary"
      outlined
      @click="onOpen"
    />

    <BaseModal v-if="open" :title="$t('officeInvite.quickAction')" @close="open = false">
      <div class="space-y-4">
        <!-- Step 1: find the client. -->
        <template v-if="!selected">
          <BaseInput
            :model-value="query"
            :label="$t('officeInvite.pickClient')"
            :placeholder="$t('clients.searchPlaceholder')"
            @update:model-value="onQuery"
          />
          <p v-if="searching" class="text-sm text-mute">{{ $t('common.loading') }}</p>
          <ul v-else-if="results.length" class="divide-y divide-line rounded-lg border border-line">
            <li v-for="c in results" :key="c.id">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-2 px-3 py-2 text-start text-sm transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
                @click="pick(c)"
              >
                <span class="truncate font-medium text-ink">{{ c.full_name }}</span>
                <span class="num ltr-data shrink-0 text-xs text-mute">{{ formatPhone(c.phone) }}</span>
              </button>
            </li>
          </ul>
          <p v-else-if="query.trim().length >= 2" class="text-sm text-mute">
            {{ $t('common.noResults') }}
          </p>
        </template>

        <!-- Step 2: send. -->
        <template v-else>
          <div class="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-2">
            <span class="min-w-0">
              <span class="block truncate font-medium text-ink">{{ selected.full_name }}</span>
              <span class="num ltr-data text-xs text-mute">{{ formatPhone(selected.phone) }}</span>
            </span>
            <button
              type="button"
              class="shrink-0 text-sm text-primary-600 hover:underline dark:text-primary-400"
              @click="selected = null"
            >
              {{ $t('officeInvite.changeClient') }}
            </button>
          </div>
          <OfficeInviteActions
            :client-name="selected.full_name"
            :phone="selected.phone"
            @done="open = false"
          />
        </template>
      </div>
    </BaseModal>
  </span>
</template>
