<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import { mediaApi } from '@/features/inventory/api'
import { websiteSpaceApi } from '@/features/settings/api'
import { confirmAction, toastError } from '@/composables/useConfirm'
import { t } from '@/i18n'

// A site media library: the owner uploads photos/videos that belong to the
// SITE (not to a project) and ticks what a landing section shows. Selection
// order = display order; picking a video makes it THE hero (a video plays
// alone). `space-key` says which library this card manages ('hero' — the
// landing hero — or 'about' — the Who-we-are mosaic). The selected ids live
// in an app setting the parent owns saving; this component owns the library
// and the picking.

const props = defineProps({
  selectedIds: { type: Array, default: () => [] },
  spaceKey: { type: String, default: 'hero' }, // 'hero' | 'about'
  photosOnly: { type: Boolean, default: false },
  maxSelected: { type: Number, default: 0 }, // 0 = unlimited
  // Section-specific wording; defaults are the hero card's strings.
  dropText: { type: String, default: null },
  emptyText: { type: String, default: null },
  hintText: { type: String, default: null },
})

const emit = defineEmits(['update:selectedIds'])

const spaceId = ref(null)
const items = ref([])
const loading = ref(true)
const uploading = ref(false)
const dragging = ref(false)
const fileInput = ref(null)

// The staff thumb endpoint (cookie-authenticated) — picker UI only; the
// public site streams through its own public URLs.
const thumbUrl = (item) => item.thumb_url

async function load() {
  loading.value = true
  try {
    if (spaceId.value === null) {
      const spaces = await websiteSpaceApi.get()
      spaceId.value = props.spaceKey === 'about' ? spaces.about_id : spaces.id
    }
    const all = await mediaApi.list('website', spaceId.value)
    items.value = all.filter((m) => m.type === 'photo' || (!props.photosOnly && m.type === 'video'))
  } catch {
    toastError(t('settings.heroLibraryLoadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(load)

// Thumbs/posters appear when the optimization pipeline finishes — refresh
// gently while anything is still pending (same pattern as MediaGallery).
const hasPending = computed(() => items.value.some((m) => m.optimize_status === 'pending'))
let pollTimer = null
watch(
  hasPending,
  (pending) => {
    clearInterval(pollTimer)
    if (pending) pollTimer = setInterval(load, 10000)
  },
  { immediate: true },
)
onBeforeUnmount(() => clearInterval(pollTimer))

async function uploadFiles(files) {
  const media = files.filter(
    (f) => f.type.startsWith('image/') || (!props.photosOnly && f.type.startsWith('video/')),
  )
  if (!media.length || !spaceId.value) return
  uploading.value = true
  try {
    for (const file of media) {
      const collection = file.type.startsWith('image/') ? 'photos' : 'videos'
      await mediaApi.upload('website', spaceId.value, file, collection)
    }
    await load()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('media.uploadFailed'))
  } finally {
    uploading.value = false
  }
}

function onPick(e) {
  uploadFiles([...(e.target.files ?? [])])
  e.target.value = ''
}

function onDrop(e) {
  dragging.value = false
  uploadFiles([...(e.dataTransfer?.files ?? [])])
}

const selectionIndex = (id) => props.selectedIds.indexOf(id)

// A video hero plays alone; photos stack in picking order, capped when the
// section only has room for so many (further clicks are ignored).
function toggle(item) {
  const current = props.selectedIds
  if (current.includes(item.id)) {
    emit('update:selectedIds', current.filter((id) => id !== item.id))
    return
  }
  if (item.type === 'video') {
    emit('update:selectedIds', [item.id])
    return
  }
  const photosOnly = current.filter((id) => items.value.find((m) => m.id === id)?.type !== 'video')
  if (props.maxSelected > 0 && photosOnly.length >= props.maxSelected) return
  emit('update:selectedIds', [...photosOnly, item.id])
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
    await mediaApi.cancel(item.id)
    emit('update:selectedIds', props.selectedIds.filter((id) => id !== item.id))
    await load()
  }
}
</script>

<template>
  <div>
    <input
      ref="fileInput"
      type="file"
      :accept="photosOnly ? 'image/*' : 'image/*,video/*'"
      multiple
      class="hidden"
      @change="onPick"
    />

    <!-- Upload zone -->
    <div
      class="rounded-xl border-2 border-dashed p-5 text-center text-sm transition-colors"
      :class="dragging ? 'border-primary bg-highlight text-ink' : 'border-line text-mute'"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <i class="pi pi-cloud-upload me-1" aria-hidden="true" />
      {{ dropText || $t('settings.heroLibraryDrop') }}
      <Button
        :label="$t('settings.heroLibraryUpload')"
        icon="pi pi-upload"
        size="small"
        text
        :loading="uploading"
        @click="fileInput?.click()"
      />
    </div>

    <p v-if="loading" class="mt-3 text-sm text-mute">{{ $t('common.loading') }}</p>
    <p v-else-if="!items.length" class="mt-3 text-xs text-mute">{{ emptyText || $t('settings.heroLibraryEmpty') }}</p>

    <div v-else class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
      <div v-for="item in items" :key="item.id" class="group relative">
        <button
          type="button"
          class="relative aspect-[4/3] w-full overflow-hidden rounded-lg bg-surface-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-surface-800"
          :class="selectionIndex(item.id) !== -1 ? 'ring-2 ring-primary-500' : ''"
          @click="toggle(item)"
        >
          <img v-if="thumbUrl(item)" :src="thumbUrl(item)" alt="" class="h-full w-full object-cover" loading="lazy" />
          <span v-else class="flex h-full w-full items-center justify-center text-surface-400">
            <i :class="item.type === 'video' ? 'pi pi-video' : 'pi pi-image'" class="text-xl" aria-hidden="true" />
          </span>
          <span
            v-if="item.type === 'video'"
            class="absolute bottom-1 end-1 rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-medium text-white"
          ><i class="pi pi-play me-0.5 text-[8px]" aria-hidden="true" />{{ $t('media.videos') }}</span>
          <span
            v-if="item.optimize_status === 'pending'"
            class="absolute bottom-1 start-1 flex items-center gap-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white"
          ><i class="pi pi-spin pi-spinner text-[9px]" aria-hidden="true" />{{ $t('media.optimizing') }}</span>
          <!-- Selection order = display order -->
          <span
            v-if="selectionIndex(item.id) !== -1"
            class="num absolute start-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-primary-500 text-xs font-bold text-primary-contrast"
          >{{ selectionIndex(item.id) + 1 }}</span>
        </button>
        <button
          type="button"
          class="absolute end-1 top-1 hidden h-6 w-6 items-center justify-center rounded-full bg-black/60 text-white hover:bg-danger group-hover:flex"
          :aria-label="$t('common.remove')"
          @click.stop="remove(item)"
        >
          <i class="pi pi-times text-xs" aria-hidden="true" />
        </button>
      </div>
    </div>

    <p class="mt-2 text-xs text-mute">{{ hintText || $t('settings.heroLibraryHint') }}</p>
  </div>
</template>
