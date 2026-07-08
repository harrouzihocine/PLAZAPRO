<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import OverlayBadge from 'primevue/overlaybadge'
import Popover from 'primevue/popover'
import { useDraftsStore } from '@/features/drafts/draftsStore'
import { timeAgo } from '@/utils/format'

// Top-bar indicator for unsaved modal drafts: a misclick outside a form never
// loses work — it lands here. Clicking a draft returns to the page it lived on
// with ?resume=<key>, which auto-reopens the modal with the values restored.
const drafts = useDraftsStore()
const router = useRouter()
const panel = ref(null)

function openDraft(draft) {
  panel.value?.hide()
  const [path, query = ''] = draft.route.split('?')
  const params = new URLSearchParams(query)
  params.set('resume', draft.key)
  router.push(`${path}?${params.toString()}`)
}
</script>

<template>
  <div v-if="drafts.count > 0">
    <OverlayBadge :value="String(drafts.count)" severity="warn" size="small">
      <Button
        icon="pi pi-pencil"
        text
        rounded
        severity="secondary"
        :aria-label="$t('drafts.unsavedAria', { n: drafts.count })"
        @click="panel.toggle($event)"
      />
    </OverlayBadge>

    <Popover ref="panel" class="w-80 max-w-[92vw]" :pt="{ content: { class: '!p-0' } }">
      <div class="border-b border-line px-4 py-3">
        <span class="text-sm font-semibold text-ink">{{ $t('drafts.title') }}</span>
        <p class="mt-0.5 text-xs text-mute">{{ $t('drafts.hint') }}</p>
      </div>
      <ul class="max-h-[50vh] divide-y divide-line overflow-y-auto">
        <li v-for="d in drafts.list" :key="d.key">
          <!-- Non-dismissable: a draft stays until it is saved (or an oversight
               admin clears it) — you can only resume it, not discard it. -->
          <button type="button" class="block w-full px-4 py-2.5 text-start" @click="openDraft(d)">
            <span class="block truncate text-sm font-medium text-ink">{{ d.label }}</span>
            <span class="block text-xs text-mute">{{ timeAgo(d.savedAt) }}</span>
          </button>
        </li>
      </ul>
    </Popover>
  </div>
</template>
