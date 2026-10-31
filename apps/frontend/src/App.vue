<script setup lang="ts">
import { ToastProvider, TooltipProvider } from 'reka-ui'
import { RouterView } from 'vue-router'
import UiToaster from './components/ui/UiToaster.vue'
import ToastBridge from './components/ToastBridge.vue'
</script>

<template>
  <TooltipProvider :delay-duration="400" :skip-delay-duration="300">
    <ToastProvider>
      <RouterView />
      <UiToaster />
      <ToastBridge />
    </ToastProvider>
  </TooltipProvider>
</template>
