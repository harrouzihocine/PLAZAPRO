<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { appSettingsApi } from '@/features/settings/api'
import { t } from '@/i18n'

// Scalar app-wide settings. Interest hold = how long a plain interest hold
// lasts (a hold backing an open deal never expires). Reserved window = how long
// a unit stays off the market after a holding deposit before the sweep frees it.
// Login security = failed password attempts before an account locks, and how
// long the lock lasts (0 = until an admin unlocks it in Users).
const holdHours = ref('')
const reservedHours = ref('')
const maxAttempts = ref('')
const lockoutMinutes = ref('')
const officeWindowDays = ref('')
// The company / office profile, printed into the office-visit invitation.
const companyName = ref('')
const officeAddress = ref('')
const officeMapsUrl = ref('')
const officePhone = ref('')
// The public showcase site (/plaza): contact channels + trilingual "about us".
const websiteWhatsapp = ref('')
const websiteEmail = ref('')
const websiteFacebook = ref('')
const websiteInstagram = ref('')
const websiteAbout = ref({ en: '', fr: '', ar: '' })
const websiteAboutLang = ref('fr')
const savingWebsite = ref(false)
const geofenceRadius = ref('')
const acceptSla = ref('')
const arrivalGrace = ref('')
const gpsRetention = ref('')
const snapshotMinutes = ref('')
const idleAlertMinutes = ref('')
const offrouteMeters = ref('')
const loading = ref(true)
const saving = ref(false)
const savingCompany = ref(false)

