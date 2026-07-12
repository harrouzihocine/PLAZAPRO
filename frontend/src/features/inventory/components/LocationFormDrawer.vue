<script setup>
import { computed, reactive, ref, watch } from 'vue'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import Slider from 'primevue/slider'
import ToggleSwitch from 'primevue/toggleswitch'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseMultiSelect from '@/components/base/BaseMultiSelect.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import CoverImageUpload from '@/features/inventory/components/CoverImageUpload.vue'
import LocationMap from '@/features/inventory/components/LocationMap.vue'
import { gtmPriorityOptions } from '@/features/inventory/api'
import { googleMapsUrl } from '@/features/inventory/googleMaps'
import { useLocationsStore } from '@/features/inventory/locationsStore'
import { useDynamicList, itemLabel } from '@/composables/useDynamicList'
import { useWilayas, useCommunes } from '@/composables/useGeography'
import { copyToClipboard } from '@/composables/useClipboard'
import { todayInput } from '@/utils/format'
import { isRTL } from '@/i18n'

// The create/edit form for a project (location), shared by the projects list
// and a single project's detail page. Pass `location` to edit it, or null to
// create; opening is driven by `visible`. Emits `saved` after a successful write.
const props = defineProps({
  visible: { type: Boolean, default: false },
  location: { type: Object, default: null },
})
const emit = defineEmits(['update:visible', 'saved'])

const store = useLocationsStore()
const { wilayas } = useWilayas()
const { items: projectTypes } = useDynamicList('project_types')
const { items: contractTypes } = useDynamicList('contract_types')
// Financing / payment options this project offers buyers (multi-select).
const { items: projectPaymentMethods } = useDynamicList('project_payment_methods')
const { communes: formCommunes, load: loadFormCommunes } = useCommunes()

const blank = {
  name: '',
  code: '',
  wilaya_id: '',
  commune_id: '',
  type_id: '',
  contract_type_id: '',
  payment_method_ids: [],
  address: '',
  description: '',
  expected_delivery_date: '',
  gtm_priority: 'medium',
  latitude: null,
  longitude: null,
  cover_media_id: null,
  cover_focus_x: 50,
  cover_focus_y: 50,
  // Public-website controls (the /plaza showcase).
  is_published: false,
  show_prices: true,
  show_availability: true,
  marketing_tagline: { en: '', fr: '', ar: '' },
  marketing_description: { en: '', fr: '', ar: '' },
  construction_progress: null,
}
const form = reactive(structuredClone(blank))
// Which language tab of the marketing copy is being edited.
const marketingLang = ref('fr')
const showMap = ref(false)

const open = computed({
  get: () => props.visible,
  set: (v) => emit('update:visible', v),
})
const editingId = computed(() => props.location?.id ?? null)
const mapsUrl = computed(() => googleMapsUrl(form))

// (Re)populate the form each time the drawer opens, from `location` (edit) or
// the blank template (create).
watch(
  () => props.visible,
  (isOpen) => {
    if (!isOpen) return
    const loc = props.location
    if (loc) {
      Object.assign(form, {
        name: loc.name,
        code: loc.code,
        wilaya_id: loc.wilaya_id ?? '',
        commune_id: loc.commune_id ?? '',
        type_id: loc.type_id ?? '',
        contract_type_id: loc.contract_type_id ?? '',
        payment_method_ids: loc.payment_method_ids ?? [],
        address: loc.address ?? '',
        description: loc.description ?? '',
        expected_delivery_date: loc.expected_delivery_date ?? '',
        gtm_priority: loc.gtm_priority ?? 'medium',
        latitude: loc.latitude ?? null,
        longitude: loc.longitude ?? null,
        cover_media_id: loc.cover_media_id ?? null,
        cover_focus_x: loc.cover_focus_x ?? 50,
        cover_focus_y: loc.cover_focus_y ?? 50,
        is_published: !!loc.is_published,
        show_prices: loc.show_prices !== false,
        show_availability: loc.show_availability !== false,
        marketing_tagline: { en: '', fr: '', ar: '', ...(loc.marketing_tagline ?? {}) },
        marketing_description: { en: '', fr: '', ar: '', ...(loc.marketing_description ?? {}) },
        construction_progress: loc.construction_progress ?? null,
      })
      loadFormCommunes(loc.wilaya_id)
      showMap.value = loc.latitude != null && loc.longitude != null
    } else {
      Object.assign(form, structuredClone(blank))
      showMap.value = false
    }
    marketingLang.value = 'fr'
  },
)

// Cascade: reload the dependent commune list when the chosen wilaya changes.
// A user-driven change also clears the previously-picked commune.
watch(
  () => form.wilaya_id,
  (id, prev) => {
    if (prev !== undefined && id !== prev) form.commune_id = ''
    loadFormCommunes(id)
  },
)

