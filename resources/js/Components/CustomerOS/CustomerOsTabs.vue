<script setup lang="ts">
type TabKey = 'all'|'customers'|'pending'|'verified'|'prospects'
defineProps<{ active:TabKey; pendingCount:number }>()
const emit = defineEmits<{ change:[value:TabKey] }>()
const tabs:{key:TabKey;label:string}[] = [{key:'all',label:'全部'},{key:'customers',label:'已确认客户'},{key:'pending',label:'待确认询盘'},{key:'verified',label:'已验证'},{key:'prospects',label:'潜在客户'}]
</script>
<template><nav class="customer-os-tabs" aria-label="客户状态"><button v-for="tab in tabs" :key="tab.key" type="button" :class="{ 'is-active': active === tab.key }" @click="emit('change',tab.key)">{{ tab.label }}<span v-if="tab.key==='pending'" class="customer-os-tab-badge">{{ pendingCount }}</span></button></nav></template>