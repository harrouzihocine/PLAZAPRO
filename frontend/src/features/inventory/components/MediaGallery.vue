<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  MEDIA_COLLECTIONS,
  SHAREABLE_COLLECTIONS,
  mediaCollectionLabel,
  mediaDownloadUrl,
} from '@/features/inventory/api'
import MediaViewer from '@/features/inventory/components/MediaViewer.vue'
import ShareMediaModal from '@/features/inventory/components/ShareMediaModal.vue'
import AttachSheet from '@/components/ui/AttachSheet.vue'
import { useMediaStore } from '@/features/inventory/mediaStore'
import { useAuthStore } from '@/features/settings/store'
import { confirmAction, promptText, toastSuccess } from '@/composables/useConfirm'
import { isNativeApp } from '@/utils/nativeApp'
import { t } from '@/i18n'

const props = defineProps({
  mediableType: { type: String, required: true }, // 'locations' | 'units'
  mediableId: { type: [String, Number], required: true },
  canManage: { type: Boolean, default: false },
})

const media = useMediaStore()
const collections = MEDIA_COLLECTIONS
const activeTab = ref(collections[0])
const viewing = ref(null)
const dragging = ref(false)
const fileInput = ref(null)
const replaceInput = ref(null)
const replacingId = ref(null)

// In the Android shell, "Upload" opens a WhatsApp-style source sheet (camera /
// video / gallery multi-select / document) instead of the bare file manager.
// Each source is its own hidden input: `capture` opens the camera app
// directly; the image/video accept combo opens Android's photo-picker grid.
const isNative = isNativeApp()
const attachOpen = ref(false)
const cameraPhotoInput = ref(null)
const cameraVideoInput = ref(null)
const libraryInput = ref(null)
const documentInput = ref(null)

function openUpload() {
  if (isNative) attachOpen.value = true
  else fileInput.value?.click()
}

function onAttachPick(kind) {
  attachOpen.value = false
  const input = {
    'camera-photo': cameraPhotoInput,
    'camera-video': cameraVideoInput,
    library: libraryInput,
    document: documentInput,
  }[kind]
  input?.value?.click()
}

const typeIcon = {
  photo: 'pi pi-image',
  video: 'pi pi-video',
  pdf: 'pi pi-file-pdf',
  pptx: 'pi pi-chart-bar',
  docx: 'pi pi-file-word',
  xlsx: 'pi pi-file-excel',
}

// Only these collections can reach the public showcase, so only they carry
// the globe toggle (documents/presentations are internal by construction).
const canTogglePublic = (item) => props.canManage && SHAREABLE_COLLECTIONS.includes(item.collection)

// ── Send-to-client selection ─────────────────────────────────────────────
// Multi-select across the shareable tabs (photos/videos/plans), then hand the
// picked ids to ShareMediaModal (own-client search → wa.me deep link). The
// selection Set is reassigned on every toggle so computeds stay reactive.
const auth = useAuthStore()
const SHARE_MAX = 40 // mirrors the media_ids cap in StoreMediaShareRequest
const selecting = ref(false)
const selectedIds = ref(new Set())
const shareOpen = ref(false)

// Sharing needs a client to address — media visibility alone isn't enough.
const canShare = computed(() => auth.can('clients.view'))
const shareableTab = computed(() => SHAREABLE_COLLECTIONS.includes(activeTab.value))
const hasShareable = computed(() =>
  media.items.some((m) => SHAREABLE_COLLECTIONS.includes(m.collection)),
)
const isSelected = (item) => selectedIds.value.has(item.id)

function startSelecting() {
  // Entering from an internal tab jumps to the first shareable one with items.
  if (!shareableTab.value) {
    activeTab.value = SHAREABLE_COLLECTIONS.find((c) => countFor(c)) ?? SHAREABLE_COLLECTIONS[0]
  }
  selecting.value = true
}

function stopSelecting() {
  selecting.value = false
  selectedIds.value = new Set()
}

function toggleSelected(item) {
  const next = new Set(selectedIds.value)
  if (next.has(item.id)) next.delete(item.id)
  else if (next.size < SHARE_MAX) next.add(item.id)
  selectedIds.value = next
}

