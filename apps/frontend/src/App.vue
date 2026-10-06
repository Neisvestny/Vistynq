<script setup lang="ts">
import TheSiteHeader from './components/TheSiteHeader.vue'
import TheSiteFooter from './components/TheSiteFooter.vue'
import HomePage from './views/HomePage.vue'
</script>

<template>
  <div id="top" class="page">
    <TheSiteHeader />
    <main class="page__main">
      <HomePage />
    </main>
    <TheSiteFooter />
  </div>
</template>
