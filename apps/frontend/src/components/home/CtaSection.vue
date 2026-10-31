<template>
  <section id="cta" class="cta">
    <div class="container cta__inner">
      <div>
        <h2>Соберите свои мысли в одном месте</h2>
        <p>
          Первая заметка займёт пару секунд. Вход и регистрация подключатся чуть позже — пока можно
          спокойно осмотреться.
        </p>
      </div>

      <div class="cta__actions">
        <UiButton size="lg" to="/register">Начать</UiButton>
        <UiButton variant="ghost" size="lg" to="/login">Войти</UiButton>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import UiButton from '../ui/UiButton.vue'
</script>
