<script setup lang="ts">
defineProps<{
  modelValue?: string
  type?: string
  placeholder?: string
  id?: string
  disabled?: boolean
  autocomplete?: string
}>()

defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>

<template>
  <label class="ui-field" :for="id">
    <span v-if="$slots.default" class="ui-field__label">
      <slot />
    </span>
    <input
      :id="id"
      class="ui-field__input"
      :type="type ?? 'text'"
      :placeholder="placeholder"
      :disabled="disabled"
      :autocomplete="autocomplete"
      :value="modelValue"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
  </label>
</template>
