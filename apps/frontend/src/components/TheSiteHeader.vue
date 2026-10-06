<script setup lang="ts">
const docsUrl = 'https://github.com/Neisvestny/Vistynq/tree/main/docs'
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
        <a href="#features">Возможности</a>
        <a href="#how">Как это работает</a>
        <a :href="docsUrl" target="_blank" rel="noopener noreferrer">Документация</a>
      </nav>

      <div class="site-header__actions">
        <a class="btn btn--ghost" href="#cta">Войти</a>
        <a class="btn btn--primary" href="#cta">Начать</a>
      </div>
    </div>
  </header>
</template>
