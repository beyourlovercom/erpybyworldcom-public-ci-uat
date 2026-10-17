<script setup lang="ts">
defineProps<{ label:string; value:number; helper:string; icon:'building'|'users'|'target'|'shield'|'document'; tone:'green'|'blue'|'amber' }>()
</script>
<template>
  <article class="customer-os-kpi-card">
    <span class="customer-os-kpi-icon" :class="`is-${tone}`" aria-hidden="true">
      <svg v-if="icon === 'building'" viewBox="0 0 24 24"><path d="M5 21h14M7 21V5l5-2v18M17 21V9l-5-2M9 8h1M9 11h1M9 14h1M14 11h1M14 14h1M14 17h1"/></svg>
      <svg v-else-if="icon === 'users'" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <svg v-else-if="icon === 'target'" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/></svg>
      <svg v-else-if="icon === 'shield'" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
      <svg v-else viewBox="0 0 24 24"><path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5M9 12h6M9 16h6"/></svg>
    </span>
    <div class="customer-os-kpi-copy"><span>{{ label }}</span><strong>{{ value }}</strong><small>{{ helper }}</small></div>
  </article>
</template>