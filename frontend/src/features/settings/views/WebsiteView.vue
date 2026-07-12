<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import BaseInput from '@/components/base/BaseInput.vue'
import BaseTextarea from '@/components/base/BaseTextarea.vue'
import HeroLibraryManager from '@/features/settings/components/HeroLibraryManager.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import SectionCard from '@/components/ui/SectionCard.vue'
import { toastError, toastSuccess } from '@/composables/useConfirm'
import { useRefreshable } from '@/composables/useRefreshRegistry'
import { appSettingsApi } from '@/features/settings/api'
import { t } from '@/i18n'

// Everything the public showcase (/plaza) presents about the company, on its
// own page: contact channels (several numbers), social profiles, the
// trilingual "about us" and the landing-hero library. Each card saves on its
// own (partial PUTs, same pattern as GeneralView).

const loading = ref(true)

// Contact
const whatsapp = ref('')
const email = ref('')
const phones = ref([''])
const savingContact = ref(false)

// Social profiles — brand names stay untranslated.
const SOCIALS = [
  { key: 'website_facebook_url', label: 'Facebook', icon: 'pi pi-facebook', placeholder: 'https://facebook.com/…' },
  { key: 'website_instagram_url', label: 'Instagram', icon: 'pi pi-instagram', placeholder: 'https://instagram.com/…' },
  { key: 'website_tiktok_url', label: 'TikTok', icon: 'pi pi-tiktok', placeholder: 'https://tiktok.com/@…' },
  { key: 'website_youtube_url', label: 'YouTube', icon: 'pi pi-youtube', placeholder: 'https://youtube.com/@…' },
  { key: 'website_linkedin_url', label: 'LinkedIn', icon: 'pi pi-linkedin', placeholder: 'https://linkedin.com/company/…' },
  { key: 'website_x_url', label: 'X (Twitter)', icon: 'pi pi-twitter', placeholder: 'https://x.com/…' },
]
const socials = ref(Object.fromEntries(SOCIALS.map((s) => [s.key, ''])))
const savingSocials = ref(false)

// About us (trilingual)
const about = ref({ en: '', fr: '', ar: '' })
const aboutLang = ref('fr')
const savingAbout = ref(false)

// Landing hero
const heroIds = ref([])
const savingHero = ref(false)
// True when the legacy single-media hero (picked from a project) still drives
// the landing page — cleared on the first save from this page.
const legacyHero = ref(false)

