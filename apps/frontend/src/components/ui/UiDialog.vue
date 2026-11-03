<script setup lang="ts">
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

defineProps<{
  title?: string
  description?: string
}>()
</script>

<template>
  <DialogRoot>
    <DialogTrigger as-child>
      <slot name="trigger" />
    </DialogTrigger>

    <DialogPortal>
      <Transition name="dialog-fade">
        <DialogOverlay class="ui-dialog__overlay" />
      </Transition>
      <Transition name="dialog-fade">
        <DialogContent class="ui-dialog__content">
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
              <button class="ui-dialog__close" aria-label="Закрыть">✕</button>
            </slot>
          </DialogClose>
        </DialogContent>
      </Transition>
    </DialogPortal>
  </DialogRoot>
</template>
