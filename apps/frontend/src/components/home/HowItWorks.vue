<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const steps = computed(() =>
  [0, 1, 2].map((i) => ({
    num: t(`home.steps.${i}.num`),
    title: t(`home.steps.${i}.title`),
    text: t(`home.steps.${i}.text`),
  }))
)
</script>

<template>
  <section id="how" class="section">
    <div class="container">
      <header class="section__head">
        <p class="kicker">{{ t('home.howKicker') }}</p>
        <h2>{{ t('home.howTitle') }}</h2>
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
