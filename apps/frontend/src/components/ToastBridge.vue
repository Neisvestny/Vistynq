<script setup lang="ts">
/**
 * Registriruet notify-khendler (src/api/notify.ts) v ToastProvider'e —
 * pozvolyaet kidat tosty iz store i router-guards vne komponentnogo konteksta.
 */
import { onUnmounted } from 'vue'
import { useToast } from '@/composables/useToast'
import { setNotifyHandler } from '@/api/notify'

const { notify } = useToast()
setNotifyHandler(notify)

onUnmounted(() => setNotifyHandler(null))
</script>

<template>
  <!-- Vizualno nichego ne renderit. -->
  <span hidden />
</template>
