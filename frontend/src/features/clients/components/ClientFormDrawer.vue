<script setup>
import { computed, reactive, ref, watch } from 'vue'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import BaseInput from '@/components/base/BaseInput.vue'
import BasePhoneInput from '@/components/base/BasePhoneInput.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import DuplicateNoticeModal from '@/features/clients/components/DuplicateNoticeModal.vue'
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
  id_documents: [],
  id_number: '',
  birth_date: '',
  birth_place: '',
  address: '',
})
const form = reactive(emptyForm())
const showIdentity = ref(false)

// Server-side (422) validation errors, keyed by field name — surfaced under the
// matching input so the user sees exactly what to fix (e.g. a future birth date)
// instead of a vague "couldn't save" toast.
const errors = reactive({})
const clearErrors = () => Object.keys(errors).forEach((k) => delete errors[k])
const fieldError = (name) => errors[name]?.[0] ?? ''

const emptyDocument = () => ({ type: '', number: '', issued_at: '', issued_place: '' })

function addDocument() {
  form.id_documents.push(emptyDocument())
}

function removeDocument(index) {
  form.id_documents.splice(index, 1)
}

watch(
  () => [props.visible, props.client],
  () => {
    if (!props.visible) return
    clearErrors()
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
      // Clone each stored document so edits don't mutate the store's copy.
      id_documents: (c?.id_documents ?? []).map((d) => ({
        type: d?.type ?? '',
        number: d?.number ?? '',
        issued_at: d?.issued_at ?? '',
        issued_place: d?.issued_place ?? '',
      })),
      id_number: c?.id_number ?? '',
      birth_date: c?.birth_date ?? '',
      birth_place: c?.birth_place ?? '',
      address: c?.address ?? '',
    })
    // Open the identity section when it already holds something.
    showIdentity.value = Boolean(
      c &&
        (c.id_documents?.length ||
          c.id_number ||
          c.birth_date ||
          c.birth_place ||
          c.address),
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
  clearErrors()
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
    // Keep only documents that carry at least a type or a number.
    id_documents: form.id_documents
      .map((d) => ({
        type: d.type || null,
        number: d.number.trim() || null,
        issued_at: d.issued_at || null,
        issued_place: d.issued_place.trim() || null,
      }))
      .filter((d) => d.type || d.number),
    id_number: form.id_number.trim() || null,
    birth_date: form.birth_date || null,
    birth_place: form.birth_place.trim() || null,
    address: form.address.trim() || null,
  }
  try {
    const saved = props.client
      ? await store.update(props.client.id, payload)
      : await store.create(payload)
    emit('update:visible', false)
    emit('saved', saved)
  } catch (e) {
    // A duplicate phone keeps the drawer open (so the phone can be corrected)
    // and shows the resolution notice instead of a generic error.
    if (e?.duplicate) duplicateNotice.value = e.duplicate
    // Field-level 422 errors: show each message under its input. If the failing
    // field lives in the collapsed identity section, open it so it is visible.
    const serverErrors = e?.response?.data?.errors
    if (serverErrors) {
      Object.assign(errors, serverErrors)
      if (
        ['id_number', 'birth_date', 'birth_place', 'address'].some((k) => errors[k]) ||
        Object.keys(errors).some((k) => k.startsWith('id_documents'))
      ) {
        showIdentity.value = true
      }
    }
    /* the summary message is also surfaced via store.error toast */
  }
}

const duplicateNotice = ref(null)
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
        <BaseInput v-model="form.last_name" label="Last name" capitalize :error="fieldError('last_name')" />
        <BaseInput v-model="form.first_name" label="First name" capitalize :error="fieldError('first_name')" />
      </div>
      <BasePhoneInput v-model="form.phone" label="Phone" required :error="fieldError('phone')" />
      <BaseInput v-model="form.email" label="Email" type="email" :error="fieldError('email')" />

      <BaseSelect
        v-model="form.source_id"
        label="Source"
        placeholder="None"
        :options="sources.map((s) => ({ value: s.id, label: s.label, icon: s.meta?.icon }))"
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
        <BaseInput v-model="form.referrer_name" label="Referrer name" capitalize :error="fieldError('referrer_name')" />
        <BasePhoneInput v-model="form.referrer_phone" label="Referrer phone" :error="fieldError('referrer_phone')" />
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

      <BaseTextarea v-model="form.notes" label="Notes" :rows="3" :error="fieldError('notes')" />

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
          <!-- ID documents — a client may present several; each has its own
               number and issue date/place. -->
          <div class="space-y-3">
            <div
              v-for="(doc, i) in form.id_documents"
              :key="i"
              class="space-y-3 rounded-lg border border-line p-3"
            >
              <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wide text-mute">
                  ID document {{ i + 1 }}
                </span>
                <Button
                  type="button"
                  icon="pi pi-trash"
                  text
                  rounded
                  size="small"
                  severity="danger"
                  aria-label="Remove document"
                  @click="removeDocument(i)"
                />
              </div>
              <div class="grid grid-cols-2 gap-3">
                <BaseSelect
                  v-model="doc.type"
                  label="ID document"
                  placeholder="None"
                  :options="ID_DOCUMENT_TYPES"
                />
                <BaseInput v-model="doc.number" label="Document number" :error="fieldError(`id_documents.${i}.number`)" />
              </div>
              <div class="grid grid-cols-2 gap-3">
                <BaseInput v-model="doc.issued_at" label="Issue date" type="date" :error="fieldError(`id_documents.${i}.issued_at`)" />
                <BaseInput v-model="doc.issued_place" label="Issue place" capitalize :error="fieldError(`id_documents.${i}.issued_place`)" />
              </div>
            </div>
            <Button
              type="button"
              label="Add ID document"
              icon="pi pi-plus"
              text
              size="small"
              @click="addDocument"
            />
          </div>
          <BaseInput
            v-model="form.id_number"
            label="ID number (NIN)"
            placeholder="National identification number"
            :error="fieldError('id_number')"
          />
          <div class="grid grid-cols-2 gap-3">
            <BaseInput v-model="form.birth_date" label="Birth date" type="date" :error="fieldError('birth_date')" />
            <BaseInput v-model="form.birth_place" label="Birth place" capitalize :error="fieldError('birth_place')" />
          </div>
          <BaseInput v-model="form.address" label="Address" :error="fieldError('address')" />
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

  <DuplicateNoticeModal
    v-if="duplicateNotice"
    :notice="duplicateNotice"
    @close="duplicateNotice = null"
  />
</template>
