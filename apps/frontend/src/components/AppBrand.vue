<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { Network } from 'lucide-vue-next'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <RouterLink class="brand" to="/" :aria-label="t('nav.homeAria')">
    <span class="brand__mark" aria-hidden="true">
      <Network :size="18" aria-hidden="true" />
    </span>
    <span class="brand__wordmark">{{ t('common.brand') }}</span>
  </RouterLink>
</template>
