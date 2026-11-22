<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { Menu as MenuIcon } from 'lucide-vue-next'
import { useI18n } from 'vue-i18n'
import UiButton from './ui/UiButton.vue'
import UiSheet from './ui/UiSheet.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'

const { t } = useI18n()
const ui = useUiStore()
const auth = useAuthStore()

const navLinks = computed(() => [
  { label: t('nav.home'), href: '#top' },
  { label: t('nav.features'), href: '#features' },
  { label: t('nav.how'), href: '#how' },
])

onMounted(() => {
  void auth.ensureRestored()
})

function closeSheet() {
  ui.closeMenu()
}
</script>

<template>
  <header class="site-header">
    <div class="container site-header__inner">
      <a class="brand" href="/" :aria-label="t('nav.homeAria')">
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
          </svg>
        </span>
        <span class="brand__wordmark">Vistynq</span>
      </a>

      <nav class="site-header__nav" :aria-label="t('nav.appNav')">
        <a v-for="link in navLinks" :key="link.href" :href="link.href">{{ link.label }}</a>
      </nav>

      <div class="site-header__actions">
        <LanguageSwitcher />
        <template v-if="auth.isAuthenticated">
          <UiButton to="/dashboard" variant="ghost" size="sm">{{ t('nav.dashboard') }}</UiButton>
        </template>
        <template v-else>
          <UiButton to="/login" variant="ghost" size="sm">{{ t('nav.login') }}</UiButton>
          <UiButton to="/register" size="sm">{{ t('nav.register') }}</UiButton>
        </template>

        <button
          type="button"
          class="site-header__menu-btn"
          :aria-label="t('common.openMenu')"
          :aria-expanded="ui.mobileMenuOpen"
          @click="ui.toggleMenu()"
        >
          <MenuIcon :size="20" aria-hidden="true" />
        </button>
      </div>
    </div>

    <UiSheet
      :open="ui.mobileMenuOpen"
      :title="t('landing.menuTitle')"
      @update:open="
        (value) => {
          if (!value) closeSheet()
        }
      "
    >
      <div class="site-header__sheet-body">
        <LanguageSwitcher />
        <nav class="site-header__sheet-nav" :aria-label="t('nav.appNav')">
          <a v-for="link in navLinks" :key="link.href" :href="link.href" @click="closeSheet">
            {{ link.label }}
          </a>
        </nav>

        <div class="site-header__sheet-actions">
          <template v-if="auth.isAuthenticated">
            <UiButton to="/dashboard" variant="ghost" @click="closeSheet">{{
              t('nav.dashboard')
            }}</UiButton>
          </template>
          <template v-else>
            <UiButton to="/login" variant="ghost" @click="closeSheet">{{
              t('nav.login')
            }}</UiButton>
            <UiButton to="/register" @click="closeSheet">{{ t('nav.register') }}</UiButton>
          </template>
        </div>
      </div>
    </UiSheet>
  </header>
</template>
