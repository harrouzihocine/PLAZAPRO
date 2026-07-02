<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import 'leaflet/dist/leaflet.css'
import L from 'leaflet'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'
import BaseButton from '@/components/base/BaseButton.vue'

// Leaflet ships its marker images with relative URLs that break under a bundler.
// Point the default icon at the assets Vite has fingerprinted for us.
const markerIconDef = L.icon({
  iconRetinaUrl: markerIcon2x,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  shadowSize: [41, 41],
})

const props = defineProps({
  latitude: { type: [Number, String], default: null },
  longitude: { type: [Number, String], default: null },
  // Editable only: the query for "Search this address" and the target for the
  // reverse-geocoded label written back on click/drag.
  address: { type: String, default: '' },
  editable: { type: Boolean, default: false },
})
const emit = defineEmits(['update:latitude', 'update:longitude', 'update:address'])

// The business operates in Algeria (see the `areas` dynamic list / DemoSeeder),
// so fall back to a country-level view of Algiers when nothing is picked yet.
const DEFAULT_CENTER = [36.7538, 3.0588]
const DEFAULT_ZOOM = 6
const PICKED_ZOOM = 15

// Nominatim is OpenStreetMap's free geocoder. Usage policy: <= 1 req/sec and no
// autocomplete-on-keystroke — that is why searching here is an explicit action.
const NOMINATIM = 'https://nominatim.openstreetmap.org'

const mapEl = ref(null)
let map = null
let marker = null

const searching = ref(false)
const geoError = ref('')
const results = ref([])

function toNum(v) {
  return v === '' || v === null || v === undefined ? null : Number(v)
}

function hasCoords() {
  return toNum(props.latitude) !== null && toNum(props.longitude) !== null
}

function placeMarker(lat, lng) {
  if (marker) {
    marker.setLatLng([lat, lng])
    return
  }
  marker = L.marker([lat, lng], { icon: markerIconDef, draggable: props.editable }).addTo(map)
  if (props.editable) {
    marker.on('dragend', () => {
      const p = marker.getLatLng()
      commit(p.lat, p.lng)
    })
  }
}

// Round to the 7 decimals the `locations` table stores, drop the marker and push
// the new position up. `reverse` fills the address unless the caller already has one.
function commit(lat, lng, { reverse = true } = {}) {
  const rlat = Math.round(lat * 1e7) / 1e7
  const rlng = Math.round(lng * 1e7) / 1e7
  placeMarker(rlat, rlng)
  emit('update:latitude', rlat)
  emit('update:longitude', rlng)
  if (reverse) reverseGeocode(rlat, rlng)
}

async function reverseGeocode(lat, lng) {
  try {
    const res = await fetch(`${NOMINATIM}/reverse?format=jsonv2&lat=${lat}&lon=${lng}`, {
      headers: { Accept: 'application/json' },
    })
    const data = await res.json()
    if (data?.display_name) emit('update:address', data.display_name)
  } catch {
    /* best-effort: keep the coordinates even if the label lookup fails */
  }
}

async function search() {
  const q = props.address.trim()
  geoError.value = ''
  results.value = []
  if (!q) {
    geoError.value = 'Type an address first, or click the map.'
    return
  }
  searching.value = true
  try {
    const res = await fetch(
      `${NOMINATIM}/search?format=jsonv2&limit=5&q=${encodeURIComponent(q)}`,
      { headers: { Accept: 'application/json' } },
    )
    const data = await res.json()
    if (!Array.isArray(data) || !data.length) {
      geoError.value = 'No matches found for that address.'
    } else if (data.length === 1) {
      pick(data[0])
    } else {
      results.value = data
    }
  } catch {
    geoError.value = 'Address lookup failed. Check your connection and try again.'
  } finally {
    searching.value = false
  }
}

function pick(r) {
  results.value = []
  const lat = Number(r.lat)
  const lng = Number(r.lon)
  map.setView([lat, lng], PICKED_ZOOM)
  emit('update:address', r.display_name)
  commit(lat, lng, { reverse: false })
}

function clear() {
  results.value = []
  geoError.value = ''
  if (marker) {
    marker.remove()
    marker = null
  }
  emit('update:latitude', null)
  emit('update:longitude', null)
}

onMounted(() => {
  const start = hasCoords() ? [toNum(props.latitude), toNum(props.longitude)] : DEFAULT_CENTER
  map = L.map(mapEl.value, { scrollWheelZoom: props.editable }).setView(
    start,
    hasCoords() ? PICKED_ZOOM : DEFAULT_ZOOM,
  )
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution:
      '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map)

  if (hasCoords()) placeMarker(toNum(props.latitude), toNum(props.longitude))
  if (props.editable) map.on('click', (e) => commit(e.latlng.lat, e.latlng.lng))

  // The container is frequently measured before layout settles (or while hidden
  // inside a toggled panel); recompute once the DOM has painted.
  setTimeout(() => map && map.invalidateSize(), 0)
})

// Reflect coordinates changed by the parent (e.g. opening the edit form) onto the
// marker. Guarded so our own commits don't fight the watcher.
watch(
  () => [props.latitude, props.longitude],
  () => {
    if (!map) return
    if (!hasCoords()) {
      if (marker) {
        marker.remove()
        marker = null
      }
      return
    }
    const lat = toNum(props.latitude)
    const lng = toNum(props.longitude)
    const cur = marker?.getLatLng()
    if (!cur || cur.lat !== lat || cur.lng !== lng) {
      placeMarker(lat, lng)
      map.setView([lat, lng], Math.max(map.getZoom(), PICKED_ZOOM))
    }
  },
)

onBeforeUnmount(() => {
  if (map) {
    map.remove()
    map = null
  }
})
</script>

<template>
  <div class="space-y-2">
    <div v-if="editable" class="space-y-2">
      <div class="flex flex-wrap items-center gap-2">
        <BaseButton type="button" variant="ghost" :disabled="searching" @click="search">
          {{ searching ? 'Searching…' : 'Search this address' }}
        </BaseButton>
        <span class="text-xs opacity-60">or click the map / drag the marker</span>
      </div>
      <ul
        v-if="results.length"
        class="divide-y divide-border overflow-hidden rounded-token border border-border"
      >
        <li v-for="r in results" :key="r.place_id">
          <button
            type="button"
            class="block w-full px-3 py-2 text-left text-sm hover:bg-surface"
            @click="pick(r)"
          >
            {{ r.display_name }}
          </button>
        </li>
      </ul>
      <p v-if="geoError" class="text-sm text-danger">{{ geoError }}</p>
    </div>

    <div ref="mapEl" class="h-72 w-full rounded-token border border-border" />

    <div v-if="editable" class="flex flex-wrap items-center justify-between gap-2 text-xs">
      <span class="opacity-70">
        <template v-if="hasCoords()">
          📍 {{ Number(latitude).toFixed(5) }}, {{ Number(longitude).toFixed(5) }}
        </template>
        <template v-else>No point selected yet.</template>
      </span>
      <button v-if="hasCoords()" type="button" class="text-danger hover:underline" @click="clear">
        Clear
      </button>
    </div>
  </div>
</template>
