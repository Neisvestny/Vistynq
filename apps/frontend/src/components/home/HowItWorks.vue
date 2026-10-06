<script setup lang="ts">
const steps = [
  {
    num: '01',
    title: 'Создайте первые записи',
    text: 'Откройте пустое поле и начните писать: новая заметка появится там, где вы нажмёте.',
  },
  {
    num: '02',
    title: 'Пишите и связывайте',
    text: 'Свяжите заметки стрелкой из меню или вставьте ссылку прямо в текст — и они станут видны вместе.',
  },
  {
    num: '03',
    title: 'Делитесь и находите нужное',
    text: 'Откройте доступ нужным людям по ссылке и находите нужное по меткам и важности.',
  },
]
</script>

<template>
  <section id="how" class="section">
    <div class="container">
      <header class="section__head">
        <p class="kicker">Как это работает</p>
        <h2>Три шага от пустого поля до полной картины</h2>
      </header>

      <div class="steps">
        <article v-for="step in steps" :key="step.num" class="step">
          <span class="step__num">{{ step.num }}</span>
          <h3>{{ step.title }}</h3>
          <p>{{ step.text }}</p>
        </article>
      </div>
    </div>
  </section>
</template>
