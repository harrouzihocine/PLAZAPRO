<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import 'leaflet/dist/leaflet.css'
import L from 'leaflet'
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png'
import markerIcon from 'leaflet/dist/images/marker-icon.png'
import markerShadow from 'leaflet/dist/images/marker-shadow.png'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { pipelineApi } from '@/features/pipeline/api'
import {
  AGENT_STATUS_COLORS,
  AGENT_STATUS_DOTS,
  AGENT_STATUS_LABEL_KEYS,
  VISIT_STATUS_LABEL_KEYS,
} from '@/features/pipeline/dispatchStatus'
import { getEcho } from '@/composables/useEcho'
import { toastError } from '@/composables/useConfirm'
import { formatDateTime, initials, todayInput } from '@/utils/format'
import { t } from '@/i18n'

// The dispatcher's live map: agent dots coloured by status (moving over
// Reverb without polling), today's site pins with their visits, pending pool
// sites hollow. A replay mode swaps the live layer for one agent's breadcrumb
// trail on a chosen day — dispute resolution, not surveillance.

const siteIconDef = L.icon({
  iconRetinaUrl: markerIcon2x,
  iconUrl: markerIcon,
  shadowUrl: markerShadow,
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  shadowSize: [41, 41],
})

const DEFAULT_CENTER = [36.7538, 3.0588] // Algiers
const DEFAULT_ZOOM = 11

const mapEl = ref(null)
const loading = ref(true)
const tilesUnavailable = ref(false)
const agents = ref([])
const unpinned = ref([])

// Replay state
const mode = ref('live') // 'live' | 'replay'
const replayAgentId = ref(null)
const replayDate = ref(todayInput())
const replayLoading = ref(false)
const replayEmpty = ref(false)

let map = null
let agentLayer = null
let siteLayer = null
let replayLayer = null
let channel = null
let sitesCache = []
let pendingCache = []
// Marker per agent id, so the roster's "show me" can fly to and open one.
const agentMarkers = new Map()

// Full-screen overlay (same pattern as LocationMap): body scroll locked,
// Escape exits, Leaflet re-measures after the box change.
const expanded = ref(false)

async function toggleExpanded(value) {
  expanded.value = typeof value === 'boolean' ? value : !expanded.value
  document.body.style.overflow = expanded.value ? 'hidden' : ''
  await nextTick()
  refreshSize()
}

function refreshSize() {
  requestAnimationFrame(() =>
    requestAnimationFrame(() => map && map.invalidateSize({ animate: false })),
  )
}

function onKeydown(e) {
  if (e.key === 'Escape' && expanded.value) toggleExpanded(false)
}

// The roster beside the map: on-duty agents first, GPS-less ones listed too
// (greyed) so "who is dark" is visible at a glance, never hunted for.
const roster = computed(() =>
  [...agents.value].sort(
    (a, b) =>
      (a.status === 'off_duty') - (b.status === 'off_duty')
      || !a.position - !b.position
      || a.name.localeCompare(b.name),
  ),
)

const rosterDot = (s) => AGENT_STATUS_DOTS[s] ?? AGENT_STATUS_DOTS.off_duty

function minutesAgo(at) {
  const n = Math.max(0, Math.round((Date.now() - new Date(at).getTime()) / 60000))
  return t('dispatch.minAgo', { n })
}

// Click a name → fly to the agent's last fix, open their popup, and ping
// their device for a fresh one (idle tracking is coarse on purpose — the
// battery contract — so "a dispatcher looking" is what buys precision).
function focusAgent(agent) {
  if (!agent.position || !map) return
  if (mode.value !== 'live') setMode('live')
  map.setView([agent.position.lat, agent.position.lng], Math.max(map.getZoom(), 15))
  agentMarkers.get(agent.id)?.openPopup()
  if (agent.status !== 'off_duty') {
    pipelineApi.dispatchLocate(agent.id).catch(() => {})
  }
}

function agentDivIcon(agent) {
  const color = AGENT_STATUS_COLORS[agent.status] ?? AGENT_STATUS_COLORS.off_duty
  return L.divIcon({
    className: '',
    html: `<div style="display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9999px;background:${color};color:#fff;font-weight:700;font-size:12px;border:2.5px solid #fff;box-shadow:0 1px 4px rgb(0 0 0 / .4)">${initials(agent.name)}</div>`,
    iconSize: [34, 34],
    iconAnchor: [17, 17],
  })
}

