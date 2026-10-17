<script setup lang="ts">
import CustomerOsKpiCard from './CustomerOsKpiCard.vue'
const props = defineProps<{ metrics:{ companies:number; customers:number; prospects:number; verified:number; unresolved_inquiries:number } }>()
const cards = [
  { key:'companies', label:'客户公司', helper:'本项目关联的公司总数', icon:'building', tone:'green' },
  { key:'customers', label:'正式客户', helper:'已确认的真实客户', icon:'users', tone:'green' },
  { key:'prospects', label:'潜在客户', helper:'有意向的潜在客户', icon:'target', tone:'blue' },
  { key:'verified', label:'已验证', helper:'已完成身份验证', icon:'shield', tone:'green' },
  { key:'unresolved_inquiries', label:'待确认询盘', helper:'需要确认身份的询盘', icon:'document', tone:'amber' },
] as const
</script>
<template><div class="customer-os-kpi-row"><CustomerOsKpiCard v-for="card in cards" :key="card.key" :label="card.label" :helper="card.helper" :icon="card.icon" :tone="card.tone" :value="props.metrics[card.key]" /></div></template>