<script setup lang="ts">
import { RouterView } from 'vue-router'
import TheSiteHeader from '@/components/TheSiteHeader.vue'
import TheSiteFooter from '@/components/TheSiteFooter.vue'
</script>

<template>
  <div id="top" class="page">
    <TheSiteHeader />
    <main id="main" class="page__main" tabindex="-1">
      <RouterView />
    </main>
    <TheSiteFooter />
  </div>
</template>