function agentPopup(agent) {
  const status = t(AGENT_STATUS_LABEL_KEYS[agent.status] ?? AGENT_STATUS_LABEL_KEYS.off_duty)
  const seen = agent.position?.at
    ? `<div style="opacity:.7">${t('dispatch.lastSeen', { time: formatDateTime(agent.position.at) })}</div>`
    : ''
  return `<div style="min-width:150px"><strong>${agent.name}</strong><div>${status}</div>${seen}</div>`
}

function renderAgents() {
  agentLayer.clearLayers()
  agentMarkers.clear()
  for (const agent of agents.value) {
    if (!agent.position) continue
    const marker = L.marker([agent.position.lat, agent.position.lng], {
      icon: agentDivIcon(agent),
      zIndexOffset: 1000,
    })
      .bindPopup(agentPopup(agent))
      .addTo(agentLayer)
    agentMarkers.set(agent.id, marker)
  }
}

function sitePopup(site) {
  const rows = site.visits
    .map((v) => {
      const status = t(VISIT_STATUS_LABEL_KEYS[v.status] ?? VISIT_STATUS_LABEL_KEYS.assigned)
      return `<div style="margin-top:4px">${v.time ? `<span style="font-variant-numeric:tabular-nums">${v.time}</span> · ` : ''}${v.client ?? '—'}${v.unit ? ` · ${v.unit}` : ''}<br><span style="opacity:.75">${v.agent ?? ''} — ${status}</span></div>`
    })
    .join('')
  return `<div style="min-width:180px"><strong>${site.name}</strong>${rows}</div>`
}

function renderSites() {
  siteLayer.clearLayers()
  for (const site of sitesCache) {
    if (site.lat === null || site.lng === null) continue
    L.marker([site.lat, site.lng], { icon: siteIconDef })
      .bindPopup(sitePopup(site))
      .addTo(siteLayer)
  }
  for (const site of pendingCache) {
    L.circleMarker([site.lat, site.lng], {
      radius: 9,
      color: '#f59e0b',
      weight: 2.5,
      fillColor: '#f59e0b',
      fillOpacity: 0.15,
    })
      .bindTooltip(`${site.name} — ${t('dispatch.pendingSite')}`)
      .addTo(siteLayer)
  }
}

function fitToContent() {
  const bounds = L.latLngBounds([])
  agentLayer.eachLayer((l) => bounds.extend(l.getLatLng()))
  siteLayer.eachLayer((l) => bounds.extend(l.getLatLng()))
  if (bounds.isValid()) map.fitBounds(bounds.pad(0.2), { maxZoom: 14 })
}

async function loadLive() {
  loading.value = true
  try {
    const data = await pipelineApi.dispatchMap()
    agents.value = data.agents
    sitesCache = data.sites
    pendingCache = data.pending_sites ?? []
    unpinned.value = data.unpinned_site_names ?? []
    renderAgents()
    renderSites()
    fitToContent()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('dispatch.mapLoadFailed'))
  } finally {
    loading.value = false
  }
}

// --- Replay -----------------------------------------------------------------

async function loadReplay() {
  if (!replayAgentId.value || !replayDate.value) return
  replayLoading.value = true
  replayEmpty.value = false
  try {
    const data = await pipelineApi.dispatchReplay(replayAgentId.value, replayDate.value)
    replayLayer.clearLayers()
    const points = data.positions.map((p) => [p.lat, p.lng])
    if (points.length) {
      L.polyline(points, { color: '#8b5cf6', weight: 3, opacity: 0.8 }).addTo(replayLayer)
      L.circleMarker(points[0], { radius: 6, color: '#10b981', fillOpacity: 1 })
        .bindTooltip(formatDateTime(data.positions[0].at))
        .addTo(replayLayer)
      L.circleMarker(points[points.length - 1], { radius: 6, color: '#ef4444', fillOpacity: 1 })
        .bindTooltip(formatDateTime(data.positions[data.positions.length - 1].at))
        .addTo(replayLayer)
    }
    for (const v of data.visits) {
      if (v.lat === null || v.lng === null) continue
      const status = t(VISIT_STATUS_LABEL_KEYS[v.status] ?? VISIT_STATUS_LABEL_KEYS.assigned)
      L.marker([v.lat, v.lng], { icon: siteIconDef })
        .bindPopup(
          `<strong>${v.site ?? ''}</strong><br>${v.client ?? '—'} — ${status}${v.arrived_at ? `<br>${t('dispatch.arrivedAt', { time: formatDateTime(v.arrived_at) })}` : ''}`,
        )
        .addTo(replayLayer)
    }
    replayEmpty.value = !points.length && !data.visits.length
    const bounds = L.latLngBounds([])
    replayLayer.eachLayer((l) => bounds.extend(l.getLatLng?.() ?? l.getBounds?.().getCenter()))
    if (bounds.isValid()) map.fitBounds(bounds.pad(0.2), { maxZoom: 15 })
  } catch (e) {
    toastError(e.response?.data?.message ?? t('dispatch.mapLoadFailed'))
  } finally {
    replayLoading.value = false
  }
}

