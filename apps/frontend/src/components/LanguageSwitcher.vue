<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUiStore } from '@/stores/ui'
import type { AppLocale } from '@/i18n'

const { t } = useI18n()
const ui = useUiStore()
const announcement = ref('')

const options: Array<{ value: AppLocale; labelKey: string }> = [
  { value: 'ru', labelKey: 'common.langRu' },
  { value: 'en', labelKey: 'common.langEn' },
]

const current = computed(() => ui.locale)

function setLocale(locale: AppLocale) {
  if (locale === ui.locale) return
  ui.setLocale(locale)
  announcement.value = t('common.languageChanged', {
    language: t(options.find((o) => o.value === locale)!.labelKey),
  })
}
</script>

<template>
  <div class="language-switcher" role="group" :aria-label="t('common.language')">
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      class="language-switcher__btn"
      :class="{ 'language-switcher__btn--active': current === option.value }"
      :aria-pressed="current === option.value"
      @click="setLocale(option.value)"
    >
      {{ t(option.labelKey) }}
    </button>
    <span class="sr-only" aria-live="polite" aria-atomic="true">{{ announcement }}</span>
  </div>
</template>
