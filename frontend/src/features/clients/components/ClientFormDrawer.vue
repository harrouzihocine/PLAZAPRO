<script setup>
import { computed, reactive, ref, watch } from 'vue'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import BaseInput from '@/components/base/BaseInput.vue'
import BasePhoneInput from '@/components/base/BasePhoneInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import { useDynamicList } from '@/composables/useDynamicList'
import { useClientsStore } from '@/features/clients/clientsStore'
import { useAuthStore } from '@/features/settings/store'

// The client create/edit form, shared by the clients list and the client file.
// Names are optional (phone is the one required field); when the lead source is
// "referral" the referrer's name + phone appear; the identity/contract section
// holds what back-office needs by the time a deal closes.
const props = defineProps({
  visible: { type: Boolean, default: false },
  client: { type: Object, default: null }, // null = creating
})
const emit = defineEmits(['update:visible', 'saved'])

const store = useClientsStore()
const auth = useAuthStore()
const { items: sources } = useDynamicList('sources')
const { items: ratings } = useDynamicList('client_ratings')

// Assigning a follow-up agent is back-office-only (clients.manage).
const canSeeOwnership = computed(() => auth.can('clients.manage'))

const ID_DOCUMENT_TYPES = [
  { value: 'national_id', label: 'National ID card' },
  { value: 'driving_license', label: 'Driving licence' },
  { value: 'passport', label: 'Passport' },
]

const emptyForm = () => ({
  first_name: '',
  last_name: '',
  phone: '',
  email: '',
  source_id: '',
  rating_id: '',
  referrer_name: '',
  referrer_phone: '',
  assigned_agent_id: '',
  notes: '',
  id_document_type: '',
  id_document_number: '',
  birth_date: '',
  birth_place: '',
  nationality: '',
  address: '',
  occupation: '',
})
const form = reactive(emptyForm())
const showIdentity = ref(false)

watch(
  () => [props.visible, props.client],
  () => {
    if (!props.visible) return
    const c = props.client
    Object.assign(form, emptyForm(), {
      first_name: c?.first_name ?? '',
      last_name: c?.last_name ?? '',
      phone: c?.phone ?? '',
      email: c?.email ?? '',
      source_id: c?.source?.id ?? '',
      rating_id: c?.rating?.id ?? '',
      referrer_name: c?.referrer_name ?? '',
      referrer_phone: c?.referrer_phone ?? '',
      assigned_agent_id: c?.assigned_agent?.id ?? '',
      notes: c?.notes ?? '',
      id_document_type: c?.id_document_type ?? '',
      id_document_number: c?.id_document_number ?? '',
      birth_date: c?.birth_date ?? '',
      birth_place: c?.birth_place ?? '',
      nationality: c?.nationality ?? '',
      address: c?.address ?? '',
      occupation: c?.occupation ?? '',
    })
    // Open the identity section when it already holds something.
    showIdentity.value = Boolean(
      c &&
        (c.id_document_type ||
          c.id_document_number ||
          c.birth_date ||
          c.birth_place ||
          c.nationality ||
          c.address ||
          c.occupation),
    )
  },
  { immediate: true },
)

// The referrer inputs appear only when the picked source IS the referral one.
const isReferral = computed(
  () => sources.value.find((s) => s.id === form.source_id)?.value === 'referral',
)

async function save() {
  if (!form.phone.trim()) return
  const payload = {
    first_name: form.first_name.trim() || null,
    last_name: form.last_name.trim() || null,
    phone: form.phone.trim(),
    email: form.email.trim() || null,
    source_id: form.source_id || null,
    rating_id: form.rating_id || null,
    referrer_name: isReferral.value ? form.referrer_name.trim() || null : null,
    referrer_phone: isReferral.value ? form.referrer_phone.trim() || null : null,
    assigned_agent_id: form.assigned_agent_id || null,
    notes: form.notes.trim() || null,
    id_document_type: form.id_document_type || null,
    id_document_number: form.id_document_number.trim() || null,
    birth_date: form.birth_date || null,
    birth_place: form.birth_place.trim() || null,
    nationality: form.nationality.trim() || null,
    address: form.address.trim() || null,
    occupation: form.occupation.trim() || null,
  }
  try {
    const saved = props.client
      ? await store.update(props.client.id, payload)
      : await store.create(payload)
    emit('update:visible', false)
    emit('saved', saved)
  } catch {
    /* error surfaced via store.error */
  }
}
</script>

