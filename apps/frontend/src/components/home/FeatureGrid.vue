<script setup lang="ts">
import { FileText, Infinity as InfinityIcon, Link2, Search, Tags, Users } from 'lucide-vue-next'

const features = [
  {
    icon: InfinityIcon,
    title: 'Бесконечный канвас',
    text: 'Заметка — прямоугольная нода с заголовком. Drag, resize, зум колесом к курсору и панорама.',
  },
  {
    icon: Link2,
    title: 'Связи любых типов',
    text: 'Сплошная стрелка «родитель → дочерняя» из меню и пунктир из [[wikilink]] в тексте заметки.',
  },
  {
    icon: Tags,
    title: 'Теги и важность',
    text: 'Цветные теги живут внутри канваса, шкала важности 1–5 красит обводку ноды.',
  },
  {
    icon: Users,
    title: 'Доступы и роли',
    text: 'Только я · приглашённые по email с ролями viewer/editor · публичная read-only ссылка.',
  },
  {
    icon: FileText,
    title: 'Markdown-заметки',
    text: 'Тело заметки — редактор с картинками, аудио-аттачментами и автодополнением [[.',
  },
  {
    icon: Search,
    title: 'Поиск и архив',
    text: 'Поиск по заголовкам и тегам, архив неактуальных и удалённых с восстановлением.',
  },
]
</script>

<template>
  <section id="features" class="section">
    <div class="container">
      <header class="section__head">
        <p class="kicker">Возможности</p>
        <h2>Всё, что нужно для работы с идеями</h2>
        <p class="section__lead">
          Шесть базовых механизмов: канвас, связи, разметка, доступы, редактор и навигация.
        </p>
      </header>

      <div class="features">
        <article v-for="feature in features" :key="feature.title" class="feature">
          <h3>
            <component :is="feature.icon" :size="18" aria-hidden="true" />
            {{ feature.title }}
          </h3>
          <p>{{ feature.text }}</p>
        </article>
      </div>
    </div>
  </section>
</template>
