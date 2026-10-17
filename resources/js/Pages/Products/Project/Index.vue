<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import CustomerOsKpiCard from '../../../Components/CustomerOS/CustomerOsKpiCard.vue'

type Row = {
  id:number; product_id:number; sku:string|null; global_name:string|null; project_name:string|null;
  category:string|null; status:string; sales_status:string; marketing_status:string; primary_site:string|null
}

const props = defineProps<{
  project:{ key:string; name:string; emoji:string; icon_url?:string|null }
  metrics:{ total:number; active:number; sales_active:number; marketing_active:number }
  products:Row[]
  canOperate:boolean
}>()

const statusLabel = (value:string) => value === 'active' ? '启用' : value === 'inactive' ? '停用' : value
const statusClass = (value:string) => value === 'active' ? 'is-verified' : value === 'inactive' ? 'is-pending' : 'is-reviewing'
</script>

<template>
  <Head :title="`${project.name} · 产品 · YBY ERP`" />
  <AuthenticatedLayout title="产品" :breadcrumbs="['YBY ERP', project.name, '产品']" canonical-topbar>
    <section class="customer-os-page customer-os-unified-page product-os-project-index">
      <header class="customer-os-header">
        <div class="customer-os-heading-copy">
          <span class="customer-os-project-icon" aria-hidden="true">
            <img v-if="project.icon_url" class="customer-os-project-brand-image" :src="project.icon_url" alt="" />
            <span v-else>{{ project.emoji }}</span>
          </span>
          <div>
            <h2>{{ project.name }} · 产品</h2>
            <p>Global Product Master × Project Product Relation · 技术真相全局唯一，商业表达按项目管理</p>
          </div>
        </div>
        <div class="customer-os-header-actions">
          <Link class="customer-os-button" :href="`/projects/${project.key}/sales`">查看销售</Link>
          <Link class="customer-os-button customer-os-button-primary" href="/products/list">全局产品库</Link>
        </div>
      </header>

      <div class="customer-os-kpi-row product-os-kpi-row">
        <CustomerOsKpiCard label="项目产品" :value="metrics.total" helper="已关联到当前项目的产品" icon="building" tone="green" />
        <CustomerOsKpiCard label="项目启用" :value="metrics.active" helper="当前项目可正常使用" icon="shield" tone="green" />
        <CustomerOsKpiCard label="销售启用" :value="metrics.sales_active" helper="可进入销售流程的产品" icon="users" tone="blue" />
        <CustomerOsKpiCard label="营销启用" :value="metrics.marketing_active" helper="可用于投放与内容运营" icon="target" tone="amber" />
      </div>

      <article class="customer-os-table-card">
        <div class="customer-os-table-heading">
          <h3>项目产品</h3>
          <span>{{ metrics.total }} 个关联产品</span>
        </div>
        <div class="customer-os-table-wrap">
          <table class="customer-os-table product-os-table">
            <thead>
              <tr>
                <th>产品</th><th>SKU</th><th>分类</th><th>项目状态</th>
                <th>销售状态</th><th>营销状态</th><th>网站 / Landing</th><th>操作</th>
              </tr>
            </thead>
            <tbody v-if="products.length">
              <tr v-for="row in products" :key="row.id">
                <td>
                  <Link :href="`/projects/${project.key}/products/${row.product_id}`" class="customer-os-company-link">
                    {{ row.project_name || row.global_name || '—' }}
                  </Link>
                  <small v-if="row.project_name && row.global_name">{{ row.global_name }}</small>
                </td>
                <td><span class="customer-os-case-number">{{ row.sku || '—' }}</span></td>
                <td>{{ row.category || '—' }}</td>
                <td><span class="customer-os-status-pill" :class="statusClass(row.status)">{{ statusLabel(row.status) }}</span></td>
                <td><span class="customer-os-status-pill" :class="statusClass(row.sales_status)">{{ statusLabel(row.sales_status) }}</span></td>
                <td><span class="customer-os-status-pill" :class="statusClass(row.marketing_status)">{{ statusLabel(row.marketing_status) }}</span></td>
                <td>
                  <a v-if="row.primary_site" :href="row.primary_site" target="_blank" rel="noreferrer" class="customer-os-row-link">打开页面</a>
                  <span v-else>—</span>
                </td>
                <td><Link :href="`/products/${row.product_id}`" class="customer-os-row-link">查看主数据</Link></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="!products.length" class="product-os-empty-state">
          <div class="customer-os-empty-copy">
            <span class="customer-os-empty-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/></svg>
            </span>
            <div>
              <strong>当前项目尚未激活产品关系</strong>
              <p>Global Product Master 保持独立；只有经过明确激活的产品才会进入 {{ project.name }}。</p>
            </div>
          </div>
          <Link class="customer-os-button customer-os-button-primary customer-os-empty-cta" href="/products/list">查看全局产品库</Link>
        </div>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.product-os-kpi-row { grid-template-columns: repeat(4,minmax(0,1fr)); }
.product-os-table { min-width: 1080px; }
.product-os-empty-state {
  min-height: 132px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 18px 18px 18px 26px;
  border-top: 1px solid var(--customer-border);
  background: #fff;
}
@media (max-width: 1280px) {
  .product-os-kpi-row { grid-template-columns: repeat(2,minmax(0,1fr)); }
}
@media (max-width: 800px) {
  .product-os-kpi-row { display: flex; }
  .product-os-empty-state { align-items: stretch; flex-direction: column; }
}
</style>