function onTileClick(item) {
  if (selecting.value && shareableTab.value) toggleSelected(item)
  else viewing.value = item
}

function onShareDone(kind) {
  shareOpen.value = false
  // Copy already toasts from the clipboard composable.
  if (kind === 'whatsapp') toastSuccess(t('mediaShare.sent'))
  stopSelecting()
}

// Only the active tab's assets; `byCollection` keeps the gallery order.
const tabItems = computed(() => media.byCollection[activeTab.value] ?? [])
const activeLabel = computed(() => mediaCollectionLabel(activeTab.value))
const countFor = (key) => (media.byCollection[key] ?? []).length

onMounted(() => media.load(props.mediableType, props.mediableId))

// While anything is still in the optimization or preview pipeline (a 4K video
// or a big deck can take minutes), refresh gently so posters/thumbnails/slides
// appear on their own.
const hasPending = computed(() =>
  media.items.some((m) => m.optimize_status === 'pending' || m.preview_status === 'pending'),
)
let pollTimer = null
watch(
  hasPending,
  (pending) => {
    clearInterval(pollTimer)
    if (pending) pollTimer = setInterval(() => media.refresh(), 10000)
  },
  { immediate: true },
)
onBeforeUnmount(() => clearInterval(pollTimer))

// mm:ss duration badge for optimized videos.
function formatDuration(seconds) {
  const m = Math.floor(seconds / 60)
  const s = String(seconds % 60).padStart(2, '0')
  return `${m}:${s}`
}

function onDrop(e) {
  dragging.value = false
  if (!props.canManage) return
  const files = [...(e.dataTransfer?.files ?? [])]
  if (files.length) media.uploadMany(files, activeTab.value)
}

function onPick(e) {
  const files = [...(e.target.files ?? [])]
  if (files.length) media.uploadMany(files, activeTab.value)
  e.target.value = ''
}

function startReplace(id) {
  replacingId.value = id
  replaceInput.value?.click()
}

function onReplacePick(e) {
  const file = e.target.files?.[0]
  if (file && replacingId.value) media.replace(replacingId.value, file)
  e.target.value = ''
  replacingId.value = null
}

async function remove(item) {
  if (
    await confirmAction({
      title: t('media.removeTitle', { name: item.original_name }),
      text: t('media.removeText'),
      confirmText: t('common.remove'),
      danger: true,
    })
  ) {
    media.remove(item.id)
  }
}

async function rename(item) {
  const name = await promptText({
    title: t('media.renameTitle'),
    value: item.original_name,
    confirmText: t('media.rename'),
    selectBasename: true,
  })
  if (name && name !== item.original_name) media.rename(item.id, name)
}
</script>