// Empty-string translations are dropped; an all-empty object becomes null.
function cleanTranslations(obj) {
  const filled = Object.fromEntries(
    Object.entries(obj ?? {})
      .filter(([, v]) => (v ?? '').trim() !== '')
      .map(([k, v]) => [k, v.trim()]),
  )
  return Object.keys(filled).length ? filled : null
}

async function submit() {
  const payload = {
    name: form.name.trim(),
    code: form.code.trim(),
    wilaya_id: form.wilaya_id || null,
    commune_id: form.commune_id || null,
    type_id: form.type_id || null,
    contract_type_id: form.contract_type_id || null,
    payment_method_ids: form.payment_method_ids ?? [],
    address: form.address.trim() || null,
    description: form.description.trim() || null,
    expected_delivery_date: form.expected_delivery_date || null,
    gtm_priority: form.gtm_priority,
    latitude: form.latitude ?? null,
    longitude: form.longitude ?? null,
    cover_media_id: form.cover_media_id ?? null,
    cover_focus_x: form.cover_focus_x ?? 50,
    cover_focus_y: form.cover_focus_y ?? 50,
    is_published: form.is_published,
    show_prices: form.show_prices,
    show_availability: form.show_availability,
    marketing_tagline: cleanTranslations(form.marketing_tagline),
    marketing_description: cleanTranslations(form.marketing_description),
    construction_progress: form.construction_progress ?? null,
  }
  try {
    if (editingId.value) {
      await store.update(editingId.value, payload)
    } else {
      await store.create(payload)
    }
    open.value = false
    emit('saved')
  } catch {
    /* error surfaced via store.error */
  }
}
</script>

