<template>
  <section id="cta" class="cta">
    <div class="container cta__inner">
      <div>
        <h2>Соберите свои мысли в одном месте</h2>
        <p>
          Создавайте канвасы, связывайте заметки и возвращайтесь к ним в любой момент — всё уже
          готово к работе.
        </p>
      </div>

      <div class="cta__actions">
        <UiButton v-if="auth.isAuthenticated" size="lg" to="/dashboard">Открыть дашборд</UiButton>
        <template v-else>
          <UiButton size="lg" to="/register">Регистрация</UiButton>
          <UiButton variant="ghost" size="lg" to="/login">Войти</UiButton>
        </template>
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
