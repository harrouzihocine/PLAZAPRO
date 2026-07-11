<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import PageHeader from '@/components/ui/PageHeader.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import OfflineStamp from '@/components/ui/OfflineStamp.vue'
import { confirmAction, toastError, toastSuccess } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { formatDate, formatDateTime, timeAgo } from '@/utils/format'
import { t } from '@/i18n'
import { webLeadsApi } from '../api'
import ConvertLeadModal from '../components/ConvertLeadModal.vue'

// The website-leads inbox: triage what the public site sends in, then convert
// the real ones into clients (the convert modal owns the duplicate handling).

const leads = ref([])
const loading = ref(true)
const tab = ref('new')
const converting = ref(null) // the lead open in the convert modal

const TABS = ['new', 'handled', 'converted', 'spam']

const TYPE_META = {
  interest: { labelKey: 'webleads.typeInterest', icon: 'pi pi-heart', class: 'bg-primary-500/10 text-primary-600 dark:text-primary-400' },
  visit_request: { labelKey: 'webleads.typeVisit', icon: 'pi pi-calendar', class: 'bg-info/10 text-info' },
  callback: { labelKey: 'webleads.typeCallback', icon: 'pi pi-phone', class: 'bg-success/10 text-success' },
}

async function load() {
  loading.value = true
  try {
    const { data } = await webLeadsApi.list({ lead_status: tab.value })
    leads.value = data
  } catch {
    toastError(t('webleads.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
useRefreshable(load)

function switchTab(next) {
  tab.value = next
  load()
}

const list = computed(() => leads.value)

function whatsappLink(lead) {
  const digits = (lead.phone ?? '').replace(/\D/g, '')
  return digits ? `https://wa.me/${digits}` : null
}

async function markHandled(lead) {
  try {
    await webLeadsApi.markHandled(lead.id)
    toastSuccess(t('webleads.handledToast'))
    load()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('webleads.saveFailed'))
  }
}

async function markSpam(lead) {
  if (!(await confirmAction({ title: t('webleads.spamConfirm'), confirmText: t('webleads.markSpam') }))) return
  try {
    await webLeadsApi.markSpam(lead.id)
    toastSuccess(t('webleads.spamToast'))
    load()
  } catch (e) {
    toastError(e.response?.data?.message ?? t('webleads.saveFailed'))
  }
}

function onConverted() {
  converting.value = null
  toastSuccess(t('webleads.convertedToast'))
  load()
}
</script>

<template>
  <div>
    <PageHeader :title="$t('webleads.title')" :subtitle="$t('webleads.subtitle')" />

    <!-- Status tabs -->
    <div class="mb-4 flex gap-1 overflow-x-auto rounded-full border border-line bg-card p-1">
      <button
        v-for="status in TABS"
        :key="status"
        type="button"
        class="whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-medium transition-colors"
        :class="tab === status ? 'bg-primary-500 text-primary-contrast' : 'text-mute hover:text-ink'"
        @click="switchTab(status)"
      >
        {{ $t(`webleads.tab${status.charAt(0).toUpperCase() + status.slice(1)}`) }}
      </button>
    </div>

    <div v-if="loading" class="space-y-3">
      <div v-for="n in 4" :key="n" class="h-28 animate-pulse rounded-xl border border-line bg-card" />
    </div>

    <EmptyState v-else-if="!list.length" icon="pi pi-globe" :title="$t('webleads.empty')" />

    <div v-else class="space-y-3">
      <article
        v-for="lead in list"
        :key="lead.id"
        class="rounded-xl border border-line bg-card p-4 shadow-card"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="font-semibold text-ink">{{ lead.name }}</h3>
              <span
                class="flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="TYPE_META[lead.type]?.class"
              >
                <i :class="[TYPE_META[lead.type]?.icon, 'text-[10px]']" aria-hidden="true" />
                {{ $t(TYPE_META[lead.type]?.labelKey ?? 'webleads.typeInterest') }}
              </span>
              <span v-if="lead.locale" class="rounded-full bg-surface-100 px-2 py-0.5 text-xs uppercase text-mute dark:bg-surface-800">
                {{ lead.locale }}
              </span>
            </div>

            <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-mute">
              <a :href="`tel:${lead.phone}`" class="ltr-data num font-medium text-ink hover:text-primary-500">{{ lead.phone }}</a>
              <a
                v-if="whatsappLink(lead)"
                :href="whatsappLink(lead)"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-1 text-success hover:underline"
              >
                <i class="pi pi-whatsapp" aria-hidden="true" />WhatsApp
              </a>
              <span v-if="lead.location" class="flex items-center gap-1">
                <i class="pi pi-building" aria-hidden="true" />
                {{ lead.location.name }}<template v-if="lead.unit"> · {{ lead.unit.reference }}</template>
              </span>
              <span v-if="lead.preferred_date" class="flex items-center gap-1">
                <i class="pi pi-calendar" aria-hidden="true" />
                {{ $t('webleads.preferred') }}: {{ formatDate(lead.preferred_date) }}
                <template v-if="lead.preferred_time"> {{ lead.preferred_time }}</template>
              </span>
            </div>

            <p v-if="lead.message" class="mt-2 whitespace-pre-line rounded-lg bg-ground p-3 text-sm text-ink">
              {{ lead.message }}
            </p>
          </div>

          <div class="text-end text-xs text-mute" :title="formatDateTime(lead.created_at)">
            {{ timeAgo(lead.created_at) }}
            <p v-if="lead.handled_by" class="mt-1">{{ lead.handled_by }}</p>
          </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-line pt-3">
          <template v-if="lead.lead_status === 'new' || lead.lead_status === 'handled'">
            <Button
              :label="$t('webleads.convert')"
              icon="pi pi-user-plus"
              size="small"
              @click="converting = lead"
            />
            <Button
              v-if="lead.lead_status === 'new'"
              :label="$t('webleads.markHandled')"
              icon="pi pi-check"
              size="small"
              severity="secondary"
              outlined
              @click="markHandled(lead)"
            />
            <Button
              :label="$t('webleads.markSpam')"
              icon="pi pi-ban"
              size="small"
              severity="danger"
              text
              @click="markSpam(lead)"
            />
          </template>
          <RouterLink
            v-else-if="lead.lead_status === 'converted' && lead.converted_client_id"
            :to="`/clients/${lead.converted_client_id}`"
          >
            <Button :label="$t('webleads.openClient')" icon="pi pi-user" size="small" outlined />
          </RouterLink>
        </div>
      </article>
    </div>

    <OfflineStamp class="mt-6" />

    <ConvertLeadModal
      v-if="converting"
      :lead="converting"
      @close="converting = null"
      @converted="onConverted"
    />
  </div>
</template>