<template>
  <SectionCard :title="$t('inventory.tabMedia')" icon="pi pi-images">
    <template #actions>
      <template v-if="selecting">
        <span class="num self-center text-sm text-mute">
          {{ $t('mediaShare.count', { n: selectedIds.size }) }}
        </span>
        <Button
          :label="$t('mediaShare.send')"
          icon="pi pi-whatsapp"
          size="small"
          :disabled="!selectedIds.size"
          @click="shareOpen = true"
        />
        <Button
          :label="$t('common.cancel')"
          size="small"
          severity="secondary"
          text
          @click="stopSelecting"
        />
      </template>
      <template v-else>
        <Button
          v-if="canShare && hasShareable"
          :label="$t('mediaShare.action')"
          icon="pi pi-whatsapp"
          size="small"
          severity="secondary"
          outlined
          @click="startSelecting"
        />
        <Button
          v-if="canManage"
          :label="$t('media.uploadTo', { collection: activeLabel })"
          icon="pi pi-upload"
          size="small"
          @click="openUpload"
        />
      </template>
    </template>
    <input ref="fileInput" type="file" multiple class="hidden" @change="onPick" />
    <input ref="replaceInput" type="file" class="hidden" @change="onReplacePick" />
    <!-- Native attach sources (AttachSheet drives which one is clicked) -->
    <input
      ref="cameraPhotoInput"
      type="file"
      accept="image/*"
      capture="environment"
      class="hidden"
      @change="onPick"
    />
    <input
      ref="cameraVideoInput"
      type="file"
      accept="video/*"
      capture="environment"
      class="hidden"
      @change="onPick"
    />
    <input
      ref="libraryInput"
      type="file"
      accept="image/*,video/*"
      multiple
      class="hidden"
      @change="onPick"
    />
    <input
      ref="documentInput"
      type="file"
      accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pps,.pptx,.ppsx,.pptm,.odp,.odt,.ods"
      multiple
      class="hidden"
      @change="onPick"
    />
    <AttachSheet :open="attachOpen" @close="attachOpen = false" @pick="onAttachPick" />

    <!-- Category tabs (mirror the backend MediaCollection enum) -->
    <nav class="-mt-1 flex flex-wrap gap-1 border-b border-line">
      <button
        v-for="c in collections"
        :key="c"
        type="button"
        class="min-h-[40px] px-3 py-2 text-sm transition-colors"
        :class="
          activeTab === c
            ? '-mb-px border-b-2 border-primary font-semibold text-ink'
            : 'text-mute hover:text-ink'
        "
        @click="activeTab = c"
      >
        {{ mediaCollectionLabel(c) }}
        <span
          v-if="countFor(c)"
          class="num ms-1 rounded-full bg-surface-100 px-1.5 text-xs text-mute dark:bg-surface-800"
        >
          {{ countFor(c) }}
        </span>
      </button>
    </nav>

    <!-- Selecting on an internal tab: nothing here can be sent to a client. -->
    <p v-if="selecting && !shareableTab" class="mt-3 text-sm text-mute">
      <i class="pi pi-info-circle me-1" aria-hidden="true" />
      {{ $t('mediaShare.tabNotShareable') }}
    </p>

    <!-- Drag-drop upload zone — targets the active tab -->
    <div
      v-if="canManage && !selecting"
      class="mt-4 rounded-xl border-2 border-dashed p-6 text-center text-sm transition-colors"
      :class="dragging ? 'border-primary bg-highlight text-ink' : 'border-line text-mute'"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <i class="pi pi-cloud-upload me-1" aria-hidden="true" />
      {{ $t('media.dragHere') }} <span class="font-medium text-ink">{{ activeLabel }}</span>
      <span v-if="media.busy" class="block text-mute">{{ $t('media.uploading') }}</span>
    </div>

    <p v-if="media.loading" class="py-4 text-center text-sm text-mute">{{ $t('common.loading') }}</p>

    <div v-else class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      <div
        v-for="(item, i) in tabItems"
        :key="item.id"
        class="group overflow-hidden rounded-xl border border-line bg-card transition-shadow hover:shadow-pop"
      >
        <button
          type="button"
          class="relative flex aspect-video w-full items-center justify-center bg-surface-100 dark:bg-surface-800"
          :aria-pressed="selecting && shareableTab ? isSelected(item) : undefined"
          @click="onTileClick(item)"
        >
          <!-- Select-mode affordances: check badge + tint on the chosen ones -->
          <template v-if="selecting && shareableTab">
            <span
              class="absolute start-1.5 top-1.5 z-10 flex h-6 w-6 items-center justify-center rounded-full border-2 text-xs transition-colors"
              :class="isSelected(item)
                ? 'border-primary-500 bg-primary-500 text-primary-contrast'
                : 'border-white/90 bg-black/30 text-transparent'"
              aria-hidden="true"
            >
              <i class="pi pi-check" />
            </span>
            <span
              v-if="isSelected(item)"
              class="absolute inset-0 z-[5] bg-primary-500/25 ring-2 ring-inset ring-primary-500"
              aria-hidden="true"
            />
          </template>
          <!-- Photos stream the WebP thumbnail (server falls back to the
               original until the optimization job has produced one). thumb_url
               carries a version param, so when optimization finishes the poll
               swaps in the small file automatically. -->
          <img
            v-if="item.type === 'photo'"
            :src="item.thumb_url"
            :alt="item.original_name"
            loading="lazy"
            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
          />
          <!-- Videos show their poster frame + play overlay once optimized. -->
          <template v-else-if="item.type === 'video' && item.thumb_url">
            <img
              :src="item.thumb_url"
              :alt="item.original_name"
              loading="lazy"
              class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
            />
            <span
              class="absolute inset-0 flex items-center justify-center"
              aria-hidden="true"
            >
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-black/50 text-white">
                <i class="pi pi-play ms-0.5" />
              </span>
            </span>
            <span
              v-if="item.duration_seconds"
              class="num absolute bottom-1 end-1 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white"
            >
              {{ formatDuration(item.duration_seconds) }}
            </span>
          </template>
          <!-- Presentations show their first slide once rendered (Drive-style),
               with a slide-count badge. -->
          <template v-else-if="item.type === 'pptx' && item.thumb_url">
            <img
              :src="item.thumb_url"
              :alt="item.original_name"
              loading="lazy"
              class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
            />
            <span
              v-if="item.slide_count"
              class="num absolute bottom-1 end-1 flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white"
            >
              <i class="pi pi-clone text-[10px]" aria-hidden="true" />
              {{ item.slide_count }}
            </span>
          </template>
          <template v-else>
            <i
              :class="typeIcon[item.type] ?? 'pi pi-file'"
              class="text-3xl text-mute"
              :aria-label="item.type"
            />
            <span
              v-if="item.optimize_status === 'pending' || item.preview_status === 'pending'"
              class="absolute bottom-1 start-1 flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white"
            >
              <i class="pi pi-spin pi-spinner text-[10px]" aria-hidden="true" />
              {{ $t('media.optimizing') }}
            </span>
          </template>
        </button>
        <div class="flex items-center justify-between gap-1 px-2 py-1.5">
          <span class="truncate text-xs text-ink" :title="item.original_name">
            {{ item.original_name }}
            <span v-if="item.version > 1" class="text-mute">v{{ item.version }}</span>
          </span>
          <div v-if="!selecting" class="flex shrink-0 items-center">
            <a
              :href="mediaDownloadUrl(item.id)"
              :download="item.original_name"
              class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink"
