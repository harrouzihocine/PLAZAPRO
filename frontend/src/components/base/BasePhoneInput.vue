<script setup>
import { computed, ref, watch } from 'vue'
import {
  COUNTRY_CODES,
  DEFAULT_DIAL_CODE,
  DIAL_CODES_BY_LENGTH,
  formatNational,
  toNationalNumber,
} from '@/data/countryCodes'

const props = defineProps({
  label: { type: String, default: '' },
  // Full stored value, e.g. "+213 550112233".
  modelValue: { type: String, default: '' },
  error: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const favorites = COUNTRY_CODES.filter((c) => c.favorite)
const rest = COUNTRY_CODES.filter((c) => !c.favorite)

const dial = ref(DEFAULT_DIAL_CODE)
const number = ref('')

// Split a stored value into (dial code, national number). Values without a
// leading "+" (e.g. legacy "0550 11 22 33") keep the default dial code and go
// straight into the number field.
function parse(value) {
  const raw = (value ?? '').trim()
  if (!raw) return { dial: DEFAULT_DIAL_CODE, number: '' }
  if (raw.startsWith('+')) {
    const compact = raw.replace(/\s+/g, '')
    const match = DIAL_CODES_BY_LENGTH.find((code) => compact.startsWith(code))
    if (match) return { dial: match, number: compact.slice(match.length) }
  }
  return { dial: DEFAULT_DIAL_CODE, number: raw }
}

// Store in compact international (E.164) form, e.g. "+213555042142": the trunk
// "0" is dropped per the country's rules. This is what shows everywhere.
const combined = computed(() => {
  const national = toNationalNumber(number.value, dial.value)
  return national ? `${dial.value}${national}` : ''
})

// Keep internal state in sync with external value (e.g. when editing a record),
// without clobbering the user's in-progress typing.
watch(
  () => props.modelValue,
  (value) => {
    if (value === combined.value) return
    const parsed = parse(value)
    dial.value = parsed.dial
    number.value =
      formatNational(toNationalNumber(parsed.number, parsed.dial), parsed.dial) || parsed.number
  },
  { immediate: true },
)

function emitValue() {
  emit('update:modelValue', combined.value)
}

// On blur, reflect the normalised, grouped national number back into the field so
// what the user sees matches the display style (e.g. "0555042142" → "555 04 21 42").
function normalizeField() {
  number.value = formatNational(toNationalNumber(number.value, dial.value), dial.value)
  emitValue()
}
</script>

<template>
  <label class="block">
    <span v-if="label" class="mb-1 block text-sm">{{ label }}</span>
    <div class="flex gap-2">
      <select
        v-model="dial"
        aria-label="Country dialing code"
        class="w-28 shrink-0 rounded-token border border-border bg-bg px-2 py-2 min-h-[44px] text-ink outline-none focus:border-primary"
        @change="emitValue"
      >
        <optgroup label="Favorites">
          <option v-for="c in favorites" :key="`fav-${c.iso}`" :value="c.dial">
            {{ c.flag }} {{ c.dial }}
          </option>
        </optgroup>
        <optgroup label="All countries">
          <option v-for="c in rest" :key="c.iso" :value="c.dial">
            {{ c.flag }} {{ c.name }} ({{ c.dial }})
          </option>
        </optgroup>
      </select>
      <input
        v-model="number"
        type="tel"
        inputmode="tel"
        class="w-full rounded-token border border-border bg-bg px-3 py-2 min-h-[44px] text-ink outline-none focus:border-primary"
        @input="emitValue"
        @blur="normalizeField"
      />
    </div>
    <span v-if="error" class="mt-1 block text-sm text-danger">{{ error }}</span>
  </label>
</template>
