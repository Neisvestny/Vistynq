<script setup lang="ts">
import { RouterView } from 'vue-router'
import AppBrand from '@/components/AppBrand.vue'
</script>

<template>
  <div class="auth-layout">
    <header class="auth-layout__header">
      <AppBrand />
    </header>

    <main class="auth-layout__main">
      <div class="ui-card auth-layout__card">
        <RouterView />
      </div>
    </main>
  </div>
</template>
