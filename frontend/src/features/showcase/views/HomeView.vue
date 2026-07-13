<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import { useI18n } from 'vue-i18n'
import { useShowcaseStore } from '../store'
import { pickLocalized } from '../localized'
import { useSeoMeta } from '../composables/useSeoMeta'
import { track } from '../composables/useTracker'
import HeroSection from '../components/HeroSection.vue'
import ProjectCard from '../components/ProjectCard.vue'
import LeadForm from '../components/LeadForm.vue'

const showcase = useShowcaseStore()
const { t } = useI18n()

onMounted(() => {
  showcase.loadConfig()
  if (showcase.projects === null) showcase.loadProjects()
})

const projects = computed(() => showcase.projects ?? [])
const featured = computed(() => projects.value.slice(0, 3))
const aboutText = computed(() => pickLocalized(showcase.config?.about))
const company = computed(() => showcase.company)

// The "Who we are" mosaic: the photos curated in Settings → Website when the
// owner picked some (first pick renders wide, so it gets the full-size file);
// featured-project covers otherwise.
const aboutPics = computed(() => {
  const curated = (showcase.config?.about_media ?? []).map((m, i) => ({
    key: `curated-${i}`,
    src: i === 0 ? m.image_url : m.thumb_url || m.image_url,
    alt: company.value.name || 'PLAZA PRO',
  }))
  if (curated.length) return curated
  return featured.value
    .filter((p) => p.cover)
    .slice(0, 4)
    .map((p) => ({ key: `project-${p.id}`, src: p.cover.thumb_url, alt: p.name }))
})

useSeoMeta(() => ({
  title: `${company.value.name || 'PLAZA PRO'} — ${t('showcase.seo.homeTitle')}`,
  description: aboutText.value || t('showcase.hero.subtitle'),
  image: featured.value.find((p) => p.cover)?.cover.file_url,
  // The company as structured data: search engines link the site, the phone
  // numbers and the social profiles into one entity card.
  jsonLd: {
    '@context': 'https://schema.org',
    '@type': 'RealEstateAgent',
    name: company.value.name || 'PLAZA PRO',
    url: window.location.origin,
    telephone: showcase.phones[0] || undefined,
    email: company.value.email || undefined,
    address: company.value.address
      ? { '@type': 'PostalAddress', streetAddress: company.value.address, addressCountry: 'DZ' }
      : undefined,
    sameAs: showcase.socialLinks.map((s) => s.url),
  },
}))
</script>

