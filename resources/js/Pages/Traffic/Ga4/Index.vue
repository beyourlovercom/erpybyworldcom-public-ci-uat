<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import TrafficSectionNav from '../../../Components/Traffic/TrafficSectionNav.vue'

type TrendRow={date:string;sessions:number;engaged_sessions:number;key_events:number}
type MixRow={label:string;sessions:number;engaged_sessions:number;share:number}
type LandingRow={path:string;sessions:number;engaged_sessions:number;engagement_rate:number;key_events:number}
type EventRow={event_name:string;event_count:number;sessions:number;business_conversion:boolean}

const props=defineProps<{
  project:{key:string;name:string}
  filters:{date_from:string;date_to:string}
  summary:{sessions:number;engaged_sessions:number;engagement_rate:number;key_events:number;business_conversion_events:number;days_with_data:number}
  trend:TrendRow[]
  traffic_mix:MixRow[]
  landing_pages:LandingRow[]
  events:EventRow[]
  contract:{source:string;scope:string;business_conversion_events:string[];revenue_state:string}
}>()

const filters=reactive({...props.filters})
const format=(v:number)=>v.toLocaleString('zh-CN')
const apply=()=>router.get('/projects/'+props.project.key+'/traffic/ga4',filters,{preserveState:true,replace:true})
const reset=()=>router.get('/projects/'+props.project.key+'/traffic/ga4')
</script>

