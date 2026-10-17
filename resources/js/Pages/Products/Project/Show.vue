<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type ExternalReference = { provider:string; connection_key:string; reference:string; reference_type:string }

const props = defineProps<{
  project:{ key:string; name:string; emoji:string; icon_url?:string|null }
  relation:{ id:number; project_name:string|null; category:string|null; status:string; sales_status:string; marketing_status:string; primary_site:string|null; created_at:string|null; updated_at:string|null }
  product:Record<string, any>
  externalReferences:ExternalReference[]
  links:{ project_products:string; global_product:string; sales:string; traffic:string }
  canOperate:boolean
}>()

const display = (value:unknown) => value === null || value === undefined || value === '' ? '—' : String(value)
const statusLabel = (value:string) => value === 'active' ? '启用' : value === 'inactive' ? '停用' : value
const statusClass = (value:string) => value === 'active' ? 'is-verified' : value === 'inactive' ? 'is-pending' : 'is-reviewing'
const dimension = () => [props.product.length_cm, props.product.width_cm, props.product.height_cm].every(v => v !== null && v !== undefined)
  ? `${props.product.length_cm} × ${props.product.width_cm} × ${props.product.height_cm} cm`
  : '—'
</script>

<template>
  <Head :title="`${relation.project_name || product.name} · Product 360 · YBY ERP`" />
  <AuthenticatedLayout title="Product 360" :breadcrumbs="['YBY ERP', project.name, '产品', relation.project_name || product.name]" canonical-topbar>
    <section class="customer-os-page customer-os-unified-page project-product360-page">
      <header class="customer-os-header">
        <div class="customer-os-heading-copy">
          <span class="customer-os-project-icon" aria-hidden="true">
            <img v-if="project.icon_url" class="customer-os-project-brand-image" :src="project.icon_url" alt="" />
            <span v-else>{{ project.emoji }}</span>
          </span>
          <div>
            <p class="mvp-kicker">PROJECT PRODUCT 360</p>
            <h2>{{ relation.project_name || product.name }}</h2>
            <p>{{ project.name }} · {{ relation.category || '未分类' }} · Global Product #{{ product.id }}</p>
          </div>
        </div>
        <div class="customer-os-header-actions">
          <Link class="customer-os-button" :href="links.project_products">返回项目产品</Link>
          <Link class="customer-os-button customer-os-button-primary" :href="links.global_product">查看全局 Product 360</Link>
        </div>
      </header>

      <div class="project-product360-status-grid">
        <article class="project-product360-status-card">
          <span>项目状态</span>
          <strong class="customer-os-status-pill" :class="statusClass(relation.status)">{{ statusLabel(relation.status) }}</strong>
          <small>是否在当前项目中启用</small>
        </article>
        <article class="project-product360-status-card">
          <span>销售状态</span>
          <strong class="customer-os-status-pill" :class="statusClass(relation.sales_status)">{{ statusLabel(relation.sales_status) }}</strong>
          <small>是否允许进入销售流程</small>
        </article>
        <article class="project-product360-status-card">
          <span>营销状态</span>
          <strong class="customer-os-status-pill" :class="statusClass(relation.marketing_status)">{{ statusLabel(relation.marketing_status) }}</strong>
          <small>是否用于投放与内容运营</small>
        </article>
      </div>

      <div class="project-product360-grid">
        <article class="mvp-card project-product360-card">
          <div class="project-product360-card-head">
            <div><p class="mvp-kicker">PROJECT CONTEXT</p><h3>项目商业上下文</h3></div>
            <a v-if="relation.primary_site" class="customer-os-button customer-os-button-primary" :href="relation.primary_site" target="_blank" rel="noreferrer">打开产品页面</a>
          </div>
          <ul class="mvp-list">
            <li><span>项目产品名</span><strong>{{ display(relation.project_name) }}</strong></li>
            <li><span>项目分类</span><strong>{{ display(relation.category) }}</strong></li>
            <li><span>Primary Site / Landing</span><strong>{{ display(relation.primary_site) }}</strong></li>
            <li><span>关系更新时间</span><strong>{{ display(relation.updated_at) }}</strong></li>
          </ul>
        </article>

        <article class="mvp-card project-product360-card">
          <div class="project-product360-card-head">
            <div><p class="mvp-kicker">GLOBAL TRUTH</p><h3>Global Product Master 摘要</h3></div>
            <Link class="customer-os-row-link" :href="links.global_product">进入完整主数据 →</Link>
          </div>
          <ul class="mvp-list">
            <li><span>Global Name</span><strong>{{ product.name }}</strong></li>
            <li><span>SKU</span><strong>{{ display(product.sku) }}</strong></li>
            <li><span>Source Type</span><strong>{{ display(product.source_type) }}</strong></li>
            <li><span>Product Type</span><strong>{{ display(product.product_type) }}</strong></li>
            <li><span>Global Status</span><strong>{{ product.is_active ? '启用' : '停用' }}</strong></li>
          </ul>
        </article>
      </div>

      <div class="project-product360-grid">
        <article class="mvp-card project-product360-card">
          <div class="project-product360-card-head"><div><p class="mvp-kicker">TECHNICAL TRUTH</p><h3>技术事实摘要</h3></div></div>
          <ul class="mvp-list">
            <li><span>GTIN</span><strong>{{ display(product.gtin) }}</strong></li>
            <li><span>单位</span><strong>{{ display(product.unit) }}</strong></li>
            <li><span>净重</span><strong>{{ product.weight_kg ? product.weight_kg + ' kg' : '—' }}</strong></li>
            <li><span>运输重量</span><strong>{{ product.shipping_weight_kg ? product.shipping_weight_kg + ' kg' : '—' }}</strong></li>
            <li><span>尺寸</span><strong>{{ dimension() }}</strong></li>
            <li><span>原产国 / HS Code</span><strong>{{ display(product.country_of_origin) }} / {{ display(product.hs_code) }}</strong></li>
          </ul>
        </article>

        <article class="mvp-card project-product360-card">
          <div class="project-product360-card-head"><div><p class="mvp-kicker">SOURCE</p><h3>外部来源与身份</h3></div></div>
          <ul v-if="externalReferences.length" class="mvp-list">
            <li v-for="ref in externalReferences" :key="`${ref.provider}-${ref.connection_key}-${ref.reference}`">
              <span>{{ ref.provider }} · {{ ref.connection_key }}</span>
              <strong>{{ ref.reference_type }} #{{ ref.reference }}</strong>
            </li>
          </ul>
          <p v-else class="mvp-empty">暂无外部引用。</p>
        </article>
      </div>

      <article class="mvp-card project-product360-card">
        <div class="project-product360-card-head">
          <div><p class="mvp-kicker">PROJECT USAGE</p><h3>项目使用入口</h3></div>
        </div>
        <div class="project-product360-actions">
          <Link class="customer-os-button" :href="links.sales">查看 {{ project.name }} 销售</Link>
          <Link class="customer-os-button" :href="links.traffic">查看 {{ project.name }} 流量</Link>
          <Link class="customer-os-button customer-os-button-primary" :href="links.global_product">库存 / 物流 / 成本 / 审计 → Global Product 360</Link>
        </div>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.project-product360-page { max-width: 1480px; }
.project-product360-status-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-bottom:14px; }
.project-product360-status-card { min-height:112px; display:grid; align-content:center; gap:8px; padding:18px 20px; border:1px solid var(--customer-border); border-radius:var(--customer-radius); background:#fff; box-shadow:0 4px 18px rgb(32 49 59 / .035); }
.project-product360-status-card > span { color:#394a60; font-size:13px; font-weight:600; }
.project-product360-status-card strong { justify-self:start; }
.project-product360-status-card small { color:#78869a; }
.project-product360-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-bottom:14px; }
.project-product360-card { margin:0; }
.project-product360-card-head { display:flex; align-items:center; justify-content:space-between; gap:18px; margin-bottom:12px; }
.project-product360-card-head h3 { margin:3px 0 0; }
.project-product360-actions { display:flex; flex-wrap:wrap; gap:10px; }
@media (max-width:900px) {
  .project-product360-status-grid,.project-product360-grid { grid-template-columns:1fr; }
  .project-product360-card-head { align-items:flex-start; flex-direction:column; }
}
</style>
