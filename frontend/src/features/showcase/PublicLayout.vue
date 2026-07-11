<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import Button from 'primevue/button'
import LanguageSwitcher from '@/components/shell/LanguageSwitcher.vue'
import { useTheme } from '@/composables/useTheme'
import { useShowcaseStore } from './store'
import { pickLocalized } from './localized'
import WhatsAppFloat from './components/WhatsAppFloat.vue'

// The public site's chrome — deliberately NOT AppShell: no echo, no push, no
// presence, nothing authenticated ever boots under this layout.

const route = useRoute()
const showcase = useShowcaseStore()
const { isNight, toggle } = useTheme()

const scrolled = ref(false)
const menuOpen = ref(false)

function onScroll() {
  scrolled.value = window.scrollY > 24
}

onMounted(() => {
  onScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
  showcase.loadConfig()
})

onUnmounted(() => window.removeEventListener('scroll', onScroll))

// Over a hero image the bar floats transparent with white text; everywhere
// else (and once scrolled) it is a solid, blurred card.
const overHero = computed(() => route.meta.hero && !scrolled.value)

const company = computed(() => showcase.company)
const aboutText = computed(() => pickLocalized(showcase.config?.about))

const year = new Date().getFullYear()

const navLinks = [
  { to: { name: 'showcase.home' }, key: 'showcase.nav.home' },
  { to: { name: 'showcase.projects' }, key: 'showcase.nav.projects' },
  { to: { name: 'showcase.home', hash: '#about' }, key: 'showcase.nav.about' },
  { to: { name: 'showcase.home', hash: '#contact' }, key: 'showcase.nav.contact' },
]
</script>

