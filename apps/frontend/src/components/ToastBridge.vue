<script setup lang="ts">
/**
 * Регистрирует notify-хендлер (src/api/notify.ts) в ToastProvider'е —
 * позволяет кидать тосты из store и router-guards вне компонентного контекста.
 */
import { useToast } from '@/composables/useToast'
import { setNotifyHandler } from '@/api/notify'

const { notify } = useToast()
setNotifyHandler(notify)
</script>

<template>
  <!-- Визуально ничего не рендерит. -->
  <span hidden />
</template>
