<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import SectionCard from '@/components/ui/SectionCard.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { MEDIA_COLLECTIONS, mediaDownloadUrl, mediaFileUrl } from '@/features/inventory/api'
import MediaViewer from '@/features/inventory/components/MediaViewer.vue'
import AttachSheet from '@/components/ui/AttachSheet.vue'
import { useMediaStore } from '@/features/inventory/mediaStore'
import { confirmAction } from '@/composables/useConfirm'
import { isNativeApp } from '@/utils/nativeApp'

const props = defineProps({
  mediableType: { type: String, required: true }, // 'locations' | 'units'
  mediableId: { type: [String, Number], required: true },
  canManage: { type: Boolean, default: false },
})

const media = useMediaStore()
const collections = MEDIA_COLLECTIONS
const activeTab = ref(collections[0].key)
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

// Only the active tab's assets; `byCollection` keeps the gallery order.
const tabItems = computed(() => media.byCollection[activeTab.value] ?? [])
const activeLabel = computed(() => collections.find((c) => c.key === activeTab.value)?.label ?? '')
const countFor = (key) => (media.byCollection[key] ?? []).length

onMounted(() => media.load(props.mediableType, props.mediableId))

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
      title: `Remove "${item.original_name}"?`,
      text: 'The file is kept but hidden.',
      confirmText: 'Remove',
      danger: true,
    })
  ) {
    media.remove(item.id)
  }
}
</script>

<template>
  <SectionCard title="Media" icon="pi pi-images">
    <template #actions>
      <Button
        v-if="canManage"
        :label="`Upload to ${activeLabel}`"
        icon="pi pi-upload"
        size="small"
        @click="openUpload"
      />
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
      accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
      multiple
      class="hidden"
      @change="onPick"
    />
    <AttachSheet :open="attachOpen" @close="attachOpen = false" @pick="onAttachPick" />

    <!-- Category tabs (mirror the backend MediaCollection enum) -->
    <nav class="-mt-1 flex flex-wrap gap-1 border-b border-line">
      <button
        v-for="c in collections"
        :key="c.key"
        type="button"
        class="min-h-[40px] px-3 py-2 text-sm transition-colors"
        :class="
          activeTab === c.key
            ? '-mb-px border-b-2 border-primary font-semibold text-ink'
            : 'text-mute hover:text-ink'
        "
        @click="activeTab = c.key"
      >
        {{ c.label }}
        <span
          v-if="countFor(c.key)"
          class="num ms-1 rounded-full bg-surface-100 px-1.5 text-xs text-mute dark:bg-surface-800"
        >
          {{ countFor(c.key) }}
        </span>
      </button>
    </nav>

    <!-- Drag-drop upload zone — targets the active tab -->
    <div
      v-if="canManage"
      class="mt-4 rounded-xl border-2 border-dashed p-6 text-center text-sm transition-colors"
      :class="dragging ? 'border-primary bg-highlight text-ink' : 'border-line text-mute'"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <i class="pi pi-cloud-upload me-1" aria-hidden="true" />
      Drag files here to add to <span class="font-medium text-ink">{{ activeLabel }}</span>
      <span v-if="media.busy" class="block text-mute">Uploading…</span>
    </div>

    <p v-if="media.loading" class="py-4 text-center text-sm text-mute">Loading…</p>

    <div v-else class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      <div
        v-for="(item, i) in tabItems"
        :key="item.id"
        class="group overflow-hidden rounded-xl border border-line bg-card transition-shadow hover:shadow-pop"
      >
        <button
          type="button"
          class="flex aspect-video w-full items-center justify-center bg-surface-100 dark:bg-surface-800"
          @click="viewing = item"
        >
          <img
            v-if="item.type === 'photo'"
            :src="mediaFileUrl(item.id)"
            :alt="item.original_name"
            loading="lazy"
            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
          />
          <i
            v-else
            :class="typeIcon[item.type] ?? 'pi pi-file'"
            class="text-3xl text-mute"
            :aria-label="item.type"
          />
        </button>
        <div class="flex items-center justify-between gap-1 px-2 py-1.5">
          <span class="truncate text-xs text-ink" :title="item.original_name">
            {{ item.original_name }}
            <span v-if="item.version > 1" class="text-mute">v{{ item.version }}</span>
          </span>
          <div class="flex shrink-0 items-center">
            <a
              :href="mediaDownloadUrl(item.id)"
              :download="item.original_name"
              class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink"
              aria-label="Download"
            >
              <i class="pi pi-download" aria-hidden="true" />
            </a>
            <template v-if="canManage">
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink disabled:opacity-30"
                :disabled="i === 0 || media.busy"
                aria-label="Move up"
                @click="media.move(item.id, -1)"
              >
                <i class="pi pi-arrow-up" aria-hidden="true" />
              </button>
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink disabled:opacity-30"
                :disabled="i === tabItems.length - 1 || media.busy"
                aria-label="Move down"
                @click="media.move(item.id, 1)"
              >
                <i class="pi pi-arrow-down" aria-hidden="true" />
              </button>
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-ink"
                aria-label="Replace"
                @click="startReplace(item.id)"
              >
                <i class="pi pi-refresh" aria-hidden="true" />
              </button>
              <button
                class="flex min-h-[32px] min-w-[28px] items-center justify-center text-xs text-mute hover:text-danger"
                aria-label="Remove"
                @click="remove(item)"
              >
                <i class="pi pi-times" aria-hidden="true" />
              </button>
            </template>
          </div>
        </div>
      </div>

      <div v-if="!tabItems.length" class="col-span-full">
        <EmptyState icon="pi pi-images" :title="`No ${activeLabel.toLowerCase()} yet`" />
      </div>
    </div>

    <MediaViewer
      v-if="viewing"
      :media="viewing"
      :items="tabItems"
      @navigate="viewing = $event"
      @close="viewing = null"
    />
  </SectionCard>
</template>