function setMode(next) {
  mode.value = next
  if (next === 'live') {
    replayLayer.clearLayers()
    map.addLayer(agentLayer)
    map.addLayer(siteLayer)
    fitToContent()
  } else {
    map.removeLayer(agentLayer)
    map.removeLayer(siteLayer)
  }
}

// --- Live updates over Reverb -------------------------------------------------

function subscribe() {
  const echo = getEcho()
  if (!echo) return
  channel = echo.private('dispatch')
  channel.listen('.agent.position', (e) => {
    const agent = agents.value.find((a) => a.id === e.userId)
    if (!agent) return
    agent.position = { lat: e.lat, lng: e.lng, at: e.recordedAt }
    agent.status = e.status
    if (mode.value === 'live') renderAgents()
  })
  channel.listen('.agent.duty', (e) => {
    const agent = agents.value.find((a) => a.id === e.userId)
    if (!agent) return
    agent.status = e.status
    if (mode.value === 'live') renderAgents()
  })
  channel.listen('.visit.lifecycle', (e) => {
    for (const site of sitesCache) {
      const visit = site.visits.find((v) => v.id === e.visit_id)
      if (visit) visit.status = e.status
    }
    if (mode.value === 'live') renderSites()
  })
}

onMounted(() => {
  map = L.map(mapEl.value, { scrollWheelZoom: true }).setView(DEFAULT_CENTER, DEFAULT_ZOOM)
  const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution:
      '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
  }).addTo(map)
  tiles.on('tileerror', () => {
    tilesUnavailable.value = true
  })
  tiles.on('tileload', () => {
    tilesUnavailable.value = false
  })

  agentLayer = L.layerGroup().addTo(map)
  siteLayer = L.layerGroup().addTo(map)
  replayLayer = L.layerGroup().addTo(map)

  // Leaflet measures its container before layout settles — re-measure after paint.
  requestAnimationFrame(() =>
    requestAnimationFrame(() => map && map.invalidateSize({ animate: false })),
  )

  window.addEventListener('keydown', onKeydown)
  loadLive()
  subscribe()
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
  channel?.stopListening('.agent.position')
  channel?.stopListening('.agent.duty')
  channel?.stopListening('.visit.lifecycle')
  if (map) {
    map.remove()
    map = null
  }
})

defineExpose({ reload: loadLive })
</script>

