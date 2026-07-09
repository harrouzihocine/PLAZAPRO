<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import SectionCard from '@/components/ui/SectionCard.vue'
import StatusTag from '@/components/ui/StatusTag.vue'
import { toastError } from '@/composables/useConfirm'
import { shortlistApi } from '@/features/clients/api'
import { queueable } from '@/features/offline/apiOrQueue'
import { formatMoney } from '@/features/payments/money'
import ProjectUnitsPicker from '@/features/inventory/components/ProjectUnitsPicker.vue'
import FinishToggle from '@/features/inventory/components/FinishToggle.vue'
import { useAuthStore } from '@/features/settings/store'
import { t } from '@/i18n'

// The project's property shortlist — the units (apartments / locals) and boxes
// the client wants to see. Each row shows the FULL property card and its journey
// state (shortlisted → visited → won/lost). Closure happens on the DEAL (the
// interested ones enter it at the visit log); this panel curates the list.
const props = defineProps({ projectId: { type: [String, Number], required: true } })
const emit = defineEmits(['changed'])
const auth = useAuthStore()
const route = useRoute()
// Curating the standalone shortlist (add / drop / save) needs shortlist.manage.
// Everyone else sees it read-only and adds properties from a log instead — the
// office-visit completion picker or "Add unit to visit" (visits.propose).
const canEdit = () => auth.can('shortlist.manage')

const items = ref([]) // working copy: { shortlistable_type, shortlistable_id, state, finish_type, property }
const additions = ref([]) // ProjectUnitsPicker v-model: properties to add on save
const saving = ref(false)
const touched = ref(false) // a finish toggle / removal marks the list dirty

// The full property card, not just the code.
const propertyLine = (it) => {
  const p = it.property
  if (!p) return `${it.shortlistable_type} #${it.shortlistable_id}`
  // `p.price` is already the PROPOSED finish's price; name the finish as soon
  // as the unit quotes a fini offer, so the number is never ambiguous.
  const priceText = p.price
    ? [
        it.finish_type === 'fini' || (p.price_fini != null && p.price_semi_fini == null)
          ? t('inventory.finishFiniShort')
          : p.price_fini != null
            ? t('inventory.finishSemiShort')
            : null,
        formatMoney(p.price),
      ]
        .filter(Boolean)
        .join(' ')
    : null

  return [
    p.reference,
    p.property_type,
    p.floor,
    p.area_sqm ? `${p.area_sqm} m²` : null,
    priceText,
    p.location,
  ]
    .filter(Boolean)
    .join(' · ')
}

// Flip the finish PROPOSED on an existing row; saved with the sync.
function setFinish(it, finish) {
  it.finish_type = finish
  // The displayed price follows the proposal immediately.
  if (it.property) {
    it.property = {
      ...it.property,
      price: finish === 'fini' ? it.property.price_fini : it.property.price_semi_fini,
    }
  }
  touched.value = true
}

// Keys already on the shortlist — hidden inside the picker.
const excludeKeys = computed(() =>
  items.value.map((i) => `${i.shortlistable_type}:${i.shortlistable_id}`),
)
const hasChanges = computed(() => additions.value.length > 0 || touched.value)

onMounted(load)

async function load() {
  const list = await shortlistApi.list(props.projectId)
  items.value = list.map((i) => ({
    id: i.id,
    shortlistable_type: i.shortlistable_type,
    shortlistable_id: i.shortlistable_id,
    state: i.state,
    locked_reason: i.locked_reason,
    finish_type: i.finish_type,
    property: i.property,
  }))
  additions.value = []
  touched.value = false
}

function remove(item) {
  const i = items.value.indexOf(item)
  if (i !== -1) items.value.splice(i, 1)
  touched.value = true
}

async function save() {
  const all = [...items.value, ...additions.value]
  if (!all.length) {
    toastError(t('shortlist.addAtLeastOne'))
    return
  }
  saving.value = true
  try {
    // Offline-queueable: the sync sends the FULL wanted list (last-write-wins),
    // so a queued copy replays cleanly; a locked property (deal/sold/reserved)
    // is rejected by the server on reconnect and surfaces in the Sync Center.
    const res = await queueable({
      method: 'put',
      url: `/projects/${props.projectId}/shortlist`,
      body: {
        items: all.map((i) => ({
          shortlistable_type: i.shortlistable_type,
          shortlistable_id: i.shortlistable_id,
          finish_type: i.finish_type ?? null,
        })),
        office_visit_id: null,
      },
      label: t('shortlist.updateLabel'),
      entityHint: { route: route.fullPath },
    })
    if (res.queued) {
      items.value = all
      additions.value = []
      return
    }
    await load()
    emit('changed')
  } catch (e) {
    toastError(e.response?.data?.message ?? t('shortlist.saveFailed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <SectionCard :title="$t('shortlist.title')" icon="pi pi-list-check">
    <ul v-if="items.length" class="mb-3 divide-y divide-line">
      <li
        v-for="it in items"
        :key="it.shortlistable_type + it.shortlistable_id"
        class="flex items-center justify-between gap-2 py-2 text-sm"
      >
        <span class="flex min-w-0 flex-wrap items-center gap-2">
          <i
            :class="it.shortlistable_type === 'box' ? 'pi pi-car' : 'pi pi-home'"
            class="shrink-0 text-mute"
            aria-hidden="true"
          />
          <span class="min-w-0 truncate text-ink">{{ propertyLine(it) }}</span>
          <StatusTag :value="it.state" />
          <StatusTag v-if="it.locked_reason" :value="it.locked_reason" />
          <!-- Both finishes quoted → the proposal can flip while unlocked. -->
          <FinishToggle
            v-if="
              canEdit() &&
              !it.locked_reason &&
              !['won', 'lost'].includes(it.state) &&
              it.shortlistable_type === 'unit' &&
              it.property?.price_semi_fini != null &&
              it.property?.price_fini != null
            "
            :model-value="it.finish_type ?? 'semi_fini'"
            :semi-fini="it.property.price_semi_fini"
            :fini="it.property.price_fini"
            :with-prices="false"
            @update:model-value="setFinish(it, $event)"
          />
        </span>
        <Button
          v-if="canEdit() && !['won', 'lost'].includes(it.state) && !it.locked_reason"
          icon="pi pi-times"
          text
          rounded
          size="small"
          severity="danger"
:aria-label="$t('shortlist.removeAria')"
          @click="remove(it)"
        />
      </li>
    </ul>
    <p v-else class="mb-3 text-sm text-mute">{{ $t('shortlist.empty') }}</p>

    <div v-if="canEdit()" class="space-y-3">
      <ProjectUnitsPicker v-model="additions" :exclude="excludeKeys" />
      <Button
        type="button"
:label="$t('shortlist.save')"
        icon="pi pi-check"
        size="small"
        :disabled="saving || (!hasChanges && !items.length)"
        :loading="saving"
        @click="save"
      />
    </div>
  </SectionCard>
</template>
