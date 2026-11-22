<script setup lang="ts">
/**
 * Форма манифеста заметки (R28): заголовок, важность, теги, актуальность,
 * видимость и кнопка «Доступ». Не делает запросов — только emit-ит изменения.
 */
import { computed, ref } from 'vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import type { Tag } from '@/api/types'
import { validateManifest, type ManifestDraft } from '@/composables/useNoteDraft'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{
  modelValue: ManifestDraft
  tags: Tag[]
  canEdit: boolean
  canManageAccess: boolean
  canCreateTags: boolean
  authorId: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: ManifestDraft]
  'create-tag': [name: string]
  'open-access': []
}>()

const auth = useAuthStore()
const tagQuery = ref('')
const tagMenuOpen = ref(false)
const importanceLevels = [1, 2, 3, 4, 5] as const

const errors = computed(() => validateManifest(props.modelValue))
const titleError = computed(() =>
  errors.value.find((error) => error.toLocaleLowerCase('ru').includes('заголовок'))
)

const selectedTags = computed(() =>
  props.modelValue.tagIds
    .map((id) => props.tags.find((tag) => tag.id === id))
    .filter((tag): tag is Tag => Boolean(tag))
)

const availableTags = computed(() => {
  const query = tagQuery.value.trim().toLocaleLowerCase('ru')
  return props.tags.filter(
    (tag) =>
      !props.modelValue.tagIds.includes(tag.id) &&
      (query === '' || tag.name.toLocaleLowerCase('ru').includes(query))
  )
})

const newTagName = computed(() => tagQuery.value.trim())
const canCreateTag = computed(
  () =>
    props.canEdit &&
    props.canCreateTags &&
    newTagName.value.length > 0 &&
    !props.tags.some(
      (tag) => tag.name.toLocaleLowerCase('ru') === newTagName.value.toLocaleLowerCase('ru')
    )
)

function patch(partial: Partial<ManifestDraft>): void {
  emit('update:modelValue', { ...props.modelValue, ...partial })
}

function toggleTag(id: string): void {
  if (!props.canEdit) return
  const ids = props.modelValue.tagIds
  patch({ tagIds: ids.includes(id) ? ids.filter((tagId) => tagId !== id) : [...ids, id] })
}

function selectTag(id: string): void {
  toggleTag(id)
  tagMenuOpen.value = false
  tagQuery.value = ''
}

function createTag(): void {
  if (!canCreateTag.value) return
  emit('create-tag', newTagName.value)
  tagQuery.value = ''
  tagMenuOpen.value = false
}

function setVisibility(visibility: ManifestDraft['visibility']): void {
  if (!props.canManageAccess) return
  patch({ visibility })
}

function validate(): string[] {
  return errors.value
}

defineExpose({ validate })
</script>

