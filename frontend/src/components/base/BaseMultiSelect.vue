<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

// A checkbox dropdown for filtering by several values at once. modelValue is an
// array of the selected option values; the trigger summarises the selection.
// The panel is teleported to <body> and positioned with fixed coordinates from
// the trigger, so it can't be clipped by an overflow container (the filter bar
// scrolls horizontally). Options are { value, label }.
const props = defineProps({
  label: { type: String, default: '' },
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'All' },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const trigger = ref(null)
const panelStyle = ref({})

const summary = computed(() => {
  if (!props.modelValue.length) return props.placeholder
  if (props.modelValue.length === 1) {
    const one = props.options.find((o) => String(o.value) === String(props.modelValue[0]))
    return one ? one.label : '1 selected'
  }
  return `${props.modelValue.length} selected`
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
    await nextTick()
    updatePosition()
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
    <button
      ref="trigger"
      type="button"
      class="flex w-full items-center justify-between gap-2 rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-left text-ink outline-none focus:border-primary"
      :aria-expanded="open"
      @click="open = !open"
    >
      <span class="truncate" :class="{ 'opacity-60': !modelValue.length }">{{ summary }}</span>
      <span aria-hidden="true" class="opacity-60">▾</span>
    </button>

    <Teleport to="body">
      <!-- Click-away backdrop -->
      <div v-if="open" class="fixed inset-0 z-40" @click="open = false" />

      <div
        v-if="open"
        class="z-50 max-h-64 overflow-y-auto rounded-token border border-border bg-surface shadow-lg"
        :style="panelStyle"
      >
        <p v-if="!options.length" class="px-3 py-3 text-center text-sm text-ink opacity-60">
          No options.
        </p>
        <label
          v-for="o in options"
          :key="o.value"
          class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-bg"
        >
          <input type="checkbox" :checked="isChecked(o.value)" @change="toggle(o.value)" />
          <span class="truncate">{{ o.label }}</span>
        </label>
      </div>
    </Teleport>
  </div>
</template>
