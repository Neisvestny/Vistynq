<script setup lang="ts">
defineProps<{
  tone?: 'neutral' | 'accent'
}>()
</script>

<template>
  <span class="ui-badge" :class="{ 'ui-badge--accent': tone === 'accent' }">
    <slot />
  </span>
</template>
