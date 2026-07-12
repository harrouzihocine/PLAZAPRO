<script setup>
import { ref } from 'vue'
import Button from 'primevue/button'
import { track } from '../composables/useTracker'
import { t } from '@/i18n'

// Share the current page: native share sheet where the platform has one
// (phones), copy-link with inline feedback elsewhere. Counts as share_click.

const props = defineProps({
  title: { type: String, default: '' },
  locationId: { type: [String, Number], default: null },
})

const copied = ref(false)

async function share() {
  track('share_click', { location_id: props.locationId ? Number(props.locationId) : null })
  const url = window.location.href
  if (navigator.share) {
    try {
      await navigator.share({ title: props.title || document.title, url })
    } catch {
      /* visitor dismissed the sheet */
    }
    return
  }
  try {
    await navigator.clipboard.writeText(url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    /* clipboard blocked — nothing sensible to do */
  }
}
</script>

<template>
  <Button
    :label="copied ? t('showcase.share.copied') : t('showcase.share.share')"
    :icon="copied ? 'pi pi-check' : 'pi pi-share-alt'"
    severity="secondary"
    outlined
    rounded
    class="!border-white/40 !text-white hover:!bg-white/10"
    @click="share"
  />
</template>
