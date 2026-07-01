<script setup>
import { onMounted, ref } from 'vue'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import { mediaFileUrl } from '@/features/inventory/api'
import MediaViewer from '@/features/inventory/components/MediaViewer.vue'
import { useMediaStore } from '@/features/inventory/mediaStore'

const props = defineProps({
  mediableType: { type: String, required: true }, // 'locations' | 'units'
  mediableId: { type: [String, Number], required: true },
  canManage: { type: Boolean, default: false },
})

const media = useMediaStore()
const viewing = ref(null)
const dragging = ref(false)
const fileInput = ref(null)
const replaceInput = ref(null)
const replacingId = ref(null)

const typeIcon = { photo: '🖼', video: '▶', pdf: '📄', pptx: '📊' }

onMounted(() => media.load(props.mediableType, props.mediableId))

function onDrop(e) {
  dragging.value = false
  if (!props.canManage) return
  const files = [...(e.dataTransfer?.files ?? [])]
  if (files.length) media.uploadMany(files)
}

function onPick(e) {
  const files = [...(e.target.files ?? [])]
  if (files.length) media.uploadMany(files)
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

function remove(item) {
  if (window.confirm(`Remove "${item.original_name}"? The file is kept but hidden.`)) {
    media.remove(item.id)
  }
}
</script>

<template>
  <BaseCard>
    <div class="flex items-center justify-between">
      <h2 class="font-semibold">Media</h2>
      <BaseButton v-if="canManage" variant="ghost" @click="fileInput?.click()">Upload</BaseButton>
    </div>
    <input ref="fileInput" type="file" multiple class="hidden" @change="onPick" />
    <input ref="replaceInput" type="file" class="hidden" @change="onReplacePick" />

    <p v-if="media.error" class="mt-2 text-sm text-danger">{{ media.error }}</p>

    <!-- Drag-drop upload zone -->
    <div
      v-if="canManage"
      class="mt-3 rounded-token border-2 border-dashed p-6 text-center text-sm transition-colors"
      :class="dragging ? 'border-primary bg-bg' : 'border-border'"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      Drag photos, videos, PDFs or presentations here
      <span v-if="media.busy" class="block opacity-70">Uploading…</span>
    </div>

    <p v-if="media.loading" class="py-4 text-center text-sm opacity-60">Loading…</p>

    <div v-else class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      <div
        v-for="(item, i) in media.items"
        :key="item.id"
        class="overflow-hidden rounded-token border border-border"
      >
        <button
          type="button"
          class="flex aspect-video w-full items-center justify-center bg-bg"
          @click="viewing = item"
        >
          <img
            v-if="item.type === 'photo'"
            :src="mediaFileUrl(item.id)"
            :alt="item.original_name"
            class="h-full w-full object-cover"
          />
          <span v-else class="text-3xl" :aria-label="item.type">{{ typeIcon[item.type] }}</span>
        </button>
        <div class="flex items-center justify-between gap-1 p-1">
          <span class="truncate px-1 text-xs" :title="item.original_name">
            {{ item.original_name }}
            <span v-if="item.version > 1" class="opacity-60">v{{ item.version }}</span>
          </span>
          <div v-if="canManage" class="flex shrink-0">
            <button class="min-h-[36px] px-1 text-xs disabled:opacity-30" :disabled="i === 0 || media.busy" aria-label="Move up" @click="media.move(item.id, -1)">↑</button>
            <button class="min-h-[36px] px-1 text-xs disabled:opacity-30" :disabled="i === media.items.length - 1 || media.busy" aria-label="Move down" @click="media.move(item.id, 1)">↓</button>
            <button class="min-h-[36px] px-1 text-xs" aria-label="Replace" @click="startReplace(item.id)">⟳</button>
            <button class="min-h-[36px] px-1 text-xs" aria-label="Remove" @click="remove(item)">✕</button>
          </div>
        </div>
      </div>

      <p v-if="!media.items.length" class="col-span-full py-4 text-center text-sm opacity-60">
        No media yet.
      </p>
    </div>

    <MediaViewer v-if="viewing" :media="viewing" @close="viewing = null" />
  </BaseCard>
</template>
