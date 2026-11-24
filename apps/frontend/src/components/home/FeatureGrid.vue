<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import UiCard from '../ui/UiCard.vue'
import { LayoutGrid, Link2, Share2, Tags } from 'lucide-vue-next'

const { t } = useI18n()

const features = [
  { icon: LayoutGrid, title: t('home.features.0.title'), text: t('home.features.0.text') },
  { icon: Link2, title: t('home.features.1.title'), text: t('home.features.1.text') },
  { icon: Tags, title: t('home.features.2.title'), text: t('home.features.2.text') },
  { icon: Share2, title: t('home.features.3.title'), text: t('home.features.3.text') },
]
</script>

<template>
  <section id="features" class="section">
    <div class="container">
      <header class="section__head">
        <h2>{{ t('home.featuresTitle') }}</h2>
        <p class="section__lead">{{ t('home.featuresLead') }}</p>
      </header>

      <div class="features">
        <UiCard v-for="feature in features" :key="feature.title" class="feature">
          <h3>
            <component :is="feature.icon" :size="20" aria-hidden="true" />
            {{ feature.title }}
          </h3>
          <p>{{ feature.text }}</p>
        </UiCard>
      </div>
    </div>
  </section>
</template>
