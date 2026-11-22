<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UiCard from '../ui/UiCard.vue'
import { FileText, Infinity as InfinityIcon, Link2, Search, Tags, Users } from 'lucide-vue-next'

const { t } = useI18n()

const icons = [InfinityIcon, Link2, Tags, Users, FileText, Search]

const features = computed(() =>
  icons.map((icon, i) => ({
    icon,
    title: t(`home.features.${i}.title`),
    text: t(`home.features.${i}.text`),
  }))
)
</script>

<template>
  <section id="features" class="section">
    <div class="container">
      <header class="section__head">
        <p class="kicker">{{ t('home.kickerFeatures') }}</p>
        <h2>{{ t('home.featuresTitle') }}</h2>
        <p class="section__lead">{{ t('home.featuresLead') }}</p>
      </header>

      <div class="features">
        <UiCard v-for="feature in features" :key="feature.title" class="feature">
          <h3>
            <component :is="feature.icon" :size="18" aria-hidden="true" />
            {{ feature.title }}
          </h3>
          <p>{{ feature.text }}</p>
        </UiCard>
      </div>
    </div>
  </section>
</template>
