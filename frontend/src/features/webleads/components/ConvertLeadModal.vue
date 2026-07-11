<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import BaseModal from '@/components/base/BaseModal.vue'
import BaseSelect from '@/components/base/BaseSelect.vue'
import { toastError } from '@/composables/useConfirm'
import { useAuthStore } from '@/features/settings/store'
import { followUpAgentsApi } from '@/features/clients/api'
import { t } from '@/i18n'
import { webLeadsApi } from '../api'

// One-click convert: web lead → real Client (+ optional project on the lead's
// project/unit). A 409 means the phone already belongs to a client — the modal
// switches to the duplicate panel: link the lead to that client (when visible)
// or stop there (the duplicate desk owns invisible ones).

const props = defineProps({
  lead: { type: Object, required: true },
})

const emit = defineEmits(['close', 'converted'])

const auth = useAuthStore()

const agents = ref([])
const assignedAgentId = ref('')
const createProject = ref(!!props.lead.location_id)
const saving = ref(false)
// null until a 409 arrives: { clientId, visible }
const duplicate = ref(null)

const canAssign = auth.can('clients.manage')

onMounted(async () => {
  if (!canAssign) return
  try {
    agents.value = await followUpAgentsApi.list()
  } catch {
    /* picker stays empty — conversion still works */
  }
})

async function convert(existingClientId = null) {
  saving.value = true
  try {
    await webLeadsApi.convert(props.lead.id, {
      existing_client_id: existingClientId,
      assigned_agent_id: assignedAgentId.value || null,
      create_project: createProject.value,
    })
    emit('converted')
  } catch (e) {
    if (e.response?.status === 409 && e.response.data?.duplicate) {
      duplicate.value = {
        clientId: e.response.data.client_id ?? null,
        visible: !!e.response.data.visible,
      }
    } else {
      toastError(e.response?.data?.message ?? t('webleads.saveFailed'))
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :title="$t('webleads.convertTitle')" size="max-w-md" @close="emit('close')">
    <!-- Duplicate branch -->
    <div v-if="duplicate" class="space-y-4">
      <p class="flex items-start gap-2.5 rounded-lg bg-warning/10 p-3 text-sm text-ink">
        <i class="pi pi-exclamation-triangle mt-0.5 text-warning" aria-hidden="true" />
        {{ duplicate.visible ? $t('webleads.duplicateBody') : $t('webleads.duplicateInvisible') }}
      </p>

      <div class="flex flex-wrap gap-2">
        <Button
          v-if="duplicate.visible && duplicate.clientId"
          :label="$t('webleads.duplicateLink')"
          icon="pi pi-link"
          :loading="saving"
          @click="convert(duplicate.clientId)"
        />
        <RouterLink v-if="duplicate.visible && duplicate.clientId" :to="`/clients/${duplicate.clientId}`">
          <Button :label="$t('webleads.openClient')" icon="pi pi-user" outlined />
        </RouterLink>
        <Button :label="$t('common.cancel')" severity="secondary" text @click="emit('close')" />
      </div>
    </div>

    <!-- Normal branch -->
    <div v-else class="space-y-4">
      <div class="rounded-lg bg-ground p-3 text-sm">
        <p class="font-semibold text-ink">{{ lead.name }}</p>
        <p class="ltr-data num mt-0.5 text-mute">{{ lead.phone }}</p>
        <p v-if="lead.location" class="mt-0.5 text-mute">
          {{ lead.location.name }}<template v-if="lead.unit"> · {{ lead.unit.reference }}</template>
        </p>
      </div>

      <BaseSelect
        v-if="canAssign"
        v-model="assignedAgentId"
        :label="$t('webleads.assignAgent')"
        :placeholder="$t('common.none')"
        :options="agents.map((a) => ({ value: a.id, label: a.name }))"
      />

      <label v-if="lead.location" class="flex items-center justify-between gap-3 text-sm text-ink">
        {{ $t('webleads.createProject', { project: lead.location.name }) }}
        <ToggleSwitch v-model="createProject" />
      </label>

      <div class="flex gap-2 pt-1">
        <Button :label="$t('webleads.convert')" icon="pi pi-user-plus" :loading="saving" @click="convert()" />
        <Button :label="$t('common.cancel')" severity="secondary" outlined @click="emit('close')" />
      </div>
    </div>
  </BaseModal>
</template>
