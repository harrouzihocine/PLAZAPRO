<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import { useApi } from '@/composables/useApi'
import { useAuthStore } from '@/features/settings/store'
import { t } from '@/i18n'
import { fullName } from '@/utils/names'

// Ctrl+K command palette: one box that finds clients, locations and units.
// Results are grouped, keyboard-navigable, and permission-aware.
const api = useApi()
const auth = useAuthStore()
const router = useRouter()

const open = ref(false)
const query = ref('')
const results = ref([])
const active = ref(0)
const searching = ref(false)
const input = ref(null)
let timer = null
let requestSeq = 0

function show() {
  open.value = true
  query.value = ''
  results.value = []
  active.value = 0
}

function onKeydown(e) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    show()
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))

const GROUPS = [
  { key: 'clients', labelKey: 'nav.clients', icon: 'pi pi-user', permission: 'clients.view' },
  { key: 'locations', labelKey: 'nav.locations', icon: 'pi pi-building', permission: 'units.view' },
  { key: 'units', labelKey: 'nav.units', icon: 'pi pi-th-large', permission: 'units.view' },
]

async function search(term) {
  const seq = ++requestSeq
  searching.value = true
  try {
    const calls = []
    if (auth.can('clients.view')) {
      calls.push(
        api
          .get('/clients', { params: { search: term } })
          .then((r) => ({ key: 'clients', rows: r.data.data ?? [] })),
      )
    }
    if (auth.can('units.view')) {
      calls.push(
        api
          .get('/locations', { params: { q: term } })
          .then((r) => ({ key: 'locations', rows: r.data.data ?? [] })),
        api
          .get('/units', { params: { search: term } })
          .then((r) => ({ key: 'units', rows: r.data.data ?? [] })),
      )
    }
    const settled = await Promise.allSettled(calls)
    if (seq !== requestSeq) return // a newer search is in flight

    const byKey = Object.fromEntries(
      settled.filter((s) => s.status === 'fulfilled').map((s) => [s.value.key, s.value.rows]),
    )

    const flat = []
    for (const group of GROUPS) {
      for (const row of (byKey[group.key] ?? []).slice(0, 5)) {
        flat.push({ group, row, ...describe(group.key, row) })
      }
    }
    results.value = flat
    active.value = 0
  } finally {
    if (seq === requestSeq) searching.value = false
  }
}

function describe(kind, row) {
  if (kind === 'clients') {
    return {
      title: fullName(row) || row.full_name || `Client #${row.id}`,
      meta: row.phone ?? row.email ?? '',
      to: `/clients/${row.id}`,
    }
  }
  if (kind === 'locations') {
    return {
      title: row.name,
      meta: [row.code, row.wilaya?.name].filter(Boolean).join(' · '),
      to: `/inventory/locations/${row.id}`,
    }
  }
  return {
    title: t('search.unitTitle', { ref: row.reference }),
    meta: [row.location?.name, row.type?.label ?? row.type?.value].filter(Boolean).join(' · '),
    to: `/inventory/units/${row.id}`,
  }
}

watch(query, (term) => {
  clearTimeout(timer)
  const t = term.trim()
  if (t.length < 2) {
    results.value = []
    return
  }
  timer = setTimeout(() => search(t), 250)
})

function move(delta) {
  if (!results.value.length) return
  active.value = (active.value + delta + results.value.length) % results.value.length
}

function go(item = results.value[active.value]) {
  if (!item) return
  open.value = false
  router.push(item.to)
}

const grouped = computed(() => {
  const seen = new Set()
  return results.value.map((item, index) => {
    const first = !seen.has(item.group.key)
    seen.add(item.group.key)
    return { ...item, index, first }
  })
})

defineExpose({ show })
</script>

<template>
  <Dialog
    v-model:visible="open"
    modal
    dismissable-mask
    :show-header="false"
    position="top"
    class="mx-3 mt-[10vh] w-full max-w-xl overflow-hidden !rounded-xl"
    :pt="{ content: { class: '!p-0' } }"
    @show="input?.$el?.focus()"
  >
    <div class="flex items-center gap-2 border-b border-line px-4">
      <i class="pi pi-search text-mute" aria-hidden="true" />
      <InputText
        ref="input"
        v-model="query"
        :placeholder="$t('search.placeholder')"
        class="w-full !border-0 !bg-transparent !py-3.5 !shadow-none focus:!outline-none"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="go()"
        @keydown.esc="open = false"
      />
      <kbd class="hidden rounded border border-line px-1.5 py-0.5 text-[10px] text-mute sm:block">
        ESC
      </kbd>
    </div>

    <div class="max-h-[50vh] overflow-y-auto p-2">
      <p v-if="query.trim().length < 2" class="px-3 py-6 text-center text-sm text-mute">
        {{ $t('search.hint') }}
      </p>
      <p v-else-if="searching && !results.length" class="px-3 py-6 text-center text-sm text-mute">
        {{ $t('search.searching') }}
      </p>
      <p v-else-if="!results.length" class="px-3 py-6 text-center text-sm text-mute">
        {{ $t('search.noMatch', { query }) }}
      </p>

      <template v-for="item in grouped" :key="item.group.key + '-' + item.row.id">
        <p
          v-if="item.first"
          class="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-mute"
        >
          {{ $t(item.group.labelKey) }}
        </p>
        <button
          type="button"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start"
          :class="
            item.index === active
              ? 'bg-highlight'
              : 'hover:bg-surface-100 dark:hover:bg-surface-800'
          "
          @mouseenter="active = item.index"
          @click="go(item)"
        >
          <i :class="item.group.icon" class="text-mute" aria-hidden="true" />
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium text-ink">{{ item.title }}</span>
            <span v-if="item.meta" class="block truncate text-xs text-mute">{{ item.meta }}</span>
          </span>
          <i
            v-if="item.index === active"
            class="pi pi-arrow-right text-xs text-mute"
            aria-hidden="true"
          />
        </button>
      </template>
    </div>
  </Dialog>
</template>
