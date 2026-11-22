<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUiStore } from '@/stores/ui'
import type { AppLocale } from '@/i18n'

const { t } = useI18n()
const ui = useUiStore()

const options: Array<{ value: AppLocale; label: string }> = [
  { value: 'ru', label: 'RU' },
  { value: 'en', label: 'English' },
]

const current = computed(() => ui.locale)

function setLocale(locale: AppLocale) {
  ui.setLocale(locale)
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
      {{ option.label }}
    </button>
  </div>
</template>
