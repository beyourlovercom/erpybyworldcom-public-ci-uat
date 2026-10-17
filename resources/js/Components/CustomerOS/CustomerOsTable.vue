<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'

type TabKey = 'all'|'customers'|'pending'|'verified'|'prospects'
type Owner = { id:number; name:string; email?:string }
type CompanyOption = { id:number; name:string }
type Inquiry = { id:number; case_number:string; title:string; contact_name:string|null; company_name_snapshot:string|null; country_snapshot:string|null; source_channel:string; source_site_key:string|null; handling_state:string; identity_resolution_state:string; last_inbound_at:string|null; company:{id:number;name:string}|null; owner:{id:number;name:string}|null }
type ReceivedTotal = { currency:string; amount_micros:number; receipt_count:number }
type Relationship = { id:number; company:{ id:number; name:string; country_code:string|null; identity_status:string; scale_band:string|null; primary_contact:string|null }; owner:{id:number;name:string}|null; location:{id:number;name:string}|null; relationship_status:string; market_route:string|null; inquiry_count:number; last_source_channel:string|null; last_inquiry_at:string|null; last_follow_up_at:string|null; received_totals:ReceivedTotal[] }

const props = defineProps<{ projectKey:string; activeTab:TabKey; pendingInquiries:Inquiry[]; relationships:Relationship[]; owners:Owner[]; companies:CompanyOption[]; canOperate:boolean }>()
const ownerDraft = reactive<Record<number,string>>({})
const companyDraft = reactive<Record<number,string>>({})
props.pendingInquiries.forEach((row) => { ownerDraft[row.id] = row.owner ? String(row.owner.id) : ''; companyDraft[row.id] = '' })
const openAction = ref<{ id:number; type:'owner'|'company' }|null>(null)
const relationRows = computed(() => props.relationships.filter((row) => {
  if (props.activeTab === 'customers') return row.relationship_status === 'customer'
  if (props.activeTab === 'prospects') return row.relationship_status === 'prospect'
  if (props.activeTab === 'verified') return row.company.identity_status === 'verified'
  return true
}))
const sourceLabel = (value:string|null) => value ? ({website:'官网表单',whatsapp:'WhatsApp',xiaoman:'阿里小满',manual:'人工录入',email:'邮件'} as Record<string,string>)[value] || value : '—'
const identityLabel = (value:string) => ({verified:'已验证',reviewing:'审核中',unresolved:'待确认',pending:'待确认',rejected:'未通过'} as Record<string,string>)[value] || value
const identityTone = (value:string) => value === 'verified' ? 'verified' : value === 'reviewing' ? 'reviewing' : 'pending'
const formatTime = (value:string|null) => value ? new Date(value).toLocaleString('zh-CN', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', hour12:false }).replaceAll('/','-') : '—'
const money = (micros:number, currency:string) => new Intl.NumberFormat('zh-CN', { style:'currency', currency, maximumFractionDigits:2 }).format(micros/1_000_000)
const flag = (value:string|null) => {
  if (!value) return ''
  const names:Record<string,string> = { Kenya:'KE', Tanzania:'TZ', Uganda:'UG', China:'CN', 'United States':'US', Germany:'DE', Canada:'CA', Mexico:'MX', Chile:'CL' }
  const code = (names[value] || value).trim().toUpperCase()
  return /^[A-Z]{2}$/.test(code) ? String.fromCodePoint(...Array.from(code).map((c) => 127397 + c.charCodeAt(0))) : ''
}
const toggleAction = (id:number, type:'owner'|'company') => { openAction.value = openAction.value?.id === id && openAction.value.type === type ? null : { id, type } }
const assign = (row:Inquiry) => router.post(`/projects/${props.projectKey}/inquiries/${row.id}/assignment`, { owner_id: ownerDraft[row.id] === '' ? null : Number(ownerDraft[row.id]) }, { preserveScroll:true, onSuccess:() => { openAction.value = null } })
const linkCompany = (row:Inquiry) => { if (!companyDraft[row.id]) return; router.post(`/projects/${props.projectKey}/inquiries/${row.id}/identity/company`, { company_id:Number(companyDraft[row.id]) }, { preserveScroll:true, onSuccess:() => { openAction.value = null } }) }
</script>

<template>
  <section class="customer-os-table-card">
    <header class="customer-os-table-heading">
      <h3>{{ activeTab === 'pending' ? '待确认询盘' : activeTab === 'customers' ? '已确认客户' : activeTab === 'prospects' ? '潜在客户' : activeTab === 'verified' ? '已验证客户' : '客户公司' }}</h3>
      <span>共 {{ activeTab === 'pending' ? pendingInquiries.length : relationRows.length }} 条</span>
    </header>
    <div class="customer-os-table-wrap">
      <table class="customer-os-table">
        <thead v-if="activeTab === 'pending'"><tr><th class="check"><input type="checkbox" aria-label="选择全部询盘"></th><th>询盘编号</th><th>客户 / 公司</th><th>来源</th><th>国家 / 市场</th><th>负责人</th><th>身份验证</th><th>最新时间 <span aria-hidden="true">↓</span></th><th>操作</th></tr></thead>
        <thead v-else><tr><th class="check"><input type="checkbox" aria-label="选择全部客户"></th><th>客户公司</th><th>主要联系人</th><th>国家 / 市场</th><th>负责人</th><th>Location / Route</th><th>身份验证</th><th>累计实收</th><th>询盘 / 最近活动</th><th>操作</th></tr></thead>
        <tbody v-if="activeTab === 'pending'">
          <template v-for="row in pendingInquiries" :key="row.id">
            <tr>
              <td class="check"><input type="checkbox" :aria-label="`选择询盘 ${row.case_number}`"></td>
              <td><strong class="customer-os-case-number">{{ row.case_number }}</strong></td>
              <td><strong>{{ row.company_name_snapshot || row.contact_name || row.title || '待识别' }}</strong></td>
              <td>{{ sourceLabel(row.source_channel) }}</td>
              <td><span class="customer-os-country"><span v-if="flag(row.country_snapshot)" aria-hidden="true">{{ flag(row.country_snapshot) }}</span>{{ row.country_snapshot || '—' }}</span></td>
              <td>{{ row.owner?.name || '待分配' }}</td>
              <td><span class="customer-os-status-pill" :class="`is-${identityTone(row.identity_resolution_state)}`">{{ identityLabel(row.identity_resolution_state) }}</span></td>
              <td>{{ formatTime(row.last_inbound_at) }}</td>
              <td><div class="customer-os-row-actions"><template v-if="canOperate"><button type="button" :disabled="owners.length===0" :title="owners.length===0 ? '暂无可分配负责人' : ''" @click="toggleAction(row.id,'owner')">分配负责人</button><button type="button" :disabled="companies.length===0" :title="companies.length===0 ? '暂无可关联公司' : ''" @click="toggleAction(row.id,'company')">关联公司</button></template><span v-else>—</span><span class="customer-os-more" aria-hidden="true">•••</span></div></td>
            </tr>
            <tr v-if="openAction?.id === row.id" class="customer-os-inline-action-row"><td colspan="9"><div class="customer-os-inline-action" v-if="openAction.type === 'owner'"><strong>分配负责人</strong><select v-model="ownerDraft[row.id]"><option value="">待分配</option><option v-for="owner in owners" :key="owner.id" :value="String(owner.id)">{{ owner.name }}</option></select><button type="button" class="customer-os-mini-primary" @click="assign(row)">保存</button><button type="button" @click="openAction=null">取消</button></div><div class="customer-os-inline-action" v-else><strong>关联公司</strong><select v-model="companyDraft[row.id]"><option value="">选择客户公司</option><option v-for="company in companies" :key="company.id" :value="String(company.id)">{{ company.name }}</option></select><button type="button" class="customer-os-mini-primary" :disabled="!companyDraft[row.id]" @click="linkCompany(row)">确认关联</button><button type="button" @click="openAction=null">取消</button></div></td></tr>
          </template>
          <tr v-if="pendingInquiries.length===0"><td colspan="9" class="customer-os-table-empty">当前没有待确认询盘。</td></tr>
        </tbody>
        <tbody v-else>
          <tr v-for="row in relationRows" :key="row.id"><td class="check"><input type="checkbox" :aria-label="`选择客户 ${row.company.name}`"></td><td><a class="customer-os-company-link" :href="`/customers/companies/${row.company.id}`">{{ row.company.name }}</a></td><td>{{ row.company.primary_contact || '—' }}</td><td><span class="customer-os-country"><span v-if="flag(row.company.country_code)" aria-hidden="true">{{ flag(row.company.country_code) }}</span>{{ row.company.country_code || '—' }}</span></td><td>{{ row.owner?.name || '待分配' }}</td><td>{{ row.location?.name || row.market_route || '—' }}</td><td><span class="customer-os-status-pill" :class="`is-${identityTone(row.company.identity_status)}`">{{ identityLabel(row.company.identity_status) }}</span></td><td><div v-if="row.received_totals.length" class="customer-os-received-totals"><span v-for="total in row.received_totals" :key="total.currency"><strong>{{ money(total.amount_micros,total.currency) }}</strong><small>{{ total.receipt_count }} 笔</small></span></div><span v-else>—</span></td><td><strong>{{ row.inquiry_count }}</strong><small>{{ formatTime(row.last_follow_up_at || row.last_inquiry_at) }}</small></td><td><a class="customer-os-row-link" :href="`/customers/companies/${row.company.id}`">查看客户</a></td></tr>
          <tr v-if="relationRows.length===0"><td colspan="10" class="customer-os-table-empty">当前筛选下暂无客户公司。</td></tr>
        </tbody>
      </table>
    </div>
  </section>
</template>