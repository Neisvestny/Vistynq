<script setup lang="ts">
import { computed, useId } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  modelValue?: string
  type?: string
  placeholder?: string
  id?: string
  name?: string
  disabled?: boolean
  autocomplete?: string
  inputmode?: 'text' | 'email' | 'numeric' | 'tel' | 'url'
  maxlength?: number
  required?: boolean
  error?: string
  errorId?: string
}>()

defineEmits<{
  'update:modelValue': [value: string]
}>()

const generatedId = useId()
const inputId = computed(() => props.id ?? `field-${generatedId}`)
const errorId = computed(() => props.errorId ?? `${inputId.value}-error`)
const describedBy = computed(() => (props.error ? errorId.value : undefined))
</script>

<template>
  <label class="ui-field" :for="inputId">
    <span v-if="$slots.default" class="ui-field__label">
      <slot />
    </span>
    <input
      :id="inputId"
      v-bind="$attrs"
      class="ui-field__input"
      :name="name"
      :type="type ?? 'text'"
      :inputmode="inputmode"
      :placeholder="placeholder"
      :disabled="disabled"
      :autocomplete="autocomplete"
      :maxlength="maxlength"
      :required="required"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="describedBy"
      :value="modelValue"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
    <span v-if="error" :id="errorId" class="ui-field__error" role="alert">{{ error }}</span>
  </label>
</template>
