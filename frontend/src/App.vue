<script setup>
import { onMounted } from 'vue'
import { RouterView } from 'vue-router'
import { useTheme } from '@/composables/useTheme'

onMounted(() => {
  useTheme().init()
})
</script>

<template>
  <RouterView />
</template>