:aria-label="$t('common.download')"
            >
              <i class="pi pi-download" aria-hidden="true" />
            </a>
            <template v-if="canManage">
              <button
                v-if="canTogglePublic(item)"
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs"
                :class="item.is_public ? 'text-mute hover:text-ink' : 'text-warning hover:text-ink'"
                :aria-label="item.is_public ? $t('media.hideFromWebsite') : $t('media.showOnWebsite')"
                :title="item.is_public ? $t('media.hideFromWebsite') : $t('media.showOnWebsite')"
                @click="media.setPublic(item.id, !item.is_public)"
              >
                <i :class="item.is_public ? 'pi pi-globe' : 'pi pi-eye-slash'" aria-hidden="true" />
              </button>
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink"
                :aria-label="$t('media.rename')"
                @click="rename(item)"
              >
                <i class="pi pi-pencil" aria-hidden="true" />
              </button>
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink disabled:opacity-30"
                :disabled="i === 0 || media.busy"
:aria-label="$t('media.moveUp')"
                @click="media.move(item.id, -1)"
              >
                <i class="pi pi-arrow-up" aria-hidden="true" />
              </button>
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink disabled:opacity-30"
                :disabled="i === tabItems.length - 1 || media.busy"
:aria-label="$t('media.moveDown')"
                @click="media.move(item.id, 1)"
              >
                <i class="pi pi-arrow-down" aria-hidden="true" />
              </button>
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink"
:aria-label="$t('media.replace')"
                @click="startReplace(item.id)"
              >
                <i class="pi pi-refresh" aria-hidden="true" />
              </button>
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-danger"
:aria-label="$t('common.remove')"
                @click="remove(item)"
              >
                <i class="pi pi-times" aria-hidden="true" />
              </button>
            </template>
          </div>
        </div>
      </div>

      <div v-if="!tabItems.length" class="col-span-full">
        <EmptyState icon="pi pi-images" :title="$t('media.emptyCollection', { collection: activeLabel })" />
      </div>
    </div>

    <MediaViewer
      v-if="viewing"
      :media="viewing"
      :items="tabItems"
      @navigate="viewing = $event"
      @close="viewing = null"
    />

    <ShareMediaModal
      v-if="shareOpen"
      :mediable-type="mediableType"
      :mediable-id="mediableId"
      :media-ids="[...selectedIds]"
      @close="shareOpen = false"
      @done="onShareDone"
    />
  </SectionCard>
</template>