async function load() {
  try {
    const settings = await appSettingsApi.get()
    whatsapp.value = settings.website_whatsapp ?? ''
    email.value = settings.website_email ?? ''
    const lines = (settings.website_phones ?? '').split(/[\n,]+/).map((p) => p.trim()).filter(Boolean)
    phones.value = lines.length ? lines : ['']
    for (const s of SOCIALS) socials.value[s.key] = settings[s.key] ?? ''
    about.value = {
      en: settings.website_about_en ?? '',
      fr: settings.website_about_fr ?? '',
      ar: settings.website_about_ar ?? '',
    }
    heroIds.value = (settings.website_hero_media_ids ?? '')
      .split(',')
      .map((id) => Number(id))
      .filter(Boolean)
    legacyHero.value = !heroIds.value.length && !!settings.website_hero_media_id
  } catch {
    toastError(t('settings.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(load)
useRefreshable(load)

async function saveContact() {
  savingContact.value = true
  try {
    await appSettingsApi.save({
      website_whatsapp: whatsapp.value.trim() || null,
      website_email: email.value.trim() || null,
      website_phones: phones.value.map((p) => p.trim()).filter(Boolean).join('\n') || null,
    })
    toastSuccess(t('settings.saved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('settings.saveFailed'))
  } finally {
    savingContact.value = false
  }
}

async function saveSocials() {
  for (const s of SOCIALS) {
    const url = socials.value[s.key].trim()
    if (url && !/^https?:\/\//i.test(url)) {
      toastError(t('settings.mapsUrlInvalid'))
      return
    }
  }
  savingSocials.value = true
  try {
    await appSettingsApi.save(
      Object.fromEntries(SOCIALS.map((s) => [s.key, socials.value[s.key].trim() || null])),
    )
    toastSuccess(t('settings.saved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('settings.saveFailed'))
  } finally {
    savingSocials.value = false
  }
}

async function saveAbout() {
  savingAbout.value = true
  try {
    await appSettingsApi.save({
      website_about_en: about.value.en.trim() || null,
      website_about_fr: about.value.fr.trim() || null,
      website_about_ar: about.value.ar.trim() || null,
    })
    toastSuccess(t('settings.saved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('settings.saveFailed'))
  } finally {
    savingAbout.value = false
  }
}

async function saveHero() {
  savingHero.value = true
  try {
    await appSettingsApi.save({
      website_hero_media_ids: heroIds.value.join(',') || null,
      // This page is now the hero's single source — retire the legacy
      // picked-from-a-project media id so clearing the selection means
      // "default cover hero", not a surprise fallback.
      website_hero_media_id: null,
    })
    legacyHero.value = false
    toastSuccess(t('settings.saved'))
  } catch (e) {
    toastError(e.response?.data?.message ?? t('settings.saveFailed'))
  } finally {
    savingHero.value = false
  }
}
</script>

<template>
  <div>
    <PageHeader :title="$t('settings.website')" :subtitle="$t('settings.websiteSubtitle')" />

    <!-- Contact channels -->
    <SectionCard :title="$t('settings.websiteContact')" icon="pi pi-phone">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="saveContact">
        <div>
          <BaseInput v-model="whatsapp" :label="$t('settings.websiteWhatsappLabel')" maxlength="40" />
          <p class="mt-1.5 text-xs text-mute">{{ $t('settings.websiteWhatsappHint') }}</p>
        </div>
        <BaseInput v-model="email" :label="$t('settings.websiteEmailLabel')" type="email" maxlength="120" />

        <div>
          <span class="text-sm font-medium text-ink">{{ $t('settings.websitePhonesLabel') }}</span>
          <div v-for="(phone, i) in phones" :key="i" class="mt-2 flex items-center gap-2">
            <BaseInput
              :model-value="phone"
              class="flex-1"
              maxlength="40"
              :placeholder="$t('settings.websitePhonePlaceholder')"
              @update:model-value="phones[i] = $event"
            />
            <Button
              v-if="phones.length > 1"
              icon="pi pi-times"
              text
              rounded
              severity="danger"
              :aria-label="$t('common.remove')"
              @click="phones.splice(i, 1)"
            />
          </div>
          <Button
            :label="$t('settings.websiteAddPhone')"
            icon="pi pi-plus"
            text
            size="small"
            class="mt-2"
            @click="phones.push('')"
          />
          <p class="mt-1.5 text-xs text-mute">{{ $t('settings.websitePhonesHint') }}</p>
        </div>

        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="savingContact" />
      </form>
    </SectionCard>

    <!-- Social profiles -->
    <SectionCard :title="$t('settings.websiteSocials')" icon="pi pi-share-alt" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="saveSocials">
        <p class="text-xs text-mute">{{ $t('settings.websiteSocialsHint') }}</p>
        <div v-for="s in SOCIALS" :key="s.key" class="flex items-center gap-3">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-highlight text-primary-600 dark:text-primary-400">
            <i :class="s.icon" aria-hidden="true" />
          </span>
          <BaseInput
            v-model="socials[s.key]"
            :label="s.label"
            type="url"
            maxlength="500"
            :placeholder="s.placeholder"
            class="flex-1"
          />
        </div>
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="savingSocials" />
      </form>
    </SectionCard>

    <!-- About us (trilingual) -->
    <SectionCard :title="$t('settings.websiteAboutLabel')" icon="pi pi-align-left" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <form v-else class="max-w-md space-y-4" @submit.prevent="saveAbout">
        <div>
          <div class="mb-2 flex items-center gap-1">
            <button
              v-for="lang in ['fr', 'ar', 'en']"
              :key="lang"
              type="button"
              class="flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase transition-colors"
              :class="aboutLang === lang
                ? 'bg-primary-500 text-primary-contrast'
                : 'bg-surface-100 text-mute hover:text-ink dark:bg-surface-800'"
              @click="aboutLang = lang"
            >
              {{ lang }}
              <!-- Fill state: a language the visitor would miss shows hollow -->
              <span
                class="h-1.5 w-1.5 rounded-full"
                :class="about[lang].trim() ? 'bg-success' : 'border border-current opacity-60'"
                aria-hidden="true"
              />
            </button>
          </div>
          <BaseTextarea v-model="about[aboutLang]" :rows="5" :maxlength="5000" :dir="aboutLang === 'ar' ? 'rtl' : 'ltr'" />
          <p class="mt-1.5 text-xs text-mute">{{ $t('settings.websiteAboutHint') }}</p>
        </div>
        <Button type="submit" :label="$t('common.save')" icon="pi pi-check" :loading="savingAbout" />
      </form>
    </SectionCard>

    <!-- Landing hero -->
    <SectionCard :title="$t('settings.websiteHeroTitle')" icon="pi pi-image" class="mt-6">
      <p v-if="loading" class="text-sm text-mute">{{ $t('common.loading') }}</p>
      <div v-else class="max-w-xl space-y-4">
        <p class="text-xs text-mute">{{ $t('settings.websiteHeroIntro') }}</p>
        <p v-if="legacyHero" class="rounded-lg bg-highlight px-3 py-2 text-xs text-ink">
          <i class="pi pi-info-circle me-1 text-primary-500" aria-hidden="true" />
          {{ $t('settings.websiteHeroLegacy') }}
        </p>
        <HeroLibraryManager v-model:selected-ids="heroIds" />
        <Button :label="$t('common.save')" icon="pi pi-check" :loading="savingHero" @click="saveHero" />
      </div>
    </SectionCard>
  </div>
</template>
