<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ToastClose, ToastRoot, ToastTitle, ToastViewport, useToastManager } from 'reka-ui'

const { t } = useI18n()
const { toasts } = useToastManager()
</script>

<template>
  <ToastViewport class="ui-toast__viewport">
    <li v-for="toast in toasts" :key="toast.id">
      <Transition name="toast-slide">
        <ToastRoot class="ui-toast__root" :toast="toast" :data-status="toast.status">
          <div>
            <ToastTitle class="ui-toast__title">
              {{ toast.title }}
            </ToastTitle>
            <p v-if="toast.description" class="ui-toast__description">
              {{ toast.description }}
            </p>
          </div>

          <ToastClose class="ui-toast__close" :aria-label="t('common.closeNotification')">
            ✕
          </ToastClose>
        </ToastRoot>
      </Transition>
    </li>
  </ToastViewport>
</template>
