<script setup lang="ts">
const steps = [
  {
    num: '01',
    title: 'Создайте канвас',
    text: 'Одна кнопка — и перед вами пустое поле с подсказкой «ПКМ — создать заметку».',
  },
  {
    num: '02',
    title: 'Пишите и связывайте',
    text: 'Заметки рождаются в точке клика, связи — одной командой меню или ссылкой [[...]] в тексте.',
  },
  {
    num: '03',
    title: 'Делитесь и фильтруйте',
    text: 'Пригласите редактора по email, откройте публичную ссылку, отфильтруйте по тегам и важности.',
  },
]
</script>

<template>
  <section id="how" class="section">
    <div class="container">
      <header class="section__head">
        <p class="kicker">Как это работает</p>
        <h2>Три шага от пустого поля до готового графа</h2>
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
