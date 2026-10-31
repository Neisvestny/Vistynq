<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <RouterLink class="brand" to="/" aria-label="Vistynq — на главную">
    <span class="brand__mark" aria-hidden="true">
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path d="M6 7 12 17 18 7" />
        <circle cx="6" cy="7" r="2" fill="currentColor" stroke="none" />
        <circle cx="18" cy="7" r="2" fill="currentColor" stroke="none" />
        <circle cx="12" cy="17" r="2" fill="currentColor" stroke="none" />
      </svg>
    </span>
    Vistynq
  </RouterLink>
</template>
