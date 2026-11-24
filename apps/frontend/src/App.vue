<script setup lang="ts">
import { ToastProvider, TooltipProvider } from 'reka-ui'
import { RouterView } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiToaster from './components/ui/UiToaster.vue'
import ToastBridge from './components/ToastBridge.vue'

const { t } = useI18n()
</script>

<template>
  <TooltipProvider :delay-duration="400" :skip-delay-duration="300">
    <ToastProvider>
      <a class="skip-link" href="#main">{{ t('common.skipToContent') }}</a>
      <RouterView />
      <UiToaster />
      <ToastBridge />
    </ToastProvider>
  </TooltipProvider>
</template>