<template>
  <div :class="expanded ? 'fixed inset-0 z-[1100] flex flex-col gap-3 overflow-y-auto bg-bg p-4' : 'space-y-3'">
    <!-- Mode + replay controls -->
    <div class="flex flex-wrap items-center gap-2">
      <Button
        :label="$t('dispatch.liveTitle')"
        icon="pi pi-wifi"
        size="small"
        :severity="mode === 'live' ? 'primary' : 'secondary'"
        :outlined="mode !== 'live'"
        @click="setMode('live')"
      />
      <Button
        :label="$t('dispatch.replay')"
        icon="pi pi-history"
        size="small"
        :severity="mode === 'replay' ? 'primary' : 'secondary'"
        :outlined="mode !== 'replay'"
        @click="setMode('replay')"
      />
      <template v-if="mode === 'replay'">
        <Select
          v-model="replayAgentId"
          :options="agents"
          option-label="name"
          option-value="id"
          :placeholder="$t('clients.agent')"
          class="w-44"
          size="small"
        />
        <input
          v-model="replayDate"
          type="date"
          :max="todayInput()"
          class="rounded-md border border-line bg-card px-2 py-1.5 text-sm text-ink outline-none focus:border-primary"
          :aria-label="$t('dispatch.replayDate')"
        />
        <Button
          :label="$t('dispatch.replayLoad')"
          icon="pi pi-play"
          size="small"
          :loading="replayLoading"
          :disabled="!replayAgentId"
          @click="loadReplay"
        />
        <span v-if="replayEmpty" class="text-xs text-mute">{{ $t('dispatch.replayEmpty') }}</span>
      </template>
      <span v-else-if="loading" class="text-xs text-mute">{{ $t('common.loading') }}</span>
    </div>

    <!-- Sites referenced today with no pin: the fix lives on the location page. -->
    <p v-if="unpinned.length" class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
      <i class="pi pi-exclamation-triangle me-1" aria-hidden="true" />
      {{ $t('dispatch.unpinnedWarning', { names: unpinned.join(', ') }) }}
    </p>

    <!-- The roster: click a name to fly to their last fix (no more hunting
         dots). GPS-less / off-duty agents trail the list, greyed. -->
    <div v-if="roster.length" class="flex flex-wrap gap-1.5">
      <button
        v-for="a in roster"
        :key="a.id"
        type="button"
        class="inline-flex items-center gap-1.5 rounded-full border border-line px-2.5 py-1 text-xs font-medium transition-colors"
        :class="a.position ? 'text-ink hover:border-primary hover:text-primary-600 dark:hover:text-primary-400' : 'cursor-not-allowed text-mute opacity-60'"
        :disabled="!a.position"
        :title="a.position ? $t('dispatch.lastSeen', { time: formatDateTime(a.position.at) }) : $t('dispatch.noPosition')"
        @click="focusAgent(a)"
      >
        <span class="inline-block h-2 w-2 rounded-full" :class="rosterDot(a.status)" />
        {{ a.name }}
        <span v-if="a.position" class="num text-[10px] opacity-70">{{ minutesAgo(a.position.at) }}</span>
        <i v-else class="pi pi-eye-slash text-[10px]" aria-hidden="true" />
      </button>
    </div>

    <div class="relative" :class="expanded ? 'min-h-0 flex-1' : 'h-[65vh] min-h-[380px]'">
      <div ref="mapEl" class="absolute inset-0 rounded-xl border border-line" />
      <div
        v-if="tilesUnavailable"
        class="absolute inset-0 z-[500] flex items-center justify-center rounded-xl border border-line bg-card"
      >
        <p class="text-sm text-mute">{{ $t('dispatch.mapOffline') }}</p>
      </div>
      <!-- Full-screen toggle (above Leaflet's controls). -->
      <button
        type="button"
        class="absolute end-2 top-2 z-[1001] flex items-center justify-center rounded-lg border border-line bg-card p-2 text-ink shadow-card hover:opacity-90"
        :aria-label="expanded ? $t('dispatch.closeMap') : $t('dispatch.expandMap')"
        :title="expanded ? $t('dispatch.closeMap') : $t('dispatch.expandMap')"
        @click="toggleExpanded()"
      >
        <i :class="expanded ? 'pi pi-times' : 'pi pi-window-maximize'" aria-hidden="true" />
      </button>
    </div>

    <!-- Legend -->
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-mute">
      <span v-for="(color, status) in { available: '#10b981', en_route: '#f59e0b', on_site: '#0ea5e9', off_duty: '#94a3b8' }" :key="status" class="inline-flex items-center gap-1.5">
        <span class="inline-block h-2.5 w-2.5 rounded-full" :style="{ background: color }" />
        {{ $t(`dispatch.status${status === 'available' ? 'Available' : status === 'en_route' ? 'EnRoute' : status === 'on_site' ? 'OnSite' : 'OffDuty'}`) }}
      </span>
      <span class="inline-flex items-center gap-1.5">
        <span class="inline-block h-2.5 w-2.5 rounded-full border-2 border-amber-500 bg-amber-500/15" />
        {{ $t('dispatch.pendingSite') }}
      </span>
    </div>
  </div>
</template>
