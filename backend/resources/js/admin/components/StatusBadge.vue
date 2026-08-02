<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  variant: 'success' | 'warning' | 'danger' | 'neutral'
  text: string
}>()

const classes = computed(() => ({
  success: 'bg-emerald-500/15 text-emerald-400',
  warning: 'bg-amber-500/15 text-amber-400',
  danger: 'bg-red-500/15 text-red-400',
  neutral: 'bg-slate-500/15 text-slate-400',
})[props.variant])
</script>

<template>
  <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="classes">{{ text }}</span>
</template>
