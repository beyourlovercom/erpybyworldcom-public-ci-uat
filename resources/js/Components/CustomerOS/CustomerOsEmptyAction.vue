<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
defineProps<{ projectKey:string; pendingCount:number }>()
</script>
<template>
  <section class="customer-os-empty-action">
    <div class="customer-os-empty-copy">
      <span class="customer-os-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
      <div><strong>暂无已确认客户，当前有 {{ pendingCount }} 条询盘等待身份确认。</strong><p>请及时审核询盘并分配负责人，完成客户身份验证。</p></div>
    </div>
    <Link class="customer-os-button customer-os-button-primary customer-os-empty-cta" :href="`/projects/${projectKey}/inquiries`">进入询盘中心 <span aria-hidden="true">→</span></Link>
  </section>
</template>
