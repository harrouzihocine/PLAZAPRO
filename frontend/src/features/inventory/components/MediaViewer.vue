<script setup>
import { mediaFileUrl, mediaPreviewUrl } from '@/features/inventory/api'

defineProps({ media: { type: Object, required: true } })
defineEmits(['close'])
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-2 sm:p-6"
    @click.self="$emit('close')"
  >
    <div class="relative flex max-h-full w-full max-w-5xl flex-col rounded-token bg-surface">
      <div class="flex items-center justify-between border-b border-border p-3">
        <span class="truncate text-sm font-medium">{{ media.original_name }}</span>
        <button class="min-h-[44px] min-w-[44px] text-lg" aria-label="Close" @click="$emit('close')">
          ✕
        </button>
      </div>

      <div class="flex min-h-[50vh] flex-1 items-center justify-center overflow-auto p-2">
        <!-- Everything is viewed inside the app — nothing is downloaded. -->
        <img
          v-if="media.type === 'photo'"
          :src="mediaFileUrl(media.id)"
          :alt="media.original_name"
          class="max-h-[80vh] max-w-full object-contain"
        />

        <video
          v-else-if="media.type === 'video'"
          :src="mediaFileUrl(media.id)"
          controls
          class="max-h-[80vh] max-w-full"
        ></video>

        <iframe
          v-else-if="media.type === 'pdf'"
          :src="mediaFileUrl(media.id)"
          class="h-[80vh] w-full"
          title="Document preview"
        ></iframe>

        <!-- Office docs (presentations, Word, spreadsheets) are shown via their
             LibreOffice-rendered PDF preview — nothing is downloaded. -->
        <template v-else-if="['pptx', 'docx', 'xlsx'].includes(media.type)">
          <iframe
            v-if="media.preview_status === 'ready'"
            :src="mediaPreviewUrl(media.id)"
            class="h-[80vh] w-full"
            title="Document preview"
          ></iframe>
          <p v-else-if="media.preview_status === 'pending'" class="p-8 text-center opacity-70">
            Converting document for preview… check back shortly.
          </p>
          <p v-else class="p-8 text-center opacity-70">Preview unavailable for this file.</p>
        </template>
      </div>
    </div>
  </div>
</template>