<template>
  <Drawer
    :visible="visible"
    position="right"
    class="!w-full sm:!w-[480px]"
    :header="client ? 'Edit client' : 'New client'"
    @update:visible="emit('update:visible', $event)"
  >
    <form class="space-y-4" @submit.prevent="save">
      <div class="grid grid-cols-2 gap-3">
        <BaseInput v-model="form.last_name" label="Last name (optional)" capitalize />
        <BaseInput v-model="form.first_name" label="First name (optional)" capitalize />
      </div>
      <BasePhoneInput v-model="form.phone" label="Phone" />
      <BaseInput v-model="form.email" label="Email" type="email" />

      <BaseSelect
        v-model="form.source_id"
        label="Source"
        placeholder="None"
        :options="sources.map((s) => ({ value: s.id, label: s.label }))"
      />

      <!-- Who told the client about the project — shown for referral leads. -->
      <div
        v-if="isReferral"
        class="space-y-3 rounded-xl border border-primary-200 bg-primary-50/50 p-3 dark:border-primary-500/30 dark:bg-primary-500/5"
      >
        <p class="text-xs font-semibold uppercase tracking-wide text-mute">
          <i class="pi pi-share-alt text-[10px]" aria-hidden="true" />
          Referred by
        </p>
        <BaseInput v-model="form.referrer_name" label="Referrer name" capitalize />
        <BasePhoneInput v-model="form.referrer_phone" label="Referrer phone" />
      </div>

      <BaseSelect
        v-model="form.rating_id"
        label="Rating"
        placeholder="None"
        :options="ratings.map((r) => ({ value: r.id, label: r.label }))"
      />

      <BaseSelect
        v-if="canSeeOwnership"
        v-model="form.assigned_agent_id"
        label="Assigned agent"
        placeholder="Unassigned"
        :options="store.followUpAgents.map((a) => ({ value: a.id, label: a.name }))"
      />

      <BaseTextarea v-model="form.notes" label="Notes" :rows="3" />

      <!-- Identity / contract details — needed by the time a deal closes. -->
      <div class="rounded-xl border border-line">
        <button
          type="button"
          class="flex w-full items-center justify-between px-3 py-2.5 text-sm font-medium text-ink"
          @click="showIdentity = !showIdentity"
        >
          <span class="flex items-center gap-2">
            <i class="pi pi-id-card text-mute" aria-hidden="true" />
            Identity &amp; contract details
          </span>
          <i :class="showIdentity ? 'pi pi-chevron-up' : 'pi pi-chevron-down'" class="text-xs text-mute" aria-hidden="true" />
        </button>
        <div v-if="showIdentity" class="space-y-3 border-t border-line p-3">
          <div class="grid grid-cols-2 gap-3">
            <BaseSelect
              v-model="form.id_document_type"
              label="ID document"
              placeholder="None"
              :options="ID_DOCUMENT_TYPES"
            />
            <BaseInput v-model="form.id_document_number" label="Document number" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <BaseInput v-model="form.birth_date" label="Birth date" type="date" />
            <BaseInput v-model="form.birth_place" label="Birth place" capitalize />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <BaseInput v-model="form.nationality" label="Nationality" capitalize />
            <BaseInput v-model="form.occupation" label="Occupation" />
          </div>
          <BaseInput v-model="form.address" label="Address" />
        </div>
      </div>

      <div class="flex gap-2 pt-2">
        <Button type="submit" label="Save" icon="pi pi-check" :loading="store.saving" />
        <Button
          type="button"
          label="Cancel"
          severity="secondary"
          outlined
          @click="emit('update:visible', false)"
        />
      </div>
    </form>
  </Drawer>
</template>