<template>
  <form class="manifest-form" @submit.prevent>
    <label class="manifest-form__field">
      <span class="manifest-form__label">Заголовок</span>
      <UiInput
        :model-value="modelValue.title"
        :disabled="!canEdit"
        placeholder="Название заметки"
        :maxlength="200"
        @update:model-value="patch({ title: $event })"
      />
      <span v-if="titleError" class="manifest-form__error">{{ titleError }}</span>
    </label>

    <fieldset class="manifest-form__field" :disabled="!canEdit">
      <legend class="manifest-form__label">Важность</legend>
      <div class="manifest-form__segment">
        <button
          v-for="level in importanceLevels"
          :key="level"
          type="button"
          class="manifest-form__segment-button"
          :class="{ 'manifest-form__segment-button--active': modelValue.importance === level }"
          :aria-pressed="modelValue.importance === level"
          :title="`Важность ${level}`"
          @click="patch({ importance: level })"
        >
          {{ level }}
        </button>
      </div>
    </fieldset>

    <div class="manifest-form__field">
      <span class="manifest-form__label">Теги</span>
      <div class="manifest-form__tags">
        <span v-for="tag in selectedTags" :key="tag.id" class="manifest-form__chip">
          <span class="manifest-form__chip-dot" :style="{ background: tag.color }" />
          {{ tag.name }}
          <button
            v-if="canEdit"
            type="button"
            class="manifest-form__chip-remove"
            :aria-label="`Убрать тег ${tag.name}`"
            @click="toggleTag(tag.id)"
          >
            ✕
          </button>
        </span>
        <span v-if="selectedTags.length === 0" class="manifest-form__hint">Теги не заданы</span>
      </div>

      <div v-if="canEdit" class="manifest-form__tag-add">
        <UiInput
          v-model="tagQuery"
          placeholder="Найти или добавить тег…"
          @focus="tagMenuOpen = true"
        />
        <div v-if="tagMenuOpen && tagQuery.trim()" class="manifest-form__tag-menu">
          <button
            v-for="tag in availableTags"
            :key="tag.id"
            type="button"
            class="manifest-form__tag-option"
            @click="selectTag(tag.id)"
          >
            <span class="manifest-form__chip-dot" :style="{ background: tag.color }" />
            {{ tag.name }}
          </button>
          <button
            v-if="canCreateTag"
            type="button"
            class="manifest-form__tag-option manifest-form__tag-option--new"
            @click="createTag"
          >
            + Создать «{{ newTagName }}»
          </button>
        </div>
      </div>
    </div>

    <label class="manifest-form__toggle">
      <input
        type="checkbox"
        :checked="modelValue.isRelevant"
        :disabled="!canEdit"
        @change="patch({ isRelevant: ($event.target as HTMLInputElement).checked })"
      />
      <span>Актуальна</span>
    </label>

    <div class="manifest-form__field">
      <span class="manifest-form__label">Видимость</span>
      <div v-if="canManageAccess" class="manifest-form__segment">
        <button
          type="button"
          class="manifest-form__segment-button"
          :class="{ 'manifest-form__segment-button--active': modelValue.visibility === 'public' }"
          :aria-pressed="modelValue.visibility === 'public'"
          @click="setVisibility('public')"
        >
          Публичная
        </button>
        <button
          type="button"
          class="manifest-form__segment-button"
          :class="{ 'manifest-form__segment-button--active': modelValue.visibility === 'private' }"
          :aria-pressed="modelValue.visibility === 'private'"
          @click="setVisibility('private')"
        >
          Приватная
        </button>
      </div>
      <p v-else class="manifest-form__hint">
        {{ modelValue.visibility === 'public' ? 'Публичная' : 'Приватная' }}
      </p>
    </div>

    <div v-if="canManageAccess" class="manifest-form__access">
      <UiButton variant="ghost" size="sm" type="button" @click="emit('open-access')">
        Доступ…
      </UiButton>
    </div>

    <p class="manifest-form__author">Автор: {{ authorId === auth.user?.id ? 'вы' : authorId }}</p>
  </form>
</template>

<style scoped>
.manifest-form {
  display: grid;
  gap: 1rem;
  padding: 1rem;
}

.manifest-form__field {
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}

.manifest-form__label {
  display: block;
  margin-bottom: 0.35rem;
  padding: 0;
  color: var(--color-text-muted);
  font-size: 0.8rem;
  font-weight: 600;
}

.manifest-form__error {
  display: block;
  margin-top: 0.25rem;
  color: var(--color-danger, #b42318);
  font-size: 0.8rem;
}

.manifest-form__segment {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}

.manifest-form__segment-button {
  min-width: 2.1rem;
  padding: 0.35rem 0.6rem;
  border: 1px solid var(--color-border);
  border-radius: 7px;
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 0.85rem;
  cursor: pointer;
}

.manifest-form__segment-button--active {
  border-color: var(--color-accent);
  background: var(--color-surface-alt, var(--color-surface));
  color: var(--color-accent);
}

.manifest-form__segment-button:disabled {
  opacity: 0.5;
  cursor: default;
}

.manifest-form__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin-bottom: 0.4rem;
}

.manifest-form__chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.2rem 0.45rem;
  border: 1px solid var(--color-border);
  border-radius: 999px;
  font-size: 0.8rem;
}

.manifest-form__chip-dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
}

.manifest-form__chip-remove {
  padding: 0 0.1rem;
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
}

.manifest-form__tag-add {
  position: relative;
  max-width: 22rem;
}

.manifest-form__tag-menu {
  position: absolute;
  z-index: 5;
  top: 100%;
  left: 0;
  right: 0;
  max-height: 12rem;
  overflow: auto;
  padding: 0.25rem;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-surface-raised, var(--color-surface));
  box-shadow: var(--shadow-md);
}

.manifest-form__tag-option {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  width: 100%;
  padding: 0.35rem 0.45rem;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--color-text);
  font-size: 0.85rem;
  text-align: left;
  cursor: pointer;
}

.manifest-form__tag-option:hover {
  background: var(--color-surface-alt, rgba(0, 0, 0, 0.04));
}

.manifest-form__tag-option--new {
  color: var(--color-accent);
}

.manifest-form__toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
}

.manifest-form__hint {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.manifest-form__author {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.8rem;
}
</style>
