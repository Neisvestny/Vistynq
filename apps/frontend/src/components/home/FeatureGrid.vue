<script setup lang="ts">
import { FileText, Infinity as InfinityIcon, Link2, Search, Tags, Users } from 'lucide-vue-next'

const features = [
  {
    icon: InfinityIcon,
    title: 'Свободное поле',
    text: 'Заметки можно перетаскивать, менять их размер, приближать и отдалять — всё помещается перед глазами.',
  },
  {
    icon: Link2,
    title: 'Связи между заметками',
    text: 'Соединяйте записи стрелкой из меню или просто вставьте ссылку на другую заметку прямо в текст.',
  },
  {
    icon: Tags,
    title: 'Метки и важность',
    text: 'Размечайте заметки цветными метками и отмечайте, насколько они важны — нужное находится сразу.',
  },
  {
    icon: Users,
    title: 'Доступ к заметкам',
    text: 'Оставляйте всё личным, приглашайте других редактировать вместе или откройте ссылку только для чтения.',
  },
  {
    icon: FileText,
    title: 'Написание текста',
    text: 'Пишите с заголовками и списками, вставляйте картинки и звук, а ссылки на другие заметки подставляются сами.',
  },
  {
    icon: Search,
    title: 'Поиск и корзина',
    text: 'Поиск по заголовкам и меткам, скрытие неактивного и восстановление удалённого.',
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
          Шесть простых действий: создать, связать, разметить, настроить доступ, написать и найти.
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
