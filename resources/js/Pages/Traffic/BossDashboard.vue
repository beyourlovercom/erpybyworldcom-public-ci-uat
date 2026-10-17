<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import TrafficSectionNav from '../../Components/Traffic/TrafficSectionNav.vue'
import BossTimeComparison from '../../Components/Traffic/BossTimeComparison.vue'

type ReceivedTotal = { currency:string; amount_micros:number; amount:string; receipt_count:number }
type TimeBucket = { bucket_start:string; bucket_end:string; period_start:string; period_end:string; is_partial:boolean; clicks:number; inquiries:number; cost_micros:number; spend:string; won:number; received_totals:ReceivedTotal[] }
type TimeTotals = { clicks:number; inquiries:number; cost_micros:number; spend:string; won:number; received_totals:ReceivedTotal[] }
type TimeRange = { date_from:string; date_to:string; effective_from:string|null; effective_to:string|null }
type TimeComparison = {
  version:string
  compare_mode:'none'|'previous'|'yoy'|'custom'
  requested_granularity:'auto'|'day'|'week'|'month'|'year'
  granularity:'day'|'week'|'month'|'year'
  current_range:TimeRange
  compare_range:TimeRange|null
  summary:{ current:TimeTotals; compare:TimeTotals|null }
  series:{ current:TimeBucket[]; compare:TimeBucket[] }
}
type TrendRow = { date:string; impressions:number; clicks:number; spend:string; provider_conversions:number; inquiries:number; qualified_inquiries:number; won:number; received_totals:ReceivedTotal[] }
type FunnelRow = { key:string; label:string; count:number; conversion_rate:number|null }
type CampaignRow = { id:number; external_campaign_id:string; name:string; status:string|null; advertising_channel_type:string|null; impressions:number; clicks:number; spend:string; provider_conversions:number; inquiries:number; opportunities:number; won:number; won_value:string|null; won_currency_code:string|null }
type SourceRow = { source_type:string; inquiries:number; opportunities:number; won:number; won_value:string|null; won_currency_code:string|null }
type LandingRow = { id:number; name:string; path:string|null; canonical_url:string|null; inquiries:number; opportunities:number; won:number; won_value:string|null; won_currency_code:string|null }
type DrilldownRow = { case_id:number; case_number:string; company_name:string|null; case_stage:string|null; first_inbound_at:string|null; source_kind:string; attribution_state:string; opportunity_id:number|null; opportunity_number:string|null; opportunity_status:string|null; won_at:string|null; sales_url:string|null }
type Drilldown = { dimension:string; value:string; label:string; total:number; rows:DrilldownRow[] }
const props = defineProps<{
  project:{ key:string; name:string }
  filters:{ date_from:string; date_to:string }
  summary:{ currency_code:string|null; mixed_currency:boolean; impressions:number; clicks:number; spend:string|null; provider_conversions:number; inquiries:number; total_inquiries:number; qualified_inquiries:number; opportunities:number; quoted:number; won:number; won_value:string|null; won_currency_code:string|null; received_totals:ReceivedTotal[]; received_micros:number|null; received:string|null; received_currency_code:string|null; revenue:null; cost_per_inquiry:string|null; ctr_percent:number|null; cpc:string|null }
  metrics_contract:{ version:string; qualified_semantics:string; won_value_is_revenue:boolean; received_is_revenue:boolean; received_state:string; revenue_state:string }
  funnel:FunnelRow[]
  attribution_quality:{ resolved:number; partial:number; unresolved:number; total:number; captured:number; missing:number }
  trend:TrendRow[]
  weekly_comparison:Array<Record<string, unknown>>
  time_comparison:TimeComparison
  campaigns:CampaignRow[]
  sources:SourceRow[]
  landing_pages:LandingRow[]
  drilldown:Drilldown|null
  data_health:{ traffic_rows:number; provider_inquiry_rows:number; canonical_case_rows:number; data_from:string|null; data_to:string|null; account_last_synced_at:string|null; sync_mode:string; automatic_sync_enabled:boolean; qualification_state:string; attribution_scope:string }
}>()

