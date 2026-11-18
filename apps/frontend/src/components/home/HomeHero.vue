<template>
  <section class="hero">
    <div class="container hero__inner">
      <p class="kicker">Пространство для ваших идей</p>

      <h1>Мысли на одной <span class="accent">карте</span></h1>

      <p class="lead">
        Все ваши заметки и связи между ними видны сразу. Создавайте записи, соединяйте их и смотрите
        на картину целиком — а не прокручивайте бесконечную ленту.
      </p>

      <div class="hero__actions">
        <UiButton v-if="auth.isAuthenticated" size="lg" to="/dashboard">Открыть дашборд</UiButton>
        <template v-else>
          <UiButton size="lg" to="/register">Регистрация</UiButton>
          <UiButton variant="ghost" size="lg" to="/login">Войти</UiButton>
        </template>
        <UiButton variant="ghost" size="lg" href="#how">Как это работает</UiButton>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import UiButton from '../ui/UiButton.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

onMounted(() => {
  void auth.ensureRestored()
})
</script>
