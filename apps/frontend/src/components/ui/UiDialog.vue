<script setup lang="ts">
import { computed, getCurrentInstance } from 'vue'
import { useI18n } from 'vue-i18n'
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

const { t } = useI18n()

/**
 * Vue castira otsutstvuyushchiy Boolean-prop v false, poetomu `props.open !== undefined`
 * vsegda true i nekontroliruemyy dialog nikogda ne otkryvaetsya. Smotrim nalicie
 * proda v originalnom vnode — tam ego net, esli <UiDialog> ispolzuetsya bez :open.
 */
const instance = getCurrentInstance()
const isControlled = computed(() => instance?.vnode.props != null && 'open' in instance.vnode.props)
const rootProps = computed(() =>
  isControlled.value ? { open: props.open } : { defaultOpen: false }
)

function onOpenChange(value: boolean) {
  emit('update:open', value)
}
</script>

<template>
  <DialogRoot v-bind="rootProps" @update:open="onOpenChange">
    <DialogTrigger v-if="$slots.trigger" as-child>
      <slot name="trigger" />
    </DialogTrigger>

    <DialogPortal>
      <DialogOverlay class="ui-dialog__overlay" />
      <DialogContent class="ui-dialog__content">
        <DialogTitle v-if="title || $slots.title" class="ui-dialog__title">
          <slot name="title">{{ title }}</slot>
        </DialogTitle>

        <DialogDescription v-if="description || $slots.description" class="ui-dialog__description">
          <slot name="description">{{ description }}</slot>
        </DialogDescription>

        <slot />

        <DialogClose as-child>
          <slot name="close">
            <button class="ui-dialog__close" type="button" :aria-label="t('common.close')">
              ✕
            </button>
          </slot>
        </DialogClose>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