const projectBaseUrl = '/projects/' + props.project.key
const trafficUrl = computed(() => projectBaseUrl + '/traffic?date_from=' + encodeURIComponent(props.filters.date_from) + '&date_to=' + encodeURIComponent(props.filters.date_to))
const inquiriesUrl = projectBaseUrl + '/inquiries'
const salesUrl = projectBaseUrl + '/sales'
const funnelHref = (key:string) => {
  if (key === 'traffic') return trafficUrl.value
  if (key === 'inquiry' || key === 'qualified') return inquiriesUrl
  if (key === 'opportunity' || key === 'quoted' || key === 'won') return salesUrl
  return projectBaseUrl
}
const openDrilldown = (dimension:string, value:string|number) => router.get(projectBaseUrl, { ...props.filters, dimension, value:String(value) }, { preserveState:true, replace:true })
const clearDrilldown = () => router.get(projectBaseUrl, props.filters, { preserveState:true, replace:true })
const integer = (value:number) => value.toLocaleString('zh-CN')
const currency = (value:string|null) => {
  if (value === null || props.summary.mixed_currency || !props.summary.currency_code) return '—'
  return new Intl.NumberFormat('zh-CN', { style: 'currency', currency: props.summary.currency_code, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value))
}
const stageLabel = (value:string|null) => {
  if (!value) return '—'
  return ({ new:'新询盘', qualified:'有效询盘', nurturing:'培育中', disqualified:'无效询盘', converted:'已转化', closed:'已关闭' } as Record<string,string>)[value] || value
}
const attributionLabel = (value:string) => ({ resolved:'已解析', partial:'部分解析', unresolved:'未解析' } as Record<string,string>)[value] || value
const opportunityStatusLabel = (value:string|null) => {
  if (!value) return '—'
  return ({ open:'进行中', new:'新建', quoted:'已报价', won:'成交', lost:'丢单', cancelled:'已取消' } as Record<string,string>)[value] || value
}
const sourceLabel = (value:string) => ({ google_ads:'Google Ads', organic_search:'自然搜索', direct_or_unknown:'直接访问 / 未识别', utm:'UTM', meta:'Meta', website:'网站', unattributed:'未归因' } as Record<string,string>)[value] || value
const outcomeMoney = (value:string|null, code:string|null) => { if(!code || code==='MIXED' || value===null) return '—'; return new Intl.NumberFormat('zh-CN',{style:'currency',currency:code,minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value)) }
const receivedSummary = computed(() => props.summary.received_totals.length ? props.summary.received_totals.map(row => outcomeMoney(row.amount,row.currency)).join(' · ') : '—')


</script>

