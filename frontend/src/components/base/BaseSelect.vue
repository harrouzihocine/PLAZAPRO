<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

// A searchable, optionally-clearable single-select — a drop-in for native
// <select>. modelValue is the chosen option's value ('' means none). The panel is
// teleported to <body> (so an overflow container can't clip it) and shows a
// search box for long lists. Emits `update:modelValue` and `change` (with the new
// value) so `@change="fetch()"`-style handlers keep working. Options are
// { value, label }; an option may carry `disabled: true`.
const props = defineProps({
  label: { type: String, default: '' },
  modelValue: { type: [String, Number], default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'Select…' },
  clearable: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
  ariaLabel: { type: String, default: '' },
  // 'auto' shows the search box only once the list is long enough to need it.
  searchable: { type: [Boolean, String], default: 'auto' },
})
const emit = defineEmits(['update:modelValue', 'change'])

const open = ref(false)
const trigger = ref(null)
const searchInput = ref(null)
const search = ref('')
const panelStyle = ref({})

const selected = computed(() =>
  props.options.find((o) => String(o.value) === String(props.modelValue)),
)
const hasValue = computed(() => props.modelValue !== '' && props.modelValue != null)

const showSearch = computed(() =>
  props.searchable === 'auto' ? props.options.length > 6 : Boolean(props.searchable),
)

const filteredOptions = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter((o) => String(o.label).toLowerCase().includes(q))
})

function choose(option) {
  if (option.disabled) return
  open.value = false
  if (String(option.value) !== String(props.modelValue)) {
    emit('update:modelValue', option.value)
    emit('change', option.value)
  }
}

function clear() {
  if (!hasValue.value) return
  emit('update:modelValue', '')
  emit('change', '')
}

function toggleOpen() {
  if (props.disabled) return
  open.value = !open.value
}

// Anchor the teleported panel under the trigger.
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
  <label class="block">
    <span v-if="label" class="mb-1 block text-sm">{{ label }}</span>
    <div
      ref="trigger"
      class="flex w-full items-center gap-1 rounded-token border border-border bg-bg pl-3 pr-2 min-h-[44px] text-ink focus-within:border-primary"
      :class="{ 'opacity-60': disabled }"
    >
      <button
        type="button"
        class="flex min-w-0 flex-1 items-center py-2 text-left outline-none"
        :disabled="disabled"
        :aria-label="ariaLabel || undefined"
        :aria-expanded="open"
        @click="toggleOpen"
      >
        <span class="block truncate" :class="{ 'opacity-60': !selected }">
          {{ selected ? selected.label : placeholder }}
        </span>
      </button>
      <button
        v-if="clearable && hasValue && !disabled"
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
        :disabled="disabled"
        @click="toggleOpen"
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
          <button
            v-for="o in filteredOptions"
            :key="o.value"
            type="button"
            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-bg"
            :class="{
              'font-semibold text-primary': String(o.value) === String(modelValue),
              'cursor-not-allowed opacity-40': o.disabled,
            }"
            :disabled="o.disabled"
            @click="choose(o)"
          >
            <span class="truncate">{{ o.label }}</span>
          </button>
        </div>
      </div>
    </Teleport>
  </label>
</template>
