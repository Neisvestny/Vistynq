<script setup lang="ts">
defineProps<{
  linking: boolean
  sourceTitle?: string | null
}>()

const emit = defineEmits<{
  cancel: []
}>()
</script>

<template>
  <div class="link-layer" aria-live="polite">
    <div v-if="linking" class="link-layer__banner">
      <span class="link-layer__text">
        Выберите заметку для связи<span v-if="sourceTitle"> от «{{ sourceTitle }}»</span>.
        <span class="link-layer__hint">Esc — отмена</span>
      </span>
      <button type="button" class="link-layer__cancel nodrag nopan" @click="emit('cancel')">
        Отменить
      </button>
    </div>
  </div>
</template>

<style scoped>
.link-layer {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 30;
}

.link-layer__banner {
  position: absolute;
  top: 0.75rem;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 0.75rem;
  max-width: min(720px, calc(100% - 2rem));
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--color-accent);
  border-radius: var(--radius-md, 10px);
  background: color-mix(
    in srgb,
    var(--color-surface-raised, #fff) 94%,
    var(--color-accent-soft) 6%
  );
  box-shadow: var(--shadow-soft, var(--shadow-md));
  color: var(--color-text);
  font-size: 0.875rem;
  pointer-events: auto;
}

.link-layer__text {
  min-width: 0;
}

.link-layer__hint {
  color: var(--color-text-muted);
}

.link-layer__cancel {
  flex: 0 0 auto;
  padding: 0.25rem 0.55rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-surface);
  color: var(--color-text);
  font-size: 0.8rem;
  cursor: pointer;
}

.link-layer__cancel:hover {
  border-color: var(--color-accent);
  color: var(--color-accent);
}
</style>
