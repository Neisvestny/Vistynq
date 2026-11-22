<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const links = computed(() => [
  { label: t('landing.docs'), href: 'https://github.com/Neisvestny/Vistynq/tree/main/docs' },
  { label: t('landing.api'), href: 'https://github.com/Neisvestny/Vistynq/blob/main/docs/api.md' },
  {
    label: t('landing.uiux'),
    href: 'https://github.com/Neisvestny/Vistynq/blob/main/docs/ui-ux.md',
  },
  {
    label: t('landing.roadmap'),
    href: 'https://github.com/Neisvestny/Vistynq/blob/main/docs/ROADMAP.md',
  },
])
</script>

<template>
  <footer class="site-footer">
    <div class="container site-footer__inner">
      <span class="site-footer__meta">{{ t('landing.footerRights') }}</span>

      <nav class="site-footer__links" :aria-label="t('nav.projectLinks')">
        <a
          v-for="link in links"
          :key="link.href"
          :href="link.href"
          target="_blank"
          rel="noopener noreferrer"
        >
          {{ link.label }}
        </a>
      </nav>

      <span class="site-footer__meta">{{ t('landing.footerTagline') }}</span>
    </div>
  </footer>
</template>
