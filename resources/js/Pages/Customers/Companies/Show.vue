<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'

type Company = {
  id: number; name: string; legal_name: string | null; website_url: string | null; website_domain_normalized: string | null;
  country_code: string | null; registration_number: string | null; industry: string | null;
  owner: { id: number; name: string } | null; status: string; identity_status: string; scale_band: string | null;
  identity_reviewer: { id: number; name: string } | null; identity_reviewed_at: string | null;
  archived_at: string | null; created_at: string | null; updated_at: string | null
}
type Contact = { id: number; name: string; email: string | null; phone: string | null; job_title: string | null; department: string | null; is_primary: boolean; status: string }
type ReceivedTotal = { currency:string; amount_micros:number; receipt_count:number }
type ProjectRelationship = { project: { key: string; name: string }; owner: { id: number; name: string } | null; location: { id: number; name: string } | null; relationship_status: string; market_route: string | null; payment_terms: string | null; credit_limit_micros: number | null; credit_currency: string | null; inquiry_count: number; last_source_channel: string | null; last_inquiry_at: string | null; received_totals:ReceivedTotal[] }
type Evidence = { id: number; evidence_type: string; source_type: string; evidence_key: string; review_state: string; confidence: string | null; source_url: string | null; project: { key: string; name: string } | null; observed_at: string | null; verified_at: string | null }

const props = defineProps<{ company: Company; canOperate: boolean; contacts: Contact[]; projectRelationships: ProjectRelationship[]; identityEvidence: Evidence[]; audit: { action: string; actor_id: number | null; created_at: string }[] }>()
const identityStatus = ref(props.company.identity_status)
const scaleBand = ref(props.company.scale_band ?? '')
const archive = () => { if (window.confirm(`确认归档公司「${props.company.name}」？`)) router.post(`/customers/companies/${props.company.id}/archive`) }
const restore = () => { if (window.confirm(`确认取消归档公司「${props.company.name}」？`)) router.post(`/customers/companies/${props.company.id}/restore`) }
const reviewIdentity = () => router.post(`/customers/companies/${props.company.id}/identity-review`, { identity_status: identityStatus.value, scale_band: scaleBand.value || null }, { preserveScroll: true })
const money = (micros:number, currency:string) => new Intl.NumberFormat('zh-CN', { style:'currency', currency, maximumFractionDigits:2 }).format(micros/1_000_000)
</script>

<template>
  <Head :title="`${company.name} · YBY ERP`" />
  <AuthenticatedLayout :canonical-topbar="true" title="公司详情" :breadcrumbs="['YBY ERP', '客户', '公司', company.name]">
    <section class="mvp-page customer-os-unified-page customer-os-company-show">
      <div class="mvp-page-heading">
        <div><p class="mvp-kicker">Global Company Truth</p><h2>{{ company.name }}</h2><p><span class="mvp-status">{{ company.archived_at ? '已归档' : company.status }}</span> · 身份 {{ company.identity_status }}</p></div>
        <div class="mvp-form-actions page-header-actions">
          <a class="mvp-button" href="/customers/companies">返回列表</a>
          <Link v-if="canOperate && !company.archived_at" class="mvp-button" :href="`/customers/companies/${company.id}/edit`">编辑</Link>
          <button v-if="canOperate && !company.archived_at" class="mvp-button" type="button" @click="archive">归档</button>
          <button v-if="canOperate && company.archived_at" class="mvp-button" type="button" @click="restore">取消归档</button>
        </div>
      </div>

      <div class="mvp-detail-grid">
        <article class="mvp-card">
          <h3>公司主档</h3>
          <ul class="mvp-list">
            <li><span>法定名称</span><strong>{{ company.legal_name ?? '—' }}</strong></li>
            <li><span>网址</span><strong>{{ company.website_url ?? '—' }}</strong></li>
            <li><span>国家</span><strong>{{ company.country_code ?? '—' }}</strong></li>
            <li><span>注册号</span><strong>{{ company.registration_number ?? '—' }}</strong></li>
            <li><span>行业</span><strong>{{ company.industry ?? '—' }}</strong></li>
            <li><span>全局负责人</span><strong>{{ company.owner?.name ?? '—' }}</strong></li>
            <li><span>身份状态</span><strong>{{ company.identity_status }}</strong></li>
            <li><span>规模</span><strong>{{ company.scale_band ?? '—' }}</strong></li>
            <li><span>最近身份审核</span><strong>{{ company.identity_reviewed_at ?? '—' }} · {{ company.identity_reviewer?.name ?? '—' }}</strong></li>
          </ul>
        </article>

        <article class="mvp-card">
          <h3>身份验证</h3>
          <p>身份验证只改变 Canonical Company，不改变来源询盘原始证据。</p>
          <div v-if="canOperate" class="mvp-form-grid">
            <label>状态<select v-model="identityStatus"><option value="unverified">未验证</option><option value="reviewing">审核中</option><option value="verified">已验证</option><option value="rejected">驳回</option></select></label>
            <label>规模<select v-model="scaleBand"><option value="">未设置</option><option value="micro">微型</option><option value="small">小型</option><option value="medium">中型</option><option value="large">大型</option><option value="enterprise">企业级</option></select></label>
            <button class="mvp-button" type="button" @click="reviewIdentity">保存验证结果</button>
          </div>
          <ul v-if="identityEvidence.length" class="mvp-list">
            <li v-for="evidence in identityEvidence" :key="evidence.id"><span>{{ evidence.evidence_type }} · {{ evidence.source_type }}</span><strong>{{ evidence.review_state }} · {{ evidence.evidence_key }}</strong></li>
          </ul>
          <p v-else>暂无身份验证证据。</p>
        </article>
      </div>

      <div class="mvp-detail-grid">
        <article class="mvp-card">
          <h3>联系人</h3>
          <ul v-if="contacts.length" class="mvp-list">
            <li v-for="contact in contacts" :key="contact.id"><span>{{ contact.is_primary ? '主要联系人' : '联系人' }} · {{ contact.job_title ?? '—' }}</span><strong>{{ contact.name }} · {{ contact.email ?? contact.phone ?? '—' }}</strong></li>
          </ul>
          <p v-else>暂无联系人。Company 与 Contact 保持独立，不会把公司名塞进联系人姓名。</p>
        </article>

        <article class="mvp-card">
          <h3>项目关系</h3>
          <ul v-if="projectRelationships.length" class="mvp-list">
            <li v-for="row in projectRelationships" :key="row.project.key"><span>{{ row.project.name }} · {{ row.relationship_status }}</span><strong>{{ row.owner?.name ?? '待分配' }} · {{ row.inquiry_count }} 条询盘 · {{ row.location?.name ?? row.market_route ?? '未设置市场' }}</strong><div v-if="row.received_totals.length" class="company-project-received"><span>累计实收</span><b v-for="total in row.received_totals" :key="total.currency">{{ money(total.amount_micros,total.currency) }} <small>{{ total.receipt_count }} 笔</small></b></div></li>
          </ul>
          <p v-else>尚未关联任何项目。</p>
        </article>
      </div>

      <article class="mvp-card">
        <h3>操作审计</h3>
        <ul class="mvp-list"><li v-for="event in audit" :key="event.created_at + event.action"><span>{{ event.action }}</span><small>{{ event.created_at }} · actor {{ event.actor_id ?? 'system' }}</small></li></ul>
      </article>
    </section>
  </AuthenticatedLayout>
</template>