<template>
  <Drawer
    v-model:visible="open"
    :position="isRTL() ? 'left' : 'right'"
    class="!w-full sm:!w-[540px]"
    :header="editingId ? $t('inventory.editProject') : $t('clients.newProject')"
  >
    <form class="space-y-4" @submit.prevent="submit">
      <div class="grid gap-3 sm:grid-cols-2">
        <BaseInput v-model="form.name" :label="$t('common.name')" required />
        <BaseInput v-model="form.code" :label="$t('inventory.code')" required />
        <BaseSelect
          v-model="form.wilaya_id"
          :label="$t('geo.wilaya')"
          :placeholder="$t('common.none')"
          :options="wilayas.map((w) => ({ value: w.id, label: `${w.code} · ${w.name}` }))"
        />
        <BaseSelect
          v-model="form.commune_id"
          :label="$t('geo.commune')"
          :placeholder="$t('common.none')"
          :disabled="!form.wilaya_id"
          :options="formCommunes.map((c) => ({ value: c.id, label: c.name }))"
        />
        <BaseSelect
          v-model="form.type_id"
          :label="$t('inventory.projectType')"
          :placeholder="$t('common.none')"
          :options="projectTypes.map((t) => ({ value: t.id, label: itemLabel(t) }))"
        />
        <BaseSelect
          v-model="form.contract_type_id"
          :label="$t('inventory.contractType')"
          :placeholder="$t('common.none')"
          :options="contractTypes.map((t) => ({ value: t.id, label: itemLabel(t) }))"
        />
        <BaseMultiSelect
          v-model="form.payment_method_ids"
          :label="$t('inventory.paymentMethodsOffered')"
          :placeholder="$t('common.none')"
          class="sm:col-span-2"
          :options="projectPaymentMethods.map((m) => ({ value: m.id, label: itemLabel(m) }))"
        />
        <div>
          <BaseInput v-model="form.address" :label="$t('common.address')" />
          <div v-if="mapsUrl" class="mt-1 flex items-center gap-3">
            <a
              :href="mapsUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-1 text-sm text-primary-600 hover:underline dark:text-primary-400"
            >
              <i class="pi pi-map-marker text-xs" aria-hidden="true" />
              {{ $t('inventory.openInMaps') }}
            </a>
            <button
              type="button"
              :title="$t('pipeline.copyMapsLink')"
              :aria-label="$t('pipeline.copyMapsLink')"
              class="inline-flex items-center text-primary-600 hover:underline dark:text-primary-400"
              @click="copyToClipboard(mapsUrl, $t('pipeline.mapsLinkCopied'))"
            >
              <i class="pi pi-copy text-xs" aria-hidden="true" />
            </button>
          </div>
        </div>
        <BaseInput
          v-model="form.expected_delivery_date"
          :label="$t('inventory.expectedDelivery')"
          type="date"
          :min="todayInput()"
        />
        <BaseSelect
          v-model="form.gtm_priority"
          :label="$t('inventory.gtmPriority')"
          :clearable="false"
          :options="gtmPriorityOptions()"
        />
      </div>
      <CoverImageUpload
        v-if="editingId"
        v-model="form.cover_media_id"
        v-model:focus-x="form.cover_focus_x"
        v-model:focus-y="form.cover_focus_y"
        :mediable-id="editingId"
      />
      <div class="space-y-2">
        <Button
          type="button"
          :label="showMap ? $t('inventory.hideMap') : $t('inventory.pickOnMap')"
          icon="pi pi-map"
          text
          size="small"
          @click="showMap = !showMap"
        />
        <LocationMap
          v-if="showMap"
          v-model:latitude="form.latitude"
          v-model:longitude="form.longitude"
          v-model:address="form.address"
          editable
        />
      </div>
      <BaseTextarea v-model="form.description" :label="$t('common.description')" :rows="3" />

      <!-- Public website (/plaza) controls -->
      <fieldset class="rounded-xl border border-line p-4">
        <legend class="px-1 text-sm font-semibold text-ink">
          <i class="pi pi-globe me-1 text-primary-500" aria-hidden="true" />{{ $t('inventory.websitePanel') }}
        </legend>

        <div class="space-y-3">
          <label class="flex items-center justify-between gap-3 text-sm text-ink">
            {{ $t('inventory.websitePublish') }}
            <ToggleSwitch v-model="form.is_published" />
          </label>
          <template v-if="form.is_published">
            <label class="flex items-center justify-between gap-3 text-sm text-ink">
              {{ $t('inventory.websiteShowPrices') }}
              <ToggleSwitch v-model="form.show_prices" />
            </label>
            <label class="flex items-center justify-between gap-3 text-sm text-ink">
              {{ $t('inventory.websiteShowAvailability') }}
              <ToggleSwitch v-model="form.show_availability" />
            </label>

            <!-- Trilingual marketing copy, one language tab at a time. The
                 dot flags languages still missing their text (visitors in
                 that language fall back to another one). -->
            <div class="flex items-center gap-1 pt-1">
              <button
                v-for="lang in ['fr', 'ar', 'en']"
                :key="lang"
                type="button"
                class="flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold uppercase transition-colors"
                :class="marketingLang === lang
                  ? 'bg-primary-500 text-primary-contrast'
                  : 'bg-surface-100 text-mute hover:text-ink dark:bg-surface-800'"
                @click="marketingLang = lang"
              >
                {{ lang }}
                <span
                  class="h-1.5 w-1.5 rounded-full"
                  :class="(form.marketing_description[lang] || '').trim() || (form.marketing_tagline[lang] || '').trim()
                    ? 'bg-success'
                    : 'border border-current opacity-60'"
                  aria-hidden="true"
                />
              </button>
              <span class="ms-1 text-[11px] text-mute">{{ $t('inventory.websiteLangsHint') }}</span>
            </div>
            <BaseInput
              v-model="form.marketing_tagline[marketingLang]"
              :label="$t('inventory.websiteTagline')"
              :maxlength="180"
            />
            <BaseTextarea
              v-model="form.marketing_description[marketingLang]"
              :label="$t('inventory.websiteDescription')"
              :rows="4"
            />

            <!-- Construction advancement, shown as a progress bar on the site -->
            <div>
              <div class="mb-2 flex items-center justify-between">
                <span class="text-sm font-medium text-ink">{{ $t('inventory.websiteProgress') }}</span>
                <span class="num text-sm font-semibold" :class="form.construction_progress === null ? 'text-mute' : 'text-primary-500'">
                  {{ form.construction_progress === null ? $t('common.none') : `${form.construction_progress}%` }}
                </span>
              </div>
              <div class="flex items-center gap-3">
                <Slider
                  :model-value="form.construction_progress ?? 0"
                  class="w-full"
                  :step="5"
                  @update:model-value="form.construction_progress = $event"
                />
                <button
                  v-if="form.construction_progress !== null"
                  type="button"
                  class="text-xs text-mute hover:text-danger"
                  :aria-label="$t('common.cancel')"
                  @click="form.construction_progress = null"
                ><i class="pi pi-times" aria-hidden="true" /></button>
              </div>
              <p class="mt-1.5 text-xs text-mute">{{ $t('inventory.websiteProgressHint') }}</p>
            </div>

            <p class="text-xs text-mute">{{ $t('inventory.websiteHint') }}</p>
          </template>
        </div>
      </fieldset>

      <div class="flex gap-2 pt-1">
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="store.saving" />
        <Button
          type="button"
          :label="$t('common.cancel')"
          severity="secondary"
          outlined
          @click="open = false"
        />
      </div>
    </form>
  </Drawer>
</template>
