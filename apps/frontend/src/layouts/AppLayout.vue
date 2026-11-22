<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import UiDropdownMenu, { type MenuItem } from '@/components/ui/UiDropdownMenu.vue'
import AppBrand from '@/components/AppBrand.vue'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()

const menuItems = computed<MenuItem[]>(() => [
  { label: t('nav.profile'), onSelect: () => void router.push({ name: 'profile' }) },
  {
    label: t('nav.logout'),
    onSelect: () => {
      void auth.logout().then(() => router.push({ name: 'home' }))
    },
  },
])
</script>

<template>
  <div class="page app-page">
    <header class="app-header">
      <div class="container app-header__inner">
        <AppBrand />

        <nav class="app-header__nav" :aria-label="t('nav.appNav')">
          <RouterLink :to="{ name: 'dashboard' }">{{ t('nav.canvases') }}</RouterLink>
        </nav>

        <div class="app-header__actions">
          <LanguageSwitcher />
          <UiDropdownMenu :items="menuItems">
            <template #trigger>
              <button type="button" class="app-header__user" :aria-label="t('nav.userMenu')">
                {{ auth.user?.displayName ?? t('nav.profile') }}
              </button>
            </template>
          </UiDropdownMenu>
        </div>
      </div>
    </header>

    <main class="page__main">
      <RouterView />
    </main>
  </div>
</template>