async function load() {
  try {
    const settings = await appSettingsApi.get()
    holdHours.value = settings.interest_hold_hours ?? '48'
    reservedHours.value = settings.reserved_hold_hours ?? '72'
    maxAttempts.value = settings.login_max_attempts ?? '3'
    lockoutMinutes.value = settings.login_lockout_minutes ?? '0'
    officeWindowDays.value = settings.office_visit_max_days ?? '1'
    companyName.value = settings.company_name ?? ''
    officeAddress.value = settings.office_address ?? ''
    officeMapsUrl.value = settings.office_maps_url ?? ''
    officePhone.value = settings.office_phone ?? ''
    websiteWhatsapp.value = settings.website_whatsapp ?? ''
    websiteEmail.value = settings.website_email ?? ''
    websiteFacebook.value = settings.website_facebook_url ?? ''
    websiteInstagram.value = settings.website_instagram_url ?? ''
    websiteAbout.value = {
      en: settings.website_about_en ?? '',
      fr: settings.website_about_fr ?? '',
      ar: settings.website_about_ar ?? '',
    }
    geofenceRadius.value = settings.dispatch_geofence_radius_m ?? '200'
    acceptSla.value = settings.dispatch_accept_sla_minutes ?? '15'
    arrivalGrace.value = settings.dispatch_arrival_grace_minutes ?? '15'
    gpsRetention.value = settings.agent_position_retention_days ?? '30'
    snapshotMinutes.value = settings.dispatch_snapshot_minutes ?? '5'
    idleAlertMinutes.value = settings.dispatch_idle_alert_minutes ?? '45'
    offrouteMeters.value = settings.dispatch_offroute_m ?? '1500'
  } catch {
    toastError(t('settings.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
useRefreshable(load) // pull-to-refresh + reconnect self-heal

async function save() {
  const hours = Number(holdHours.value)
  const reserved = Number(reservedHours.value)
  const attempts = Number(maxAttempts.value)
  const lockout = Number(lockoutMinutes.value)
  if (!Number.isInteger(hours) || hours < 1 || !Number.isInteger(reserved) || reserved < 1) {
    toastError(t('settings.hoursInvalid'))
    return
  }
  if (!Number.isInteger(attempts) || attempts < 1 || attempts > 10) {
    toastError(t('settings.attemptsInvalid'))
    return
  }
  if (!Number.isInteger(lockout) || lockout < 0) {
    toastError(t('settings.lockoutInvalid'))
    return
  }
  const officeDays = Number(officeWindowDays.value)
  if (!Number.isInteger(officeDays) || officeDays < 0) {
    toastError(t('settings.officeWindowInvalid'))
    return
  }
  const radius = Number(geofenceRadius.value)
  const sla = Number(acceptSla.value)
  const grace = Number(arrivalGrace.value)
  const retention = Number(gpsRetention.value)
  const snapshot = Number(snapshotMinutes.value)
  const idle = Number(idleAlertMinutes.value)
  const offroute = Number(offrouteMeters.value)
  if (
    !Number.isInteger(radius) || radius < 50 || radius > 2000
    || !Number.isInteger(sla) || sla < 1
    || !Number.isInteger(grace) || grace < 1
    || !Number.isInteger(retention) || retention < 7
    || !Number.isInteger(snapshot) || snapshot < 0 || snapshot > 30
    || !Number.isInteger(idle) || idle < 0 || idle > 480
    || !Number.isInteger(offroute) || offroute < 0 || offroute > 20000
  ) {
    toastError(t('settings.dispatchGpsInvalid'))
    return
  }
  saving.value = true
  try {
    await appSettingsApi.save({
      interest_hold_hours: hours,
      reserved_hold_hours: reserved,
      login_max_attempts: attempts,
      login_lockout_minutes: lockout,
      office_visit_max_days: officeDays,
      dispatch_geofence_radius_m: radius,
      dispatch_accept_sla_minutes: sla,
      dispatch_arrival_grace_minutes: grace,
      agent_position_retention_days: retention,
      dispatch_snapshot_minutes: snapshot,
      dispatch_idle_alert_minutes: idle,
      dispatch_offroute_m: offroute,
    })
    toastSuccess(t('settings.saved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('settings.saveFailed'))
  } finally {
    saving.value = false
  }
}

// The company / office profile saves on its own — a partial PUT, so it never
// depends on the numeric fields validating. Empty fields are sent as null so
// the server clears them (and skips the URL check on a blank maps link).
async function saveCompany() {
  const maps = officeMapsUrl.value.trim()
  if (maps && !/^https?:\/\//i.test(maps)) {
    toastError(t('settings.mapsUrlInvalid'))
    return
  }
  savingCompany.value = true
  try {
    await appSettingsApi.save({
      company_name: companyName.value.trim() || null,
      office_address: officeAddress.value.trim() || null,
      office_maps_url: maps || null,
      office_phone: officePhone.value.trim() || null,
    })
    toastSuccess(t('settings.saved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('settings.saveFailed'))
  } finally {
    savingCompany.value = false
  }
}

// The public-website block saves on its own too (same partial-PUT pattern).
async function saveWebsite() {
  for (const url of [websiteFacebook.value.trim(), websiteInstagram.value.trim()]) {
    if (url && !/^https?:\/\//i.test(url)) {
      toastError(t('settings.mapsUrlInvalid'))
      return
    }
  }
  savingWebsite.value = true
  try {
    await appSettingsApi.save({
      website_whatsapp: websiteWhatsapp.value.trim() || null,
      website_email: websiteEmail.value.trim() || null,
      website_facebook_url: websiteFacebook.value.trim() || null,
      website_instagram_url: websiteInstagram.value.trim() || null,
      website_about_en: websiteAbout.value.en.trim() || null,
      website_about_fr: websiteAbout.value.fr.trim() || null,
      website_about_ar: websiteAbout.value.ar.trim() || null,
    })
    toastSuccess(t('settings.saved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('settings.saveFailed'))
  } finally {
    savingWebsite.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('settings.general')" :subtitle="$t('settings.generalSubtitle')" />

    <!-- The company / office profile: printed into the office-visit invitation
         agents send clients ("come to us"). Every field is optional. -->
    <SectionCard :title="$t('settings.companyProfile')" icon="pi pi-building">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="saveCompany">
        <p class="text-xs text-mute">{{ $t('settings.companyProfileHint') }}</p>
        <BaseInput v-model="companyName" :label="$t('settings.companyNameLabel')" maxlength="120" />
        <BaseInput v-model="officeAddress" :label="$t('settings.officeAddressLabel')" maxlength="255" />
        <div>
          <BaseInput
            v-model="officeMapsUrl"
            :label="$t('settings.officeMapsLabel')"
            type="url"
            placeholder="https://maps.google.com/…"
            maxlength="500"
          />
          <p class="mt-1.5 text-xs text-mute">{{ $t('settings.officeMapsHint') }}</p>
        </div>
        <BaseInput v-model="officePhone" :label="$t('settings.officePhoneLabel')" maxlength="40" />
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="savingCompany" />
      </form>
    </SectionCard>

    <!-- The public showcase site (/plaza): what anonymous visitors see. -->
    <SectionCard :title="$t('settings.websiteProfile')" icon="pi pi-globe" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="saveWebsite">
        <p class="text-xs text-mute">{{ $t('settings.websiteProfileHint') }}</p>
        <div>
          <BaseInput v-model="websiteWhatsapp" :label="$t('settings.websiteWhatsappLabel')" maxlength="40" />
          <p class="mt-1.5 text-xs text-mute">{{ $t('settings.websiteWhatsappHint') }}</p>
        </div>
        <BaseInput v-model="websiteEmail" :label="$t('settings.websiteEmailLabel')" type="email" maxlength="120" />
        <BaseInput v-model="websiteFacebook" :label="$t('settings.websiteFacebookLabel')" type="url" maxlength="500" placeholder="https://facebook.com/…" />
        <BaseInput v-model="websiteInstagram" :label="$t('settings.websiteInstagramLabel')" type="url" maxlength="500" placeholder="https://instagram.com/…" />

        <div>
          <div class="mb-2 flex items-center gap-1">
            <span class="me-2 text-sm font-medium text-ink">{{ $t('settings.websiteAboutLabel') }}</span>
            <button
              v-for="lang in ['fr', 'ar', 'en']"
              :key="lang"
              type="button"
              class="rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase transition-colors"
              :class="websiteAboutLang === lang
                ? 'bg-primary-500 text-primary-contrast'
                : 'bg-surface-100 text-mute hover:text-ink dark:bg-surface-800'"
              @click="websiteAboutLang = lang"
            >{{ lang }}</button>
          </div>
          <BaseTextarea v-model="websiteAbout[websiteAboutLang]" :rows="5" :maxlength="5000" />
          <p class="mt-1.5 text-xs text-mute">{{ $t('settings.websiteAboutHint') }}</p>
        </div>

        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="savingWebsite" />
      </form>
    </SectionCard>

    <SectionCard :title="$t('settings.holds')" icon="pi pi-clock" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="save">
        <div>
          <BaseInput
            v-model="holdHours"
:label="$t('settings.holdHoursLabel')"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.holdHoursHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="reservedHours"
:label="$t('settings.reservedHoursLabel')"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.reservedHoursHint') }}
          </p>
        </div>
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="saving" />
      </form>
    </SectionCard>

    <SectionCard :title="$t('settings.visits')" icon="pi pi-calendar" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="save">
        <div>
          <BaseInput
            v-model="officeWindowDays"
:label="$t('settings.officeWindowLabel')"
            type="number"
            min="0"
            max="60"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.officeWindowHint') }}
          </p>
        </div>
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="saving" />
      </form>
    </SectionCard>

    <SectionCard :title="$t('settings.loginSecurity')" icon="pi pi-shield" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="save">
        <div>
          <BaseInput
            v-model="maxAttempts"
:label="$t('settings.maxAttemptsLabel')"
            type="number"
            min="1"
            max="10"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.maxAttemptsHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="lockoutMinutes"
:label="$t('settings.lockoutLabel')"
            type="number"
            min="0"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.lockoutHint') }}
          </p>
        </div>
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="saving" />
      </form>
    </SectionCard>

    <!-- The dispatch GPS layer: geofence auto check-in, the watchdog's two
         clocks, and how long breadcrumb trails are kept. -->
    <SectionCard :title="$t('settings.dispatchGps')" icon="pi pi-map-marker" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="save">
        <div>
          <BaseInput
            v-model="geofenceRadius"
:label="$t('settings.geofenceLabel')"
            type="number"
            min="50"
            max="2000"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.geofenceHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="acceptSla"
:label="$t('settings.acceptSlaLabel')"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.acceptSlaHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="arrivalGrace"
:label="$t('settings.graceLabel')"
            type="number"
            min="1"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.graceHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="gpsRetention"
:label="$t('settings.retentionLabel')"
            type="number"
            min="7"
            max="365"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.retentionHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="snapshotMinutes"
            :label="$t('settings.snapshotLabel')"
            type="number"
            min="0"
            max="30"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.snapshotHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="idleAlertMinutes"
            :label="$t('settings.idleAlertLabel')"
            type="number"
            min="0"
            max="480"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.idleAlertHint') }}
          </p>
        </div>
        <div>
          <BaseInput
            v-model="offrouteMeters"
            :label="$t('settings.offrouteLabel')"
            type="number"
            min="0"
            max="20000"
            step="100"
          />
          <p class="mt-1.5 text-xs text-mute">
            {{ $t('settings.offrouteHint') }}
          </p>
        </div>
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="saving" />
      </form>
    </SectionCard>
  </div>
</template>