<template>
  <div class="min-h-screen bg-ground text-ink">
    <!-- Topbar -->
    <header
      class="fixed inset-x-0 top-0 z-40 transition-all duration-300"
      :class="overHero
        ? 'bg-gradient-to-b from-black/70 via-black/30 to-transparent text-white'
        : 'border-b border-line bg-card/90 text-ink shadow-card backdrop-blur'"
    >
      <div class="mx-auto flex h-16 max-w-6xl items-center gap-3 px-4 sm:px-6">
        <RouterLink :to="{ name: 'showcase.home' }" class="flex shrink-0 items-center" @click="menuOpen = false">
          <img
            src="/logo-full.png"
            :alt="company.name || 'PLAZA PRO'"
            class="h-9 w-auto select-none"
            :class="overHero ? 'brand-mark-invert' : 'brand-mark-auto'"
            draggable="false"
          />
        </RouterLink>

        <nav class="ms-6 hidden items-center gap-1 md:flex">
          <RouterLink
            v-for="link in navLinks"
            :key="link.key"
            :to="link.to"
            class="rounded-full px-3 py-1.5 text-sm font-medium transition-colors"
            :class="overHero ? 'text-white/85 hover:bg-white/10 hover:text-white' : 'text-mute hover:bg-primary-500/10 hover:text-ink'"
          >
            {{ $t(link.key) }}
          </RouterLink>
        </nav>

        <span class="ms-auto flex items-center gap-1">
          <LanguageSwitcher />
          <Button
            :icon="isNight ? 'pi pi-sun' : 'pi pi-moon'"
            text
            rounded
            severity="secondary"
            :class="overHero ? '!text-white' : ''"
            :aria-label="isNight ? $t('shell.switchToDay') : $t('shell.switchToNight')"
            @click="toggle"
          />
          <RouterLink :to="{ name: 'showcase.projects' }" class="hidden sm:block">
            <Button :label="$t('showcase.nav.explore')" size="small" rounded />
          </RouterLink>
          <Button
            class="md:!hidden"
            :class="overHero ? '!text-white' : ''"
            :icon="menuOpen ? 'pi pi-times' : 'pi pi-bars'"
            text
            rounded
            severity="secondary"
            :aria-label="$t('showcase.nav.menu')"
            @click="menuOpen = !menuOpen"
          />
        </span>
      </div>

      <!-- Mobile menu -->
      <Transition name="showcase-menu">
        <nav
          v-if="menuOpen"
          class="border-t border-line bg-card px-4 pb-4 pt-2 text-ink shadow-card md:hidden"
        >
          <RouterLink
            v-for="link in navLinks"
            :key="link.key"
            :to="link.to"
            class="block rounded-lg px-3 py-2.5 text-sm font-medium text-ink hover:bg-primary-500/10"
            @click="menuOpen = false"
          >
            {{ $t(link.key) }}
          </RouterLink>
        </nav>
      </Transition>
    </header>

    <main>
      <RouterView />
    </main>

    <!-- Footer -->
    <footer class="border-t border-line bg-card">
      <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-3">
        <div>
          <img
            src="/logo-full.png"
            :alt="company.name || 'PLAZA PRO'"
            class="brand-mark-auto h-10 w-auto select-none"
            draggable="false"
          />
          <p v-if="aboutText" class="mt-4 line-clamp-4 text-sm leading-relaxed text-mute">
            {{ aboutText }}
          </p>
        </div>

        <div>
          <h3 class="text-sm font-semibold uppercase tracking-wider text-mute">
            {{ $t('showcase.footer.contact') }}
          </h3>
          <ul class="mt-4 space-y-3 text-sm">
            <li v-if="company.address" class="flex items-start gap-2.5">
              <i class="pi pi-map-marker mt-0.5 text-primary-500" aria-hidden="true" />
              <a
                v-if="company.maps_url"
                :href="company.maps_url"
                target="_blank"
                rel="noopener"
                class="hover:text-primary-500"
              >{{ company.address }}</a>
              <span v-else>{{ company.address }}</span>
            </li>
            <li v-if="company.phone" class="flex items-center gap-2.5">
              <i class="pi pi-phone text-primary-500" aria-hidden="true" />
              <a :href="`tel:${company.phone}`" class="ltr-data hover:text-primary-500">{{ company.phone }}</a>
            </li>
            <li v-if="company.email" class="flex items-center gap-2.5">
              <i class="pi pi-envelope text-primary-500" aria-hidden="true" />
              <a :href="`mailto:${company.email}`" class="ltr-data hover:text-primary-500">{{ company.email }}</a>
            </li>
          </ul>
        </div>

        <div>
          <h3 class="text-sm font-semibold uppercase tracking-wider text-mute">
            {{ $t('showcase.footer.explore') }}
          </h3>
          <ul class="mt-4 space-y-2.5 text-sm">
            <li v-for="link in navLinks" :key="link.key">
              <RouterLink :to="link.to" class="hover:text-primary-500">{{ $t(link.key) }}</RouterLink>
            </li>
          </ul>
          <div class="mt-5 flex items-center gap-2">
            <a
              v-if="company.facebook_url"
              :href="company.facebook_url"
              target="_blank"
              rel="noopener"
              class="flex h-9 w-9 items-center justify-center rounded-full border border-line text-mute transition-colors hover:border-primary-500 hover:text-primary-500"
              aria-label="Facebook"
            ><i class="pi pi-facebook" aria-hidden="true" /></a>
            <a
              v-if="company.instagram_url"
              :href="company.instagram_url"
              target="_blank"
              rel="noopener"
              class="flex h-9 w-9 items-center justify-center rounded-full border border-line text-mute transition-colors hover:border-primary-500 hover:text-primary-500"
              aria-label="Instagram"
            ><i class="pi pi-instagram" aria-hidden="true" /></a>
          </div>
        </div>
      </div>

      <div class="border-t border-line py-4">
        <p class="mx-auto max-w-6xl px-4 text-center text-xs text-mute sm:px-6">
          © {{ year }} {{ company.name || 'PLAZA PRO' }} — {{ $t('showcase.footer.rights') }}
        </p>
      </div>
    </footer>

    <WhatsAppFloat />
  </div>
</template>

<style scoped>
.showcase-menu-enter-active,
.showcase-menu-leave-active {
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.showcase-menu-enter-from,
.showcase-menu-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}
</style>
