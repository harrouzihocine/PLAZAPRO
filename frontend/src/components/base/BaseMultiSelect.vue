<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

// A checkbox dropdown for filtering by several values at once. modelValue is an
// array of the selected option values; the trigger summarises the selection and
// carries a clear (✕) button. The panel is teleported to <body> and positioned
// with fixed coordinates from the trigger, so it can't be clipped by an overflow
// container, and offers a search box for long lists. Options are { value, label }.
const props = defineProps({
  label: { type: String, default: '' },
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'All' },
  // 'auto' shows the search box only once the list is long enough to need it.
  searchable: { type: [Boolean, String], default: 'auto' },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const trigger = ref(null)
const searchInput = ref(null)
const search = ref('')
const panelStyle = ref({})

const summary = computed(() => {
  if (!props.modelValue.length) return props.placeholder
  if (props.modelValue.length === 1) {
    const one = props.options.find((o) => String(o.value) === String(props.modelValue[0]))
    return one ? one.label : '1 selected'
  }
  return `${props.modelValue.length} selected`
})

const showSearch = computed(() =>
  props.searchable === 'auto' ? props.options.length > 6 : Boolean(props.searchable),
)

const filteredOptions = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter((o) => String(o.label).toLowerCase().includes(q))
})

function isChecked(value) {
  return props.modelValue.some((v) => String(v) === String(value))
}

function toggle(value) {
  const next = isChecked(value)
    ? props.modelValue.filter((v) => String(v) !== String(value))
    : [...props.modelValue, value]
  emit('update:modelValue', next)
}

function clear() {
  if (props.modelValue.length) emit('update:modelValue', [])
}

// Anchor the teleported panel under the trigger button.
function updatePosition() {
  const el = trigger.value
  if (!el) return
  const r = el.getBoundingClientRect()
  panelStyle.value = {
    position: 'fixed',
    top: `${r.bottom + 4}px`,
    left: `${r.left}px`,
    minWidth: `${r.width}px`,
  }
}

watch(open, async (isOpen) => {
  if (isOpen) {
    search.value = ''
    await nextTick()
    updatePosition()
    searchInput.value?.focus()
    window.addEventListener('scroll', updatePosition, true)
    window.addEventListener('resize', updatePosition)
  } else {
    window.removeEventListener('scroll', updatePosition, true)
    window.removeEventListener('resize', updatePosition)
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', updatePosition, true)
  window.removeEventListener('resize', updatePosition)
})
</script>

<template>
  <div class="block">
    <span v-if="label" class="mb-1 block text-sm">{{ label }}</span>
    <div
      ref="trigger"
      class="flex w-full items-center gap-1 rounded-token border border-border bg-bg pl-3 pr-2 min-h-[44px] text-ink focus-within:border-primary"
    >
      <button
        type="button"
        class="flex min-w-0 flex-1 items-center py-2 text-left outline-none"
        :aria-expanded="open"
        @click="open = !open"
      >
        <span class="block truncate" :class="{ 'opacity-60': !modelValue.length }">{{ summary }}</span>
      </button>
      <button
        v-if="modelValue.length"
        type="button"
        class="shrink-0 rounded px-1 text-lg leading-none opacity-60 hover:opacity-100"
        aria-label="Clear selection"
        @click="clear"
      >
        ×
      </button>
      <button
        type="button"
        class="shrink-0 opacity-60"
        aria-label="Toggle options"
        tabindex="-1"
        @click="open = !open"
      >
        ▾
      </button>
    </div>

    <Teleport to="body">
      <!-- Click-away backdrop -->
      <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />

      <div
        v-if="open"
        class="z-50 flex max-h-72 flex-col overflow-hidden rounded-token border border-border bg-surface shadow-lg"
        :style="panelStyle"
      >
        <div v-if="showSearch" class="border-b border-border p-2">
          <input
            ref="searchInput"
            v-model="search"
            type="text"
            placeholder="Search…"
            class="w-full rounded-token border border-border bg-bg px-2 py-1.5 text-sm text-ink outline-none focus:border-primary"
          />
        </div>
        <div class="overflow-y-auto">
          <p
            v-if="!filteredOptions.length"
            class="px-3 py-3 text-center text-sm text-ink opacity-60"
          >
            {{ options.length ? 'No matches.' : 'No options.' }}
          </p>
          <label
            v-for="o in filteredOptions"
            :key="o.value"
            class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-bg"
            :class="{ 'bg-bg font-medium': isChecked(o.value) }"
          >
            <input
              type="checkbox"
              class="h-4 w-4 shrink-0 accent-primary"
              :checked="isChecked(o.value)"
              @change="toggle(o.value)"
            />
            <span class="truncate">{{ o.label }}</span>
          </label>
        </div>
      </div>
    </Teleport>
  </div>
</template>