<template>
  <Head :title="project.name+' · 网站 / GA4 · YBY ERP'" />
  <AuthenticatedLayout title="网站 / GA4" :breadcrumbs="['YBY ERP',project.name,'流量 OS','网站 / GA4']" canonical-topbar>
    <section class="ga4-page">
      <header class="ga4-hero">
        <div>
          <p class="ga4-kicker">WEBSITE ANALYTICS</p>
          <h1>网站 / GA4</h1>
          <p>查看网站会话、参与会话、渠道结构、Landing Page 与事件表现。Revenue 暂不进入本阶段。</p>
        </div>
        <div class="ga4-actions">
          <Link class="ga4-button" :href="'/projects/'+project.key+'/traffic'">← 返回流量总览</Link>
          <form class="ga4-filters" @submit.prevent="apply">
            <label>开始日期<input v-model="filters.date_from" type="date"></label>
            <label>结束日期<input v-model="filters.date_to" type="date"></label>
            <button class="ga4-button primary">应用</button>
            <button type="button" class="ga4-button" @click="reset">最近 30 天</button>
          </form>
        </div>
      </header>

      <TrafficSectionNav :project-key="project.key" />

      <div class="ga4-kpis">
        <article><span>网站会话</span><strong>{{ format(summary.sessions) }}</strong><small>GA4 Sessions</small></article>
        <article><span>参与会话</span><strong>{{ format(summary.engaged_sessions) }}</strong><small>Engaged Sessions</small></article>
        <article><span>参与率</span><strong>{{ summary.engagement_rate.toFixed(1) }}%</strong><small>Engaged / Sessions</small></article>
        <article><span>GA4 Key Events</span><strong>{{ summary.key_events }}</strong><small>Google 官方口径</small></article>
        <article><span>业务转化事件</span><strong>{{ format(summary.business_conversion_events) }}</strong><small>generate_lead + click_whatsapp</small></article>
        <article><span>有数据日期</span><strong>{{ summary.days_with_data }}</strong><small>当前日期范围</small></article>
      </div>

      <div class="ga4-grid two">
        <article class="ga4-card">
          <div class="ga4-card-head"><div><h2>每日趋势</h2><p>Sessions 与 Engaged Sessions 按天展示。</p></div></div>
          <div class="ga4-table-wrap">
            <table>
              <thead><tr><th>日期</th><th>Sessions</th><th>Engaged</th><th>参与率</th><th>Key Events</th></tr></thead>
              <tbody>
                <tr v-for="row in trend" :key="row.date">
                  <td>{{ row.date }}</td>
                  <td>{{ format(row.sessions) }}</td>
                  <td>{{ format(row.engaged_sessions) }}</td>
                  <td>{{ row.sessions ? ((row.engaged_sessions/row.sessions)*100).toFixed(1)+'%' : '0.0%' }}</td>
                  <td>{{ row.key_events }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </article>

        <article class="ga4-card">
          <div class="ga4-card-head"><div><h2>网站流量结构</h2><p>Session Default Channel Group。</p></div></div>
          <div class="ga4-mix">
            <div v-for="row in traffic_mix" :key="row.label">
              <div><strong>{{ row.label }}</strong><small>{{ format(row.sessions) }} sessions</small></div>
              <span>{{ row.share.toFixed(1) }}%</span>
            </div>
          </div>
        </article>
      </div>

      <article class="ga4-card">
        <div class="ga4-card-head"><div><h2>Top Landing Pages</h2><p>按 Sessions 排序，帮助判断哪些入口页真正承接流量。</p></div><Link class="ga4-button" :href="'/projects/'+project.key+'/traffic/landing-pages'">查看 Landing Page Performance →</Link></div>
        <div class="ga4-table-wrap">
          <table>
            <thead><tr><th>#</th><th>Landing Page</th><th>Sessions</th><th>Engaged</th><th>参与率</th><th>Key Events</th></tr></thead>
            <tbody>
              <tr v-for="(row,index) in landing_pages" :key="row.path">
                <td>{{ index+1 }}</td><td class="left"><strong>{{ row.path }}</strong></td>
                <td>{{ format(row.sessions) }}</td><td>{{ format(row.engaged_sessions) }}</td>
                <td>{{ row.engagement_rate.toFixed(1) }}%</td><td>{{ row.key_events }}</td>
              </tr>
              <tr v-if="!landing_pages.length"><td colspan="6" class="empty">当前范围暂无 Landing Page 数据。</td></tr>
            </tbody>
          </table>
        </div>
      </article>

      <article class="ga4-card">
        <div class="ga4-card-head"><div><h2>Event Breakdown</h2><p>业务转化事件会单独标记；普通 GA4 Event 不会被误算成询盘。</p></div></div>
        <div class="ga4-table-wrap">
          <table>
            <thead><tr><th>#</th><th>Event</th><th>Event Count</th><th>Sessions</th><th>业务转化</th></tr></thead>
            <tbody>
              <tr v-for="(row,index) in events" :key="row.event_name">
                <td>{{ index+1 }}</td><td class="left"><strong>{{ row.event_name }}</strong></td>
                <td>{{ format(row.event_count) }}</td><td>{{ format(row.sessions) }}</td>
                <td>{{ row.business_conversion ? '是' : '—' }}</td>
              </tr>
              <tr v-if="!events.length"><td colspan="5" class="empty">当前范围暂无 Event 数据。</td></tr>
            </tbody>
          </table>
        </div>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.ga4-page{display:grid;gap:16px;padding:16px 0 30px;max-width:1540px}.ga4-hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.ga4-kicker{margin:0 0 4px;color:#1677ff;font-size:11px;font-weight:800;letter-spacing:.12em}.ga4-hero h1{margin:0;color:#0d1b3e;font-size:36px}.ga4-hero p:last-child{margin:8px 0 0;color:#728096}.ga4-actions{display:grid;gap:8px;justify-items:end}.ga4-filters{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.ga4-filters label{display:grid;gap:4px;color:#718096;font-size:11px}.ga4-filters input{height:38px;border:1px solid #d5dee9;border-radius:9px;padding:0 10px}.ga4-button{height:38px;padding:0 14px;border:1px solid #d5dee9;border-radius:9px;background:#fff;color:#34465f;font-weight:650;text-decoration:none;display:inline-flex;align-items:center}.ga4-button.primary{border-color:#1677ff;background:#1677ff;color:#fff}
.ga4-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.ga4-kpis article,.ga4-card{padding:18px;border:1px solid #dfe7f0;border-radius:14px;background:#fff;box-shadow:0 6px 22px rgb(38 63 90/.04)}.ga4-kpis span,.ga4-kpis small{display:block}.ga4-kpis span{color:#405169;font-size:12px;font-weight:700}.ga4-kpis strong{display:block;margin:8px 0 5px;color:#0c1837;font-size:28px}.ga4-kpis small{color:#94a1b1;font-size:10px}.ga4-grid.two{display:grid;grid-template-columns:1.6fr 1fr;gap:14px}.ga4-card-head{margin-bottom:12px}.ga4-card-head h2{margin:0;color:#122143;font-size:18px}.ga4-card-head p{margin:4px 0 0;color:#8795a7;font-size:11px}.ga4-table-wrap{overflow:auto}.ga4-table-wrap table{width:100%;border-collapse:collapse;font-size:12px}.ga4-table-wrap th,.ga4-table-wrap td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:right;white-space:nowrap}.ga4-table-wrap th:first-child,.ga4-table-wrap td:first-child,.ga4-table-wrap .left{text-align:left}.ga4-table-wrap th{color:#748297}.ga4-table-wrap td{color:#35465f}.ga4-table-wrap .empty{text-align:center!important;color:#98a5b5;padding:24px}.ga4-mix{display:grid;gap:9px}.ga4-mix>div{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid #edf1f5}.ga4-mix strong,.ga4-mix small{display:block}.ga4-mix small{margin-top:2px;color:#95a2b1;font-size:10px}.ga4-mix span{font-weight:750;color:#1b2b4a}
@media(max-width:1200px){.ga4-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.ga4-grid.two{grid-template-columns:1fr}}@media(max-width:700px){.ga4-hero{flex-direction:column}.ga4-actions{justify-items:start}.ga4-kpis{grid-template-columns:1fr 1fr}}@media(max-width:480px){.ga4-kpis{grid-template-columns:1fr}}

/* Traffic Responsive Containment V1 */
.ga4-page{width:100%;min-width:0;max-width:100%;overflow-x:hidden;box-sizing:border-box}
.ga4-page>*{min-width:0;max-width:100%;box-sizing:border-box}
.ga4-hero,.ga4-actions,.ga4-filters,.ga4-grid.two{min-width:0;max-width:100%}
.ga4-hero>*,.ga4-grid.two>*{min-width:0}
.ga4-kpis{grid-template-columns:repeat(auto-fit,minmax(160px,1fr));width:100%;min-width:0}
.ga4-kpis article,.ga4-card{min-width:0;max-width:100%;box-sizing:border-box}
.ga4-grid.two{grid-template-columns:minmax(0,1.6fr) minmax(0,1fr)}
.ga4-filters label{min-width:0}.ga4-filters input{min-width:0;max-width:100%;box-sizing:border-box}
.ga4-table-wrap{display:block;width:100%;min-width:0;max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch}
.ga4-table-wrap table{width:100%;min-width:max-content}
.ga4-table-wrap .left{max-width:420px;overflow:hidden}.ga4-table-wrap .left strong{display:block;max-width:420px;overflow:hidden;text-overflow:ellipsis}
@media(max-width:700px){.ga4-grid.two{grid-template-columns:1fr}.ga4-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:480px){.ga4-kpis{grid-template-columns:1fr}}

</style>