<template>
  <Head :title="`${project.name} · 增长总览 · YBY ERP`" />
  <AuthenticatedLayout title="增长总览" :breadcrumbs="['Growth OS', project.name, '增长总览']">
    <section class="traffic-canonical-page traffic-boss-dashboard">
      <div class="traffic-canonical-hero">
        <div><p class="sales-canonical-kicker">增长 OS</p><h2>{{ project.name }} · 增长总览</h2><p>流量 → 询盘 → 有效询盘 → 商机 → 成交 → 实收。成交金额不等于实收；实收只来自已确认回款。</p></div>
      </div>

      <TrafficSectionNav :project-key="project.key" />

      <BossTimeComparison
        :project-key="project.key"
        :filters="props.filters"
        :currency-code="summary.currency_code"
        :mixed-currency="summary.mixed_currency"
        :time-comparison="time_comparison"
        :data-health="data_health"
      />

      <article class="mvp-card growth-funnel">
        <div class="card-heading"><div><p class="mvp-kicker">标准增长漏斗</p><h3>增长漏斗</h3></div><span>实收已接入 Payment Receipt 真值；会计营收确认仍独立</span></div>
        <div class="funnel-rail"><Link v-for="(row,index) in funnel" :key="row.key" :href="funnelHref(row.key)" class="funnel-step funnel-step-link"><span class="funnel-index">{{ index + 1 }}</span><div><small>{{ row.label }}</small><strong>{{ integer(row.count) }}</strong></div><em>{{ index === 0 ? '基准' : (row.conversion_rate === null ? '—' : row.conversion_rate.toFixed(2) + '%') }}</em></Link></div>
      </article>

      <article class="mvp-card attribution-quality">
        <div class="card-heading"><div><p class="mvp-kicker">归因质量</p><h3>归因可信度</h3></div><strong>{{ attribution_quality.total ? ((attribution_quality.resolved / attribution_quality.total) * 100).toFixed(1) : '0.0' }}%</strong></div>
        <div class="quality-grid"><div><span>已解析</span><strong>{{ attribution_quality.resolved }}</strong></div><div><span>部分解析</span><strong>{{ attribution_quality.partial }}</strong></div><div><span>未解析</span><strong>{{ attribution_quality.unresolved }}</strong></div><div><span>缺少证据</span><strong>{{ attribution_quality.missing }}</strong></div></div>
      </article>

      <div class="detail-grid">
        <article class="mvp-card campaign-card">
          <div class="card-heading"><div><p class="mvp-kicker">广告活动</p><h3>Google Ads 广告活动</h3></div><span>{{ campaigns.length }} 个</span></div>
          <div class="table-wrap"><table><thead><tr><th>广告活动</th><th>点击</th><th>消耗</th><th>询盘</th><th>商机</th><th>成交</th><th>成交金额</th></tr></thead><tbody>
            <tr v-for="row in campaigns" :key="row.id"><td><button class="entity-link" @click="openDrilldown('campaign',row.id)"><strong>{{ row.name }}</strong><small>{{ row.external_campaign_id }}</small></button></td><td>{{ integer(row.clicks) }}</td><td>{{ currency(row.spend) }}</td><td>{{ integer(row.inquiries) }}</td><td>{{ integer(row.opportunities) }}</td><td>{{ integer(row.won) }}</td><td>{{ outcomeMoney(row.won_value,row.won_currency_code) }}</td></tr>
            <tr v-if="!campaigns.length"><td colspan="7" class="empty-cell">暂无广告活动数据</td></tr>
          </tbody></table></div>
          <p class="scope-note">点击/消耗来自 provider；Inquiry / Opportunity / Won 只来自 标准询盘记录 Attribution，不做反推。</p>
        </article>

        <aside class="mvp-card source-card">
          <div><p class="mvp-kicker">询盘来源</p><h3>询盘来源</h3></div>
          <div class="source-list"><button v-for="row in sources" :key="row.source_type" class="source-drill" @click="openDrilldown('source',row.source_type)"><span>{{ sourceLabel(row.source_type) }}<small>{{ row.opportunities }} 商机 · {{ row.won }} 成交</small></span><strong>{{ integer(row.inquiries) }}</strong></button><p v-if="!sources.length" class="empty-state">暂无询盘来源数据。</p></div>
          <div class="health-block"><h4>数据健康</h4><dl><div><dt>流量数据行</dt><dd>{{ data_health.traffic_rows }}</dd></div><div><dt>标准询盘 Case</dt><dd>{{ data_health.canonical_case_rows }}</dd></div><div><dt>归因</dt><dd>{{ attribution_quality.resolved }}/{{ attribution_quality.total }} 已解析</dd></div><div><dt>实收</dt><dd>{{ receivedSummary }}</dd></div><div><dt>营收确认</dt><dd>未定义</dd></div></dl></div>
        </aside>
      </div>
      <article class="mvp-card landing-card">
        <div class="card-heading"><div><p class="mvp-kicker">落地页结果</p><h3>落地页 → 询盘 → 成交</h3></div><span>{{ landing_pages.length }} 个已匹配页面</span></div>
        <div class="table-wrap"><table><thead><tr><th>落地页</th><th>询盘</th><th>商机</th><th>成交</th><th>成交金额</th></tr></thead><tbody><tr v-for="row in landing_pages" :key="row.id"><td><button class="entity-link" @click="openDrilldown('landing',row.id)"><strong>{{ row.name }}</strong><small>{{ row.path || row.canonical_url || '—' }}</small></button></td><td>{{ row.inquiries }}</td><td>{{ row.opportunities }}</td><td>{{ row.won }}</td><td>{{ outcomeMoney(row.won_value,row.won_currency_code) }}</td></tr><tr v-if="!landing_pages.length"><td colspan="5" class="empty-cell">当前没有已解析的落地页归因。</td></tr></tbody></table></div>
      </article>
      <article v-if="drilldown" class="mvp-card drilldown-card">
        <div class="card-heading"><div><p class="mvp-kicker">明细下钻</p><h3>{{ drilldown.label }}</h3><small>{{ drilldown.total }} 个 标准询盘记录</small></div><button class="mvp-button" @click="clearDrilldown">关闭</button></div>
        <div class="table-wrap"><table><thead><tr><th>询盘</th><th>公司</th><th>阶段</th><th>归因</th><th>商机</th><th>操作</th></tr></thead><tbody><tr v-for="row in drilldown.rows" :key="row.case_id"><td><strong>{{ row.case_number }}</strong><small>{{ sourceLabel(row.source_kind) }}</small></td><td>{{ row.company_name || '—' }}</td><td>{{ stageLabel(row.case_stage) }}</td><td>{{ attributionLabel(row.attribution_state) }}</td><td>{{ opportunityStatusLabel(row.opportunity_status) }}</td><td><Link v-if="row.sales_url" :href="row.sales_url">销售详情</Link><span v-else>—</span></td></tr></tbody></table></div>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.traffic-canonical-page{display:grid;gap:16px;padding:16px 0 28px}.traffic-canonical-hero{display:flex;align-items:center;justify-content:space-between;gap:28px;padding:6px 6px 10px}.traffic-canonical-hero h2{margin:0 0 8px;color:#101b32;font-size:36px;line-height:1.08;font-weight:800;letter-spacing:-.03em}.traffic-canonical-hero p:last-child{margin:0;color:#69788b;font-size:15px}.traffic-date-filter{display:flex;align-items:end;gap:10px;padding:14px 16px;border:1px solid #dbe4e8;border-radius:14px;background:#fff}.traffic-date-filter label{display:grid;gap:5px;color:#64748b;font-size:11px}.traffic-date-filter input{min-height:38px;padding:0 10px;border:1px solid #ccd7db;border-radius:9px}.traffic-date-filter>span{margin-left:auto;color:#78869a;font-size:11px}.growth-kpi-grid{grid-template-columns:repeat(6,minmax(0,1fr))}.traffic-trend-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.traffic-trend-card{min-width:0;padding:18px 20px;border:1px solid #dbe4e8;border-radius:17px;background:#fff;box-shadow:0 8px 25px rgb(28 51 63 / .05)}.traffic-trend-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:10px}.traffic-trend-heading span{display:block;color:#0b8f53;font-size:10px;font-weight:800;letter-spacing:.12em}.traffic-trend-heading h3{margin:4px 0 0;color:#17223d;font-size:17px}.traffic-chart-shell{overflow-x:auto}.traffic-chart-shell svg{width:100%;min-width:430px;height:150px}.traffic-spend-line,.traffic-inquiry-line{fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}.traffic-spend-line{stroke:#2b7de9}.traffic-inquiry-line{stroke:#18a66b}.traffic-chart-dates{display:flex;justify-content:space-between;color:#94a3b8;font-size:10px}.traffic-spend-point{fill:#fff;stroke:#2b7de9;stroke-width:2;cursor:pointer}.traffic-inquiry-point{fill:#fff;stroke:#18a66b;stroke-width:2;cursor:pointer}.traffic-selected-day{display:flex;gap:14px;align-items:center;flex-wrap:wrap;padding:10px 14px;border:1px solid #dbe4e8;border-radius:12px;background:#fff;color:#59697c;font-size:12px}.traffic-selected-day strong{color:#17223d}@media(max-width:1280px){.growth-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.traffic-trend-grid{grid-template-columns:1fr}.traffic-date-filter{flex-wrap:wrap}.traffic-date-filter>span{width:100%;margin-left:0}}@media(max-width:800px){.traffic-canonical-hero{align-items:flex-start;flex-direction:column}.traffic-canonical-hero h2{font-size:30px}.traffic-date-filter{align-items:stretch;flex-direction:column}}

.dashboard-filters{display:flex;align-items:end;gap:12px;margin-bottom:14px}.dashboard-filters label{display:grid;gap:5px;color:#64748b;font-size:12px}.dashboard-filters input{min-height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;background:#fff}.filter-actions{display:flex;gap:8px}.freshness-strip{display:flex;justify-content:space-between;gap:16px;padding:10px 14px;border:1px solid #dbe4ec;border-radius:10px;background:#f8fafc;color:#475569;font-size:12px}.freshness-strip.stale{border-color:#fed7aa;background:#fff7ed;color:#9a3412}.kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-top:14px}.kpi-card{display:grid;gap:7px;min-width:0;padding:16px;border:1px solid #dbe4ec;border-radius:12px;background:#fff}.kpi-card.primary{border-top:3px solid #2563eb}.kpi-card.success{border-top:3px solid #059669}.kpi-card.unavailable{background:#f8fafc}.kpi-card span{color:#64748b;font-size:12px;font-weight:650}.kpi-card strong{font-size:clamp(21px,1.5vw,28px);line-height:1;color:#0f172a;white-space:nowrap;font-variant-numeric:tabular-nums}.kpi-card small{color:#94a3b8;font-size:11px}.trend-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:14px}.trend-card{min-width:0}.card-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.card-heading h3{margin:2px 0 10px}.card-heading>strong{font-size:18px;font-variant-numeric:tabular-nums}.chart-shell{overflow-x:auto}svg{width:100%;min-width:430px;height:150px}.spend-line,.inquiry-line{fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}.spend-line{stroke:#2563eb}.inquiry-line{stroke:#059669}.chart-dates{display:flex;justify-content:space-between;color:#94a3b8;font-size:11px}.detail-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(280px,.72fr);gap:14px;margin-top:14px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:10px 8px;border-bottom:1px solid #e2e8f0;text-align:right;white-space:nowrap}th:first-child,td:first-child{text-align:left}th{color:#64748b;font-weight:650}td{color:#334155;font-variant-numeric:tabular-nums}td strong,td small{display:block}td small{margin-top:3px;color:#94a3b8;font-size:11px}.scope-note{margin:12px 0 0;color:#64748b;font-size:11px}.source-list{display:grid;gap:2px}.source-list>div,.health-block dl>div{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #e2e8f0}.source-list span,.health-block dt{color:#64748b;font-size:13px}.source-list strong{font-size:22px}.health-block{margin-top:20px}.health-block h4{margin:0 0 4px}.health-block dl{margin:0}.health-block dd{margin:0;color:#334155;font-weight:650}.empty-state,.empty-cell{color:#94a3b8;text-align:center}.empty-cell{text-align:center!important}@media(max-width:1440px){.kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:900px){.dashboard-filters,.freshness-strip{align-items:stretch;flex-direction:column}.kpi-grid,.trend-grid,.detail-grid{grid-template-columns:1fr}}
.growth-funnel{margin-top:14px}.growth-funnel .card-heading span{color:#64748b;font-size:12px}.funnel-rail{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-top:10px}.funnel-step{display:grid;grid-template-columns:auto 1fr;gap:8px;align-items:center;padding:12px;border:1px solid #dbe4ec;border-radius:10px;background:#f8fafc}.funnel-index{display:grid;place-items:center;width:26px;height:26px;border-radius:999px;background:#e2e8f0;font-size:12px;font-weight:700}.funnel-step div{display:grid;gap:3px}.funnel-step small{color:#64748b}.funnel-step strong{font-size:20px}.funnel-step em{grid-column:1/-1;color:#64748b;font-size:11px;font-style:normal}@media(max-width:1100px){.funnel-rail{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:640px){.funnel-rail{grid-template-columns:1fr}}
.attribution-quality,.landing-card{margin-top:14px}.quality-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:10px}.quality-grid>div{display:grid;gap:5px;padding:12px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0}.quality-grid span{font-size:11px;color:#64748b}.quality-grid strong{font-size:22px}.source-list span small{display:block;margin-top:2px;color:#94a3b8;font-size:10px}@media(max-width:720px){.quality-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
.entity-link,.source-drill{border:0;background:transparent;padding:0;color:inherit;font:inherit;cursor:pointer;text-align:left}.entity-link strong,.entity-link small{display:block}.entity-link:hover strong{color:#006241}.source-drill{display:flex;width:100%;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #e2e8f0}.source-drill span small{display:block;margin-top:2px;color:#94a3b8;font-size:10px}.drilldown-card{margin-top:14px}

/* Weekly comparison */
.weekly-comparison-section{display:grid;gap:12px;padding:18px 20px;border:1px solid #dbe4e8;border-radius:17px;background:#fff;box-shadow:0 8px 25px rgb(28 51 63 / .05)}
.weekly-comparison-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.weekly-comparison-heading h3{margin:3px 0 0;color:#17223d;font-size:18px}.weekly-comparison-heading>span{color:#78869a;font-size:11px}
.weekly-comparison-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.weekly-comparison-card{display:grid;gap:8px;min-width:0;padding:14px;border:1px solid #e1e8ec;border-radius:13px;background:#fbfcfd;color:inherit;text-decoration:none;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}.weekly-comparison-card:hover{transform:translateY(-1px);border-color:#b7c9c1;box-shadow:0 8px 20px rgb(28 51 63 / .07)}.weekly-comparison-card:focus-visible{outline:3px solid rgb(0 98 65 / .18);outline-offset:2px}
.weekly-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.weekly-card-head span{font-size:12px;font-weight:800;color:#334155}.weekly-card-head em{font-size:10px;font-style:normal;color:#64748b}.weekly-comparison-card>strong{font-size:26px;line-height:1;color:#17223d}.weekly-comparison-card>small{font-size:10px;color:#94a3b8}.weekly-chart-shell{min-width:0}.weekly-chart-shell svg{width:100%;min-width:0;height:82px}.weekly-line{fill:none;stroke:#087f5b;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}.weekly-axis{display:flex;justify-content:space-between;color:#94a3b8;font-size:9px}.weekly-comparison-card>p{margin:8px 0 0;color:#94a3b8;font-size:11px}@media(max-width:1100px){.weekly-comparison-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.weekly-comparison-grid{grid-template-columns:1fr}.weekly-comparison-heading{flex-direction:column}}

/* Boss KPI navigation */
.boss-kpi-link,.funnel-step-link{text-decoration:none;color:inherit;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease}
.boss-kpi-link:hover,.funnel-step-link:hover{transform:translateY(-1px);border-color:#b7c9c1;box-shadow:0 10px 28px rgb(28 51 63 / .09)}
.boss-kpi-link:focus-visible,.funnel-step-link:focus-visible{outline:3px solid rgb(0 98 65 / .18);outline-offset:2px}
.boss-kpi-link strong,.funnel-step-link strong{color:inherit}

/* Traffic Responsive Containment V1 */
.traffic-canonical-page{width:100%;min-width:0;max-width:100%;overflow-x:hidden;box-sizing:border-box}
.traffic-canonical-page>*{min-width:0;max-width:100%;box-sizing:border-box}
.traffic-canonical-hero,.traffic-date-filter,.dashboard-filters,.freshness-strip,.traffic-trend-grid,.trend-grid,.detail-grid,.card-heading{min-width:0;max-width:100%}
.traffic-canonical-hero>*,.traffic-trend-grid>*,.trend-grid>*,.detail-grid>*,.card-heading>*{min-width:0}
.growth-kpi-grid,.kpi-grid{grid-template-columns:repeat(auto-fit,minmax(160px,1fr));width:100%;min-width:0}
.funnel-rail{grid-template-columns:repeat(auto-fit,minmax(150px,1fr));min-width:0}
.kpi-card,.funnel-step,.mvp-card{min-width:0;max-width:100%;box-sizing:border-box}
.detail-grid{grid-template-columns:minmax(0,2fr) minmax(0,.72fr)}
.traffic-chart-shell,.chart-shell,.table-wrap{display:block;width:100%;min-width:0;max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch}
.table-wrap table{width:100%;min-width:max-content}
.entity-link{min-width:0;max-width:100%}.entity-link strong,.entity-link small{max-width:420px;overflow:hidden;text-overflow:ellipsis}
@media(max-width:900px){.detail-grid,.traffic-trend-grid,.trend-grid{grid-template-columns:1fr}}
@media(max-width:640px){.growth-kpi-grid,.kpi-grid,.funnel-rail{grid-template-columns:1fr}}

</style>
