<script setup lang="ts">
import { ref, watch, type PropType } from 'vue'

export interface WikiLinkItem {
  id: string
  title: string
}

const props = defineProps({
  items: {
    type: Array as PropType<WikiLinkItem[]>,
    required: true,
  },
  command: {
    type: Function as PropType<(item: WikiLinkItem) => void>,
    required: true,
  },
})

const selectedIndex = ref(0)

watch(
  () => props.items,
  () => {
    selectedIndex.value = 0
  }
)

function selectItem(index: number) {
  const item = props.items[index]
  if (item) props.command(item)
}

function onKeyDown(event: KeyboardEvent): boolean {
  if (event.key === 'ArrowUp') {
    selectedIndex.value =
      (selectedIndex.value + props.items.length - 1) % Math.max(props.items.length, 1)
    return true
  }
  if (event.key === 'ArrowDown') {
    selectedIndex.value = (selectedIndex.value + 1) % Math.max(props.items.length, 1)
    return true
  }
  if (event.key === 'Enter') {
    selectItem(selectedIndex.value)
    return true
  }
  return false
}

defineExpose({ onKeyDown })
</script>

<template>
  <div class="wiki-link-list" role="listbox" aria-label="Заметки для ссылки">
    <button
      v-for="(item, index) in items"
      :key="item.id"
      type="button"
      class="wiki-link-list__item"
      :class="{ 'wiki-link-list__item--selected': index === selectedIndex }"
      role="option"
      :aria-selected="index === selectedIndex"
      @click="selectItem(index)"
      @mouseenter="selectedIndex = index"
    >
      <span class="wiki-link-list__title">{{ item.title || 'Без названия' }}</span>
    </button>
    <div v-if="items.length === 0" class="wiki-link-list__empty">Заметки не найдены</div>
  </div>
</template>

<style scoped>
.wiki-link-list {
  min-width: 220px;
  max-width: 360px;
  max-height: 240px;
  overflow: auto;
  padding: 0.25rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 10px);
  background: var(--color-surface-raised, #fff);
  box-shadow: var(--shadow-md);
}

.wiki-link-list__item {
  display: block;
  width: 100%;
  padding: 0.4rem 0.55rem;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  text-align: left;
  cursor: pointer;
}

.wiki-link-list__item:hover,
.wiki-link-list__item--selected {
  background: var(--color-accent-soft, rgba(59, 130, 246, 0.1));
  color: var(--color-accent);
}

.wiki-link-list__title {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.wiki-link-list__empty {
  padding: 0.5rem 0.55rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}
</style>