<template>
  <div>
    <HeroSection :projects="projects" />

    <!-- Featured projects -->
    <section id="featured" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6">
      <div class="flex items-end justify-between gap-4">
        <div>
          <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
            {{ $t('showcase.featured.kicker') }}
          </p>
          <h2 class="mt-2 text-3xl font-bold text-ink">{{ $t('showcase.featured.title') }}</h2>
        </div>
        <RouterLink v-if="projects.length > 3" :to="{ name: 'showcase.projects' }" class="hidden sm:block">
          <Button :label="$t('showcase.featured.viewAll')" text icon="pi pi-arrow-right" icon-pos="right" />
        </RouterLink>
      </div>

      <div v-if="featured.length" class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        <ProjectCard v-for="project in featured" :key="project.id" :project="project" />
      </div>
      <p v-else class="mt-8 rounded-xl border border-line bg-card p-8 text-center text-mute">
        {{ $t('showcase.featured.comingSoon') }}
      </p>

      <RouterLink v-if="projects.length > 3" :to="{ name: 'showcase.projects' }" class="mt-6 block sm:hidden">
        <Button :label="$t('showcase.featured.viewAll')" outlined fluid />
      </RouterLink>
    </section>

    <!-- Didn't find it? Describe it — the desire wizard -->
    <section class="bg-gradient-to-br from-primary-600 to-primary-800 dark:from-primary-700 dark:to-primary-950">
      <div class="mx-auto flex max-w-6xl flex-col items-center gap-6 px-4 py-14 text-center sm:px-6 md:flex-row md:text-start">
        <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/15 text-white">
          <i class="pi pi-search text-2xl" aria-hidden="true" />
        </span>
        <div class="md:flex-1">
          <h2 class="text-2xl font-bold text-white">{{ $t('showcase.desire.bannerTitle') }}</h2>
          <p class="mt-1.5 text-white/80">{{ $t('showcase.desire.bannerBody') }}</p>
        </div>
        <RouterLink :to="{ name: 'showcase.desire' }" class="shrink-0">
          <Button
            :label="$t('showcase.desire.bannerCta')"
            severity="contrast"
            rounded
            icon="pi pi-arrow-right"
            icon-pos="right"
          />
        </RouterLink>
      </div>
    </section>

    <!-- About -->
    <section id="about" class="scroll-mt-20 border-y border-line bg-card">
      <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-20 sm:px-6 md:grid-cols-2">
        <div>
          <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
            {{ $t('showcase.about.kicker') }}
          </p>
          <h2 class="mt-2 text-3xl font-bold text-ink">
            {{ company.name || 'PLAZA PRO' }}
          </h2>
          <p class="mt-5 whitespace-pre-line text-base leading-relaxed text-mute">
            {{ aboutText || $t('showcase.about.fallback') }}
          </p>

          <ul class="mt-8 space-y-4">
            <li v-for="n in 3" :key="n" class="flex items-start gap-3">
              <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-500/15 text-primary-500">
                <i :class="['pi', ['pi-verified', 'pi-home', 'pi-users'][n - 1]]" aria-hidden="true" />
              </span>
              <div>
                <p class="font-semibold text-ink">{{ $t(`showcase.about.point${n}Title`) }}</p>
                <p class="text-sm text-mute">{{ $t(`showcase.about.point${n}Body`) }}</p>
              </div>
            </li>
          </ul>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div
            v-for="(pic, i) in aboutPics"
            :key="pic.key"
            class="overflow-hidden rounded-2xl"
            :class="i === 0 ? 'col-span-2 aspect-[2/1]' : 'aspect-square'"
          >
            <img
              :src="pic.src"
              :alt="pic.alt"
              class="h-full w-full object-cover transition-transform duration-500 hover:scale-105"
              loading="lazy"
            />
          </div>
        </div>
      </div>
    </section>

    <!-- Contact / callback -->
    <section id="contact" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6">
      <div class="grid gap-10 md:grid-cols-2">
        <div>
          <p class="text-sm font-semibold uppercase tracking-widest text-primary-500">
            {{ $t('showcase.contact.kicker') }}
          </p>
          <h2 class="mt-2 text-3xl font-bold text-ink">{{ $t('showcase.contact.title') }}</h2>
          <p class="mt-4 text-mute">{{ $t('showcase.contact.body') }}</p>

          <ul class="mt-8 space-y-4 text-sm">
            <li v-for="phone in showcase.phones" :key="phone" class="flex items-center gap-3">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-500/15 text-primary-500">
                <i class="pi pi-phone" aria-hidden="true" />
              </span>
              <a
                :href="`tel:${phone}`"
                class="ltr-data font-medium text-ink hover:text-primary-500"
                @click="track('phone_click')"
              >{{ phone }}</a>
            </li>
            <li v-if="company.address" class="flex items-center gap-3">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-500/15 text-primary-500">
                <i class="pi pi-map-marker" aria-hidden="true" />
              </span>
              <a v-if="company.maps_url" :href="company.maps_url" target="_blank" rel="noopener" class="font-medium text-ink hover:text-primary-500">
                {{ company.address }}
              </a>
              <span v-else class="font-medium text-ink">{{ company.address }}</span>
            </li>
            <li v-if="company.email" class="flex items-center gap-3">
              <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-500/15 text-primary-500">
                <i class="pi pi-envelope" aria-hidden="true" />
              </span>
              <a :href="`mailto:${company.email}`" class="ltr-data font-medium text-ink hover:text-primary-500">{{ company.email }}</a>
            </li>
          </ul>
        </div>

        <div class="rounded-2xl border border-line bg-card p-6 shadow-card sm:p-8">
          <h3 class="text-lg font-semibold text-ink">{{ $t('showcase.contact.formTitle') }}</h3>
          <p class="mt-1 text-sm text-mute">{{ $t('showcase.contact.formHint') }}</p>
          <LeadForm class="mt-6" type="callback" />
        </div>
      </div>
    </section>
  </div>
</template>
