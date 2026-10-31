<script setup lang="ts">
import { ref } from 'vue'
import { Menu as MenuIcon } from 'lucide-vue-next'
import UiButton from './ui/UiButton.vue'
import UiSheet from './ui/UiSheet.vue'

const navLinks = [
  { label: 'Главная', href: '#top' },
  { label: 'Возможности', href: '#features' },
  { label: 'Как это работает', href: '#how' },
]

const sheetOpen = ref(false)

function closeSheet() {
  sheetOpen.value = false
}
</script>

<template>
  <header class="site-header">
    <div class="container site-header__inner">
      <a class="brand" href="/" aria-label="Vistynq — на главную">
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
      </a>

      <nav class="site-nav" aria-label="Основная навигация">
        <a v-for="link in navLinks" :key="link.href" :href="link.href">
          {{ link.label }}
        </a>
      </nav>

      <div class="site-header__actions">
        <UiButton variant="ghost" to="/login">Войти</UiButton>
        <UiButton to="/register">Начать</UiButton>
      </div>

      <UiSheet v-model:open="sheetOpen" class="site-header__sheet" side="right">
        <template #title>Меню</template>

        <nav class="site-sheet__nav" aria-label="Мобильная навигация">
          <a v-for="link in navLinks" :key="link.href" :href="link.href" @click="closeSheet">
            {{ link.label }}
          </a>
        </nav>

        <div class="site-sheet__actions">
          <UiButton variant="ghost" to="/login" @click="closeSheet">Войти</UiButton>
          <UiButton to="/register" @click="closeSheet">Начать</UiButton>
        </div>
      </UiSheet>

      <button
        class="site-header__burger"
        type="button"
        aria-label="Открыть меню"
        :aria-expanded="sheetOpen"
        @click="sheetOpen = !sheetOpen"
      >
        <MenuIcon :size="22" aria-hidden="true" />
      </button>
    </div>
  </header>
</template>
