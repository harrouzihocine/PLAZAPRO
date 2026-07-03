<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import { useAuthStore } from '@/features/settings/store'
import { toastError } from '@/composables/useConfirm'
import { useTheme } from '@/composables/useTheme'

const auth = useAuthStore()
const router = useRouter()
const { isNight, toggle } = useTheme()

const email = ref('')
const password = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (e) {
    toastError(e.response?.data?.message ?? 'Login failed. Check your credentials.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="relative flex min-h-screen bg-ground text-ink">
    <!-- Brand panel (desktop) -->
    <div
      class="relative hidden w-1/2 flex-col justify-between overflow-hidden bg-surface-950 p-12 lg:flex dark:bg-surface-900"
    >
      <div
        class="pointer-events-none absolute -right-40 -top-40 h-[480px] w-[480px] rounded-full bg-primary-500/20 blur-3xl"
        aria-hidden="true"
      />
      <div
        class="pointer-events-none absolute -bottom-48 -left-24 h-[420px] w-[420px] rounded-full bg-primary-500/10 blur-3xl"
        aria-hidden="true"
      />

      <div class="relative flex items-center gap-3">
        <span
          class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary text-xl font-bold text-primary-contrast"
        >
          P
        </span>
        <span class="text-lg font-bold tracking-wide text-white">
          PLAZA<span class="text-primary-400"> PRO</span>
        </span>
      </div>

      <div class="relative max-w-md">
        <h2 class="text-3xl font-semibold leading-snug text-white">
          Every client, every unit, every deal — one workspace.
        </h2>
        <p class="mt-4 text-sm leading-relaxed text-surface-300">
          Pipeline, inventory, payments and reporting for your sales team, with a full audit trail
          behind every action.
        </p>
      </div>

      <p class="relative text-xs text-surface-400">
        © {{ new Date().getFullYear() }} PLAZA PRO — internal sales platform
      </p>
    </div>

    <!-- Form panel -->
    <div class="flex flex-1 items-center justify-center p-6">
      <Button
        :icon="isNight ? 'pi pi-sun' : 'pi pi-moon'"
        text
        rounded
        severity="secondary"
        class="!absolute right-4 top-4"
        :aria-label="isNight ? 'Switch to day theme' : 'Switch to night theme'"
        @click="toggle"
      />

      <div class="w-full max-w-sm">
        <div class="mb-8 flex items-center gap-3 lg:hidden">
          <span
            class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary text-xl font-bold text-primary-contrast"
          >
            P
          </span>
          <span class="text-lg font-bold tracking-wide text-ink">
            PLAZA<span class="text-primary-600 dark:text-primary-400"> PRO</span>
          </span>
        </div>

        <h1 class="text-2xl font-semibold tracking-tight text-ink">Welcome back</h1>
        <p class="mt-1 text-sm text-mute">Sign in with your work account to continue.</p>

        <form class="mt-8 flex flex-col gap-5" @submit.prevent="submit">
          <div class="flex flex-col gap-1.5">
            <label for="login-email" class="text-sm font-medium text-ink">Email</label>
            <InputText
              id="login-email"
              v-model="email"
              type="email"
              autocomplete="username"
              placeholder="you@company.com"
              required
              fluid
            />
          </div>

          <div class="flex flex-col gap-1.5">
            <label for="login-password" class="text-sm font-medium text-ink">Password</label>
            <Password
              id="login-password"
              v-model="password"
              :feedback="false"
              toggle-mask
              autocomplete="current-password"
              placeholder="••••••••"
              required
              fluid
            />
          </div>

          <Button
            type="submit"
            :label="loading ? 'Signing in…' : 'Sign in'"
            icon="pi pi-arrow-right"
            icon-pos="right"
            :loading="loading"
            class="mt-1"
          />
        </form>

        <p class="mt-8 text-center text-xs text-mute">
          Access is provisioned by your administrator.
        </p>
      </div>
    </div>
  </div>
</template>
