<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import BaseButton from '@/components/base/BaseButton.vue'
import BaseCard from '@/components/base/BaseCard.vue'
import BaseInput from '@/components/base/BaseInput.vue'
import { useAuthStore } from '@/features/settings/store'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push({ name: 'dashboard' })
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Login failed. Check your credentials.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-bg p-4 text-ink">
    <BaseCard class="w-full max-w-sm">
      <h1 class="mb-1 text-center text-xl font-semibold text-primary">PLAZA PRO</h1>
      <p class="mb-6 text-center text-sm opacity-70">Sign in to continue</p>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <BaseInput v-model="email" label="Email" type="email" />
        <BaseInput v-model="password" label="Password" type="password" />
        <p v-if="error" class="text-sm text-danger">{{ error }}</p>
        <BaseButton type="submit" :disabled="loading">
          {{ loading ? 'Signing in…' : 'Sign in' }}
        </BaseButton>
      </form>
    </BaseCard>
  </div>
</template>
