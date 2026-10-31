<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router'
import UiDropdownMenu, { type MenuItem } from '@/components/ui/UiDropdownMenu.vue'
import AppBrand from '@/components/AppBrand.vue'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()

const menuItems: MenuItem[] = [
  { label: 'Профиль', onSelect: () => void router.push({ name: 'profile' }) },
  {
    label: 'Выйти',
    onSelect: () => {
      void auth.logout().then(() => router.push({ name: 'home' }))
    },
  },
]
</script>

<template>
  <div class="page app-page">
    <header class="app-header">
      <div class="container app-header__inner">
        <AppBrand />

        <nav class="app-header__nav" aria-label="Навигация приложения">
          <RouterLink :to="{ name: 'dashboard' }">Канвасы</RouterLink>
        </nav>

        <div class="app-header__actions">
          <UiDropdownMenu :items="menuItems">
            <template #trigger>
              <button type="button" class="app-header__user" aria-label="Меню пользователя">
                {{ auth.user?.displayName ?? 'Профиль' }}
              </button>
            </template>
          </UiDropdownMenu>
        </div>
      </div>
    </header>

    <main class="page__main app-page__main">
      <RouterView />
    </main>
  </div>
</template>
