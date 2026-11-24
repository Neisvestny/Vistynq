<script setup lang="ts">
defineProps<{
  tone?: 'neutral' | 'accent' | 'owner' | 'editor' | 'viewer' | 'public' | 'archive'
}>()
</script>

<template>
  <span class="ui-badge" :class="tone && tone !== 'neutral' ? `ui-badge--${tone}` : undefined">
    <slot />
  </span>
</template>
