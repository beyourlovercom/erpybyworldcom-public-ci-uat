<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
defineProps<{ project:{ key:string; name:string; emoji:string; icon_url?:string|null } }>()
</script>
<template>
  <header class="customer-os-header">
    <div class="customer-os-heading-copy">
      <span class="customer-os-project-icon" aria-hidden="true">
        <img v-if="project.icon_url" class="customer-os-project-brand-image" :src="project.icon_url" alt="" />
        <svg v-else-if="project.key === 'yby-bottle'" viewBox="0 0 40 40"><path d="M16 5h8v7l4 5v16H12V17l4-5Z"/><path d="M16 9h8"/></svg>
        <svg v-else-if="project.key === 'yby-world'" viewBox="0 0 40 40"><circle cx="20" cy="20" r="15"/><path d="M5 20h30M20 5c5 5 7 10 7 15s-2 10-7 15c-5-5-7-10-7-15s2-10 7-15Z"/></svg>
        <span v-else>{{ project.emoji }}</span>
      </span>
      <div>
        <h2>{{ project.name }} · 客户</h2>
        <p>统一管理项目客户、联系人、负责人及客户验证状态。</p>
      </div>
    </div>
    <div class="customer-os-header-actions">
      <Link class="customer-os-button customer-os-button-secondary" :href="`/projects/${project.key}/inquiries`">查看询盘</Link>
      <Link class="customer-os-button customer-os-button-primary" href="/customers/companies">全局公司库</Link>
    </div>
  </header>
</template>
