<script setup lang="ts">
const links = [
  { label: 'Документация', href: 'https://github.com/Neisvestny/Vistynq/tree/main/docs' },
  { label: 'Описание API', href: 'https://github.com/Neisvestny/Vistynq/blob/main/docs/api.md' },
  {
    label: 'Оформление и интерфейс',
    href: 'https://github.com/Neisvestny/Vistynq/blob/main/docs/ui-ux.md',
  },
  { label: 'Планы', href: 'https://github.com/Neisvestny/Vistynq/blob/main/docs/ROADMAP.md' },
]
</script>

<template>
  <footer class="site-footer">
    <div class="container site-footer__inner">
      <span class="site-footer__meta">© 2026 Vistynq — заметки и связи между ними</span>

      <nav class="site-footer__links" aria-label="Ссылки проекта">
        <a
          v-for="link in links"
          :key="link.href"
          :href="link.href"
          target="_blank"
          rel="noopener noreferrer"
        >
          {{ link.label }}
        </a>
      </nav>

      <span class="site-footer__meta">Сделано с заботой о ваших идеях</span>
    </div>
  </footer>
</template>
