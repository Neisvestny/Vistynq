<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core'

defineProps<{ id: string }>()
</script>

<template>
  <div class="dangling-anchor" aria-hidden="true">
    <Handle type="target" :position="Position.Left" class="dangling-anchor__handle" />
  </div>
</template>

<style scoped>
.dangling-anchor {
  width: 1px;
  height: 1px;
  opacity: 0;
  pointer-events: none;
}

.dangling-anchor__handle {
  opacity: 0;
  pointer-events: none;
}
</style>
