<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps<{ projectKey:string }>()
const page = usePage()
const currentPath = computed(() => String(page.url ?? '').split('?')[0])
const base = computed(() => `/projects/${props.projectKey}`)
const items = computed(() => [
  { key:'overview', label:'总览', path:`${base.value}/traffic` },
  { key:'ga4', label:'GA4', path:`${base.value}/traffic/ga4` },
  { key:'landing', label:'Landing Pages', path:`${base.value}/traffic/landing-pages` },
  { key:'seo', label:'SEO Growth', path:`${base.value}/traffic/seo` },
])
const active = (path:string) => currentPath.value === path
</script>

<template>
  <nav class="traffic-section-nav" aria-label="Traffic OS 页面导航">
    <Link
      v-for="item in items"
      :key="item.key"
      :href="item.path"
      class="traffic-section-link"
      :class="{ 'is-active': active(item.path) }"
      :aria-current="active(item.path) ? 'page' : undefined"
    >{{ item.label }}</Link>
  </nav>
</template>

<style scoped>
.traffic-section-nav{display:flex;width:100%;min-width:0;max-width:100%;gap:4px;padding:5px;border:1px solid #dbe4e8;border-radius:12px;background:#fff;overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch;box-sizing:border-box}
.traffic-section-link{flex:0 0 auto;padding:8px 12px;border-radius:8px;color:#64748b;text-decoration:none;font-size:12px;font-weight:700;white-space:nowrap;transition:background .15s ease,color .15s ease}
.traffic-section-link:hover{background:#f1f5f9;color:#334155}
.traffic-section-link.is-active{background:#e8f3ef;color:#006241}
@media(max-width:700px){.traffic-section-nav{padding:4px}.traffic-section-link{padding:8px 10px;font-size:11px}}
</style>
