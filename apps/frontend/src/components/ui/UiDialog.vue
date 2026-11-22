<script setup lang="ts">
import { computed, ref } from 'vue'
import {
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
  DialogTrigger,
} from 'reka-ui'

const props = defineProps<{
  title?: string
  description?: string
  /** Controlled mode. If omitted, dialog manages its own open state (trigger slot). */
  open?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const internalOpen = ref(false)
const isControlled = computed(() => props.open !== undefined)
const modelOpen = computed({
  get: () => (isControlled.value ? (props.open as boolean) : internalOpen.value),
  set: (value: boolean) => {
    if (!isControlled.value) internalOpen.value = value
    emit('update:open', value)
  },
})
</script>

<template>
  <DialogRoot v-model:open="modelOpen">
    <DialogTrigger v-if="$slots.trigger" as-child>
      <slot name="trigger" />
    </DialogTrigger>

    <DialogPortal>
      <Transition name="dialog-fade">
        <DialogOverlay v-if="modelOpen" class="ui-dialog__overlay" />
      </Transition>
      <Transition name="dialog-fade">
        <DialogContent v-if="modelOpen" class="ui-dialog__content">
          <DialogTitle v-if="title || $slots.title" class="ui-dialog__title">
            <slot name="title">{{ title }}</slot>
          </DialogTitle>

          <DialogDescription
            v-if="description || $slots.description"
            class="ui-dialog__description"
          >
            <slot name="description">{{ description }}</slot>
          </DialogDescription>

          <slot />

          <DialogClose as-child>
            <slot name="close">
              <button class="ui-dialog__close" type="button" aria-label="Закрыть">✕</button>
            </slot>
          </DialogClose>
        </DialogContent>
      </Transition>
    </DialogPortal>
  </DialogRoot>
</template>
