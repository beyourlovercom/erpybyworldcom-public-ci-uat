<script setup lang="ts">
import { computed } from 'vue'
import UiButton from '../UI/UiButton.vue'
import NotificationBell from './NotificationBell.vue'
import UserAccountMenu from './UserAccountMenu.vue'
import type { NotificationCenterSummary, SafeUser } from '../../types/navigation'

export type BreadcrumbItem = { label: string; href?: string }

const props = withDefaults(defineProps<{
  title:string
  breadcrumbs:(string | BreadcrumbItem)[]
  mobileExpanded:boolean
  notificationCenter?:NotificationCenterSummary
  canonicalMode?:boolean
  user?:SafeUser | null
}>(), {
  notificationCenter:() => ({ unread_count:0, items:[] }),
  canonicalMode:false,
  user:null,
})
const emit = defineEmits<{ toggleMobile: [] }>()

const parentHref = computed<string | null>(() => {
  const withHref = props.breadcrumbs.filter((c): c is BreadcrumbItem => typeof c === 'object' && !!c.href)
  return withHref.length > 0 ? (withHref[withHref.length - 1].href ?? null) : null
})
</script>

<template>
  <header class="shell-topbar" :class="{ 'is-canonical': canonicalMode }">
    <div class="shell-topbar-leading">
      <a v-if="parentHref" :href="parentHref" class="shell-back-control" aria-label="返回上一层">‹</a>
      <div>
        <div class="shell-breadcrumbs" aria-label="面包屑">
          <template v-for="(crumb, i) in breadcrumbs" :key="i">
            <a v-if="typeof crumb === 'object' && crumb.href" :href="crumb.href">{{ crumb.label }}</a>
            <span v-else>{{ typeof crumb === 'object' ? crumb.label : crumb }}</span>
          </template>
        </div>
        <h1 v-if="!canonicalMode">{{ title }}</h1>
      </div>
    </div>
    <div class="shell-topbar-trailing">
      <label v-if="canonicalMode" class="shell-global-search" title="统一全局搜索后端尚未接入；当前仅呈现 Canonical 搜索入口。">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input type="search" disabled placeholder="搜索客户、公司或询盘编号…" aria-label="搜索客户、公司或询盘编号" />
      </label>
      <NotificationBell :center="notificationCenter" />
      <UserAccountMenu v-if="canonicalMode && user" :user="user" :navigation-hidden="false" variant="topbar" />
      <UiButton class="shell-icon-button shell-mobile-toggle" tone="secondary" size="sm" :aria-expanded="mobileExpanded" :aria-label="mobileExpanded ? '关闭导航' : '打开导航'" @click="emit('toggleMobile')">☰</UiButton>
    </div>
  </header>
</template>
