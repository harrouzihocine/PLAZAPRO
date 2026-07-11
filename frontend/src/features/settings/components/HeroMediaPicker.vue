<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { locationsApi, mediaApi } from '@/features/inventory/api'

// Visual picker for the landing-page hero: choose a PUBLISHED project, see
// its photos & videos as thumbnails, click one. Replaces the raw media-ID
// input nobody could be expected to fill by hand. The chosen id is stored in
// the website_hero_media_id app setting; the public API re-validates it, so
// this picker only OFFERS valid choices, it doesn't have to enforce them.

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
})

const emit = defineEmits(['update:modelValue'])

const locations = ref([])
const locationId = ref(null)
const media = ref([])
const loadingMedia = ref(false)

// The staff thumb endpoint (cookie-authenticated) — for the picker UI only;
// the public site streams through its own public URLs.
const thumbUrl = (id) => `/api/v1/media/${id}/thumb`

const published = computed(() => locations.value.filter((l) => l.is_published))
const selectedId = computed(() => (props.modelValue ? Number(props.modelValue) : null))

onMounted(async () => {
  try {
    locations.value = await locationsApi.list()
  } catch {
    /* the section still renders; the hint explains publishing first */
  }
})

watch(locationId, async (id) => {
  media.value = []
  if (!id) return
  loadingMedia.value = true
  try {
    const items = await mediaApi.list('locations', id)
    media.value = items.filter((m) => m.type === 'photo' || m.type === 'video')
  } finally {
    loadingMedia.value = false
  }
})

function pick(item) {
  emit('update:modelValue', item.id === selectedId.value ? '' : item.id)
}
</script>

<template>
  <div>
    <span class="text-sm font-medium text-ink">{{ $t('settings.websiteHeroLabel') }}</span>

    <!-- Current pick -->
    <div v-if="selectedId" class="mt-2 flex items-center gap-3 rounded-lg border border-line p-2">
      <img :src="thumbUrl(selectedId)" alt="" class="h-14 w-20 rounded-md object-cover" />
      <span class="num text-sm text-mute">#{{ selectedId }}</span>
      <Button
        icon="pi pi-times"
        size="small"
        text
        rounded
        severity="danger"
        class="ms-auto"
        :aria-label="$t('settings.websiteHeroClear')"
        @click="emit('update:modelValue', '')"
      />
    </div>

    <p v-if="!published.length" class="mt-2 text-xs text-mute">
      {{ $t('settings.websiteHeroNoProjects') }}
    </p>

    <template v-else>
      <Select
        v-model="locationId"
        :options="published.map((l) => ({ value: l.id, label: l.name }))"
        option-label="label"
        option-value="value"
        show-clear
        :placeholder="$t('settings.websiteHeroPickProject')"
        class="mt-2"
        fluid
      />

      <p v-if="loadingMedia" class="mt-3 text-sm text-mute">{{ $t('common.loading') }}</p>
      <p v-else-if="locationId && !media.length" class="mt-3 text-xs text-mute">
        {{ $t('settings.websiteHeroNoMedia') }}
      </p>

      <div v-else-if="media.length" class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
        <button
          v-for="item in media"
          :key="item.id"
          type="button"
          class="group relative aspect-[4/3] overflow-hidden rounded-lg bg-surface-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-surface-800"
          :class="item.id === selectedId ? 'ring-2 ring-primary-500' : ''"
          @click="pick(item)"
        >
          <img v-if="item.thumb_url" :src="item.thumb_url" alt="" class="h-full w-full object-cover" loading="lazy" />
          <span v-else class="flex h-full w-full items-center justify-center text-surface-400">
            <i class="pi pi-video text-xl" aria-hidden="true" />
          </span>
          <span
            v-if="item.type === 'video'"
            class="absolute bottom-1 end-1 rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-medium text-white"
          ><i class="pi pi-play me-0.5 text-[8px]" aria-hidden="true" />{{ $t('media.videos') }}</span>
          <span
            v-if="item.id === selectedId"
            class="absolute start-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-primary-500 text-primary-contrast"
          ><i class="pi pi-check text-xs" aria-hidden="true" /></span>
        </button>
      </div>
    </template>

    <p class="mt-2 text-xs text-mute">{{ $t('settings.websiteHeroHint') }}</p>
  </div>
</template>
