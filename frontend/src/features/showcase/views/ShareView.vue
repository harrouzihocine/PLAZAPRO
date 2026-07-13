<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import { useShowcaseStore } from '../store'
import { useSeoMeta } from '../composables/useSeoMeta'
import { publicApi } from '../publicApi'
import PublicGallery from '../components/PublicGallery.vue'

// The landing page of a WhatsApp media share: the client taps the link their
// agent sent and gets the hand-picked gallery (photos / videos / plans tabs,
// carousel + lightbox — all PublicGallery). Unknown and expired tokens read
// the same ("link expired") with a nudge toward the public projects.
const props = defineProps({ token: { type: String, required: true } })

const showcase = useShowcaseStore()
const { t } = useI18n()

const state = ref('loading') // loading | ready | gone
const share = ref(null)

onMounted(async () => {
  try {
    const { data } = await publicApi.share(props.token)
    share.value = data.data
    state.value = share.value.media.length ? 'ready' : 'gone'
  } catch {
    state.value = 'gone'
  }
})

useSeoMeta(() => ({
  title: `${share.value?.title ?? t('showcase.sharePage.title')} — ${showcase.company.name || 'PLAZA PRO'}`,
}))
</script>

<template>
  <div class="mx-auto max-w-6xl px-4 pb-20 pt-28 sm:px-6">
    <p v-if="state === 'loading'" class="py-24 text-center text-mute">
      <i class="pi pi-spin pi-spinner me-2" aria-hidden="true" />{{ $t('showcase.sharePage.loading') }}
    </p>

    <template v-else-if="state === 'ready'">
      <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
        {{ $t('showcase.sharePage.kicker') }}
      </p>
      <h1 class="mt-2 text-3xl font-bold text-ink sm:text-4xl">{{ share.title }}</h1>
      <p class="mt-3 text-mute">
        {{ $t('showcase.sharePage.subtitle', { n: share.media.length }) }}
      </p>

      <div class="mt-10">
        <PublicGallery :media="share.media" compact />
      </div>
    </template>

    <!-- Expired, revoked-by-time or plain wrong link — same face for all. -->
    <div v-else class="mx-auto max-w-md py-24 text-center">
      <i class="pi pi-clock text-4xl text-mute" aria-hidden="true" />
      <h1 class="mt-4 text-2xl font-bold text-ink">{{ $t('showcase.sharePage.goneTitle') }}</h1>
      <p class="mt-2 text-mute">{{ $t('showcase.sharePage.goneBody') }}</p>
      <Button
        :label="$t('showcase.sharePage.browse')"
        class="mt-6"
        @click="$router.push({ name: 'showcase.projects' })"
      />
    </div>
  </div>
</template>
