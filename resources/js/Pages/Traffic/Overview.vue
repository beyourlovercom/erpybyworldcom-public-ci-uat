<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'
import TrafficSectionNav from '../../Components/Traffic/TrafficSectionNav.vue'

type Channel={
  key:string;label:string;source:string;connected:boolean;status:string;
  impressions:number|null;clicks:number|null;spend?:string|null;currency?:string|null;
  sessions?:number|null;engaged_sessions?:number|null;key_events?:number|null;
  business_conversion_events?:number|null;
  business_event_breakdown?:{event_name:string;event_count:number;sessions:number}[]
}
type TrendRow={date:string;clicks:number;impressions:number;sessions:number;engaged_sessions:number;inquiries:number}
type TopItem={key:string;name:string;channel:string;impressions:number|null;clicks:number|null;note:string|null}

const props=defineProps<{
  project:{key:string;name:string}
  filters:{date_from:string;date_to:string}
  summary:{
    platform_impressions:number;platform_clicks:number;website_sessions:number|null;
    engaged_sessions:number|null;inquiries:number;active_channels:number;tracked_channels:number
  }
  channels:Channel[]
  trend:TrendRow[]
  traffic_mix:{label:string;value:number;sessions?:number}[]
  top_items?:TopItem[]
  contract:{
    version:string;platform_impressions_are_cross_platform:boolean;
    platform_metric_semantics?:string;summary_scope?:string;summary_sources?:string[];
    deferred_sources?:string[];website_metrics_source:string;website_metrics_state:string;
    gsc_metrics_state?:string;revenue_state:string
  }
}>()

const filters=reactive({...props.filters})
const selectedIndex=ref<number|null>(null)
const selected=computed(()=>selectedIndex.value===null?null:props.trend[selectedIndex.value]??null)
const format=(value:number|null|undefined)=>value===null||value===undefined?'—':value.toLocaleString('zh-CN')
const apply=()=>router.get('/projects/'+props.project.key+'/traffic',filters,{preserveState:true,replace:true})
const reset=()=>router.get('/projects/'+props.project.key+'/traffic')
const channel=(key:string)=>props.channels.find(row=>row.key===key)
const chartWidth=780,chartHeight=190,chartPad=18

const pathFor=(field:'clicks'|'inquiries')=>{
  if(!props.trend.length)return ''
  const max=Math.max(...props.trend.map(row=>Number(row[field])),1)
  return props.trend.map((row,index)=>{
    const x=props.trend.length===1?chartWidth/2:chartPad+index*((chartWidth-chartPad*2)/(props.trend.length-1))
    const y=chartPad+(max-Number(row[field]))*((chartHeight-chartPad*2)/max)
    return (index===0?'M':'L')+' '+x.toFixed(1)+' '+y.toFixed(1)
  }).join(' ')
}
const pointFor=(field:'clicks'|'inquiries',index:number)=>{
  const max=Math.max(...props.trend.map(row=>Number(row[field])),1)
  const x=props.trend.length===1?chartWidth/2:chartPad+index*((chartWidth-chartPad*2)/(props.trend.length-1))
  const y=chartPad+(max-Number(props.trend[index]?.[field]??0))*((chartHeight-chartPad*2)/max)
  return {x,y}
}
</script>

<template>
  <Head :title="project.name+' · 流量 OS · YBY ERP'" />
  <AuthenticatedLayout title="流量 OS" :breadcrumbs="['YBY ERP',project.name,'流量 OS']" canonical-topbar>
    <section class="traffic-v2-page">
      <header class="traffic-v2-hero">
        <div>
          <p class="traffic-v2-kicker">TRAFFIC OS</p>
          <h1>流量 OS</h1>
          <h2>全渠道流量总览</h2>
          <p>统一查看网站、广告、搜索与社媒带来的曝光、点击、访问与询盘。</p>
        </div>
        <form class="traffic-v2-filters" @submit.prevent="apply">
          <label>开始日期<input v-model="filters.date_from" type="date"></label>
          <label>结束日期<input v-model="filters.date_to" type="date"></label>
          <button class="traffic-v2-button primary">应用</button>
          <button type="button" class="traffic-v2-button" @click="reset">最近 30 天</button>
        </form>
      </header>

      <TrafficSectionNav :project-key="project.key" />

      <nav class="traffic-v2-source-tabs">
        <span class="active">总览</span>
        <Link :href="'/projects/'+project.key+'/traffic/ga4'" :class="{pending:!channel('website')?.connected}">网站 <small>GA4</small></Link>
        <span>Google Ads</span>
        <span>SEO <small>GSC</small></span>
        <span class="pending">Facebook <small>Meta</small></span>
        <span class="pending">YouTube</span>
        <span class="pending">TikTok</span>
        <span class="pending">其他社媒</span>
        <span class="pending">引荐流量</span>
      </nav>

      <div class="traffic-v2-kpis">
        <article><span>总曝光量</span><strong>{{ format(summary.platform_impressions) }}</strong><small>当前日期范围 · Google Ads + GSC</small></article>
        <article><span>总点击量</span><strong>{{ format(summary.platform_clicks) }}</strong><small>当前日期范围 · Google Ads + GSC</small></article>
        <article><span>网站会话</span><strong :class="{pending:summary.website_sessions===null}">{{ format(summary.website_sessions) }}</strong><small>GA4</small></article>
        <article><span>参与会话</span><strong :class="{pending:summary.engaged_sessions===null}">{{ format(summary.engaged_sessions) }}</strong><small>GA4</small></article>
        <article><span>询盘数</span><strong>{{ format(summary.inquiries) }}</strong><small>Canonical Inquiry OS</small></article>
        <article><span>已接入渠道</span><strong>{{ summary.active_channels }}</strong><small>共 {{ summary.tracked_channels }} 个规划渠道</small></article>
      </div>

      <div class="traffic-v2-main-grid">
        <article class="traffic-v2-card">
          <div class="traffic-v2-card-head">
            <div><h3>流量趋势</h3><p>蓝线为当前可比较来源的点击量，绿线为 Canonical Inquiry OS 询盘量。</p></div>
            <div class="traffic-v2-segment"><span class="active">日</span><span>周</span><span>月</span></div>
          </div>
          <div v-if="trend.length" class="traffic-v2-chart">
            <div class="traffic-v2-legend"><span>● 平台点击</span><span>● 询盘</span></div>
            <svg :viewBox="'0 0 '+chartWidth+' '+chartHeight">
              <path :d="pathFor('clicks')" class="click-line"/><path :d="pathFor('inquiries')" class="inquiry-line"/>
              <g v-for="(row,index) in trend" :key="row.date" @click="selectedIndex=index">
                <circle :cx="pointFor('clicks',index).x" :cy="pointFor('clicks',index).y" r="5" class="click-point"/>
                <circle :cx="pointFor('inquiries',index).x" :cy="pointFor('inquiries',index).y" r="5" class="inquiry-point"/>
              </g>
            </svg>
            <div class="traffic-v2-chart-dates"><span>{{ trend[0]?.date }}</span><span>{{ trend[trend.length-1]?.date }}</span></div>
            <div v-if="selected" class="traffic-v2-tooltip">
              <strong>{{ selected.date }}</strong>
              <span>点击 {{ format(selected.clicks) }}</span>
              <span>曝光 {{ format(selected.impressions) }}</span>
              <span>网站会话 {{ format(selected.sessions) }}</span>
              <span>询盘 {{ format(selected.inquiries) }}</span>
            </div>
          </div>
        </article>

        <article class="traffic-v2-card">
          <div class="traffic-v2-card-head"><div><h3>流量结构（网站会话）</h3><p>由 GA4 按 Session Default Channel Group 统一提供。</p></div></div>
          <div v-if="traffic_mix.length" class="traffic-v2-mix-ready">
            <div v-for="row in traffic_mix" :key="row.label"><span>{{ row.label }}</span><strong>{{ row.value.toFixed(1) }}%</strong></div>
          </div>
          <div v-else class="traffic-v2-mix-pending">
            <div class="traffic-v2-donut"><span>GA4</span></div>
            <strong>等待 GA4 数据</strong>
            <p>接入后会统一展示自然搜索、付费搜索、社媒、直接访问、引荐等网站会话结构。</p>
          </div>
        </article>
      </div>

      <article class="traffic-v2-card">
        <div class="traffic-v2-card-head">
          <div><h3>渠道表现</h3><p>不同平台指标定义可能不同；只在可比较口径下做合计，平台原生指标保持独立。</p></div>
        </div>
        <div class="traffic-v2-channel-grid">
          <section v-for="row in channels" :key="row.key" class="traffic-v2-channel" :class="{offline:!row.connected}">
            <div class="traffic-v2-channel-title"><strong>{{ row.label }}</strong><small>{{ row.source }}</small></div>
            <template v-if="row.connected">
              <dl v-if="row.key==='website'">
                <div><dt>网站会话</dt><dd>{{ format(row.sessions) }}</dd></div>
                <div><dt>参与会话</dt><dd>{{ format(row.engaged_sessions) }}</dd></div>
                <div><dt>业务转化事件</dt><dd>{{ format(row.business_conversion_events) }}</dd></div>
              </dl>
              <dl v-else>
                <div><dt>曝光</dt><dd>{{ format(row.impressions) }}</dd></div>
                <div><dt>点击</dt><dd>{{ format(row.clicks) }}</dd></div>
                <div v-if="row.spend!==undefined"><dt>花费</dt><dd>{{ row.currency || '' }} {{ row.spend }}</dd></div>
              </dl>
              <span class="traffic-v2-connected">● {{ row.status }}</span>
            </template>
            <template v-else><div class="traffic-v2-channel-pending">—</div><p>{{ row.status }}</p></template>
          </section>
        </div>
      </article>

      <article class="traffic-v2-card">
        <div class="traffic-v2-card-head">
          <div><h3>跨渠道内容 / 活动排行</h3><p>当前统一展示 Google Ads Campaign 与 GSC Keyword；后续 Meta 内容会按同一日期范围接入。</p></div>
          <span class="traffic-v2-contract">Revenue 暂不进入本阶段</span>
        </div>
        <div class="traffic-v2-table-wrap"><table><thead><tr><th>#</th><th>内容 / 活动</th><th>渠道</th><th>曝光</th><th>点击</th><th>说明</th></tr></thead><tbody>
          <tr v-for="(row,index) in (top_items||[])" :key="row.key">
            <td>{{ index+1 }}</td><td><strong>{{ row.name }}</strong></td><td>{{ row.channel }}</td>
            <td>{{ format(row.impressions) }}</td><td>{{ format(row.clicks) }}</td><td>{{ row.note || '—' }}</td>
          </tr>
          <tr v-if="!(top_items||[]).length"><td colspan="6" class="traffic-v2-empty-cell">当前范围暂无可比较的跨渠道内容或活动数据。</td></tr>
        </tbody></table></div>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.traffic-v2-page{display:grid;gap:16px;padding:16px 0 30px;max-width:1540px}.traffic-v2-hero{display:flex;justify-content:space-between;gap:28px;align-items:flex-start;padding:4px 2px}.traffic-v2-kicker{margin:0 0 4px;color:#1677ff;font-size:11px;font-weight:800;letter-spacing:.12em}.traffic-v2-hero h1{margin:0;color:#0d1b3e;font-size:38px;line-height:1;font-weight:850;letter-spacing:-.04em}.traffic-v2-hero h2{margin:8px 0 4px;color:#122144;font-size:18px}.traffic-v2-hero p:last-child{margin:0;color:#687890}.traffic-v2-filters{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.traffic-v2-filters label{display:grid;gap:4px;color:#718096;font-size:11px}.traffic-v2-filters input{height:38px;border:1px solid #d5dee9;border-radius:9px;padding:0 10px}.traffic-v2-button{height:38px;padding:0 14px;border:1px solid #d5dee9;border-radius:9px;background:#fff;color:#34465f;font-weight:650}.traffic-v2-button.primary{border-color:#1677ff;background:#1677ff;color:#fff}
.traffic-v2-source-tabs{display:flex;overflow-x:auto;border:1px solid #dfe7f0;border-radius:12px;background:#fff;box-shadow:0 5px 20px rgb(38 63 90/.04)}.traffic-v2-source-tabs a,.traffic-v2-source-tabs>span{display:flex;min-width:128px;gap:6px;align-items:center;justify-content:center;padding:13px 14px;border-right:1px solid #edf1f6;color:#354760;text-decoration:none;font-size:13px;font-weight:650;white-space:nowrap}.traffic-v2-source-tabs small{color:#93a1b2;font-size:10px}.traffic-v2-source-tabs .active{background:#1677ff;color:#fff}.traffic-v2-source-tabs .pending{color:#8a97a8;background:#fbfcfe}
.traffic-v2-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.traffic-v2-kpis article{padding:17px 18px;border:1px solid #dfe7f0;border-radius:13px;background:#fff;box-shadow:0 6px 20px rgb(38 63 90/.045)}.traffic-v2-kpis span,.traffic-v2-kpis small{display:block}.traffic-v2-kpis span{color:#405169;font-size:12px;font-weight:700}.traffic-v2-kpis strong{display:block;margin:8px 0 5px;color:#0c1837;font-size:29px;font-variant-numeric:tabular-nums}.traffic-v2-kpis strong.pending{font-size:18px;color:#8b99aa}.traffic-v2-kpis small{color:#94a1b1;font-size:10px}
.traffic-v2-card{padding:18px 20px;border:1px solid #dfe7f0;border-radius:14px;background:#fff;box-shadow:0 6px 24px rgb(38 63 90/.04)}.traffic-v2-card-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:12px}.traffic-v2-card-head h3{margin:0;color:#122143;font-size:18px}.traffic-v2-card-head p{margin:4px 0 0;color:#8795a7;font-size:11px}.traffic-v2-main-grid{display:grid;grid-template-columns:minmax(0,2.05fr) minmax(310px,.9fr);gap:14px}.traffic-v2-segment{display:flex;gap:4px}.traffic-v2-segment span{padding:6px 11px;border:1px solid #dfe7f0;border-radius:7px;color:#66758a;font-size:11px}.traffic-v2-segment .active{border-color:#1677ff;color:#1677ff;background:#f3f8ff}
.traffic-v2-chart svg{width:100%;height:190px;border-top:1px solid #eef2f7;border-bottom:1px solid #eef2f7}.click-line,.inquiry-line{fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}.click-line{stroke:#1677ff}.inquiry-line{stroke:#20b26b}.click-point{fill:#fff;stroke:#1677ff;stroke-width:2;cursor:pointer}.inquiry-point{fill:#fff;stroke:#20b26b;stroke-width:2;cursor:pointer}.traffic-v2-legend{display:flex;gap:14px;margin-bottom:8px;font-size:11px}.traffic-v2-legend span:first-child{color:#1677ff}.traffic-v2-legend span:last-child{color:#20b26b}.traffic-v2-chart-dates{display:flex;justify-content:space-between;margin-top:6px;color:#9aa6b4;font-size:10px}.traffic-v2-tooltip{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;padding:9px 12px;border:1px solid #dae4ef;border-radius:9px;background:#f8fbff;color:#5c6b80;font-size:11px}
.traffic-v2-mix-pending{display:grid;place-items:center;text-align:center;padding:12px 20px 20px}.traffic-v2-donut{display:grid;place-items:center;width:150px;height:150px;border:22px solid #e8eff8;border-radius:50%;color:#8a99aa;font-weight:800}.traffic-v2-mix-pending>strong{margin-top:12px;color:#485a72}.traffic-v2-mix-pending p{max-width:330px;color:#93a0af;font-size:11px;line-height:1.6}.traffic-v2-mix-ready{display:grid;gap:9px}.traffic-v2-mix-ready>div{display:flex;justify-content:space-between}
.traffic-v2-channel-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.traffic-v2-channel{min-height:165px;padding:14px;border:1px solid #e3eaf2;border-radius:11px;background:#fff}.traffic-v2-channel.offline{background:#fafbfd}.traffic-v2-channel-title{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:12px}.traffic-v2-channel-title strong{color:#1b2b4a}.traffic-v2-channel-title small{color:#98a4b3}.traffic-v2-channel dl{display:grid;gap:7px;margin:0}.traffic-v2-channel dl>div{display:flex;justify-content:space-between;gap:8px}.traffic-v2-channel dt{color:#738196;font-size:11px}.traffic-v2-channel dd{margin:0;color:#22334f;font-weight:700}.traffic-v2-connected{display:block;margin-top:12px;color:#18a862;font-size:11px;font-weight:700}.traffic-v2-channel-pending{margin:24px 0 7px;color:#9ba7b5;font-size:20px;font-weight:800}.traffic-v2-channel.offline p{margin:0;color:#9ba7b5;font-size:11px}
.traffic-v2-text-link{color:#1677ff;text-decoration:none;font-size:12px}.traffic-v2-contract{color:#8c99aa;font-size:11px}.traffic-v2-table-wrap{overflow-x:auto}.traffic-v2-table-wrap table{width:100%;border-collapse:collapse;font-size:12px}.traffic-v2-table-wrap th,.traffic-v2-table-wrap td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:right;white-space:nowrap}.traffic-v2-table-wrap th:first-child,.traffic-v2-table-wrap td:first-child,.traffic-v2-table-wrap th:nth-child(2),.traffic-v2-table-wrap td:nth-child(2){text-align:left}.traffic-v2-table-wrap th{color:#748297}.traffic-v2-table-wrap td{color:#35465f}.traffic-v2-empty-cell{text-align:center!important;padding:24px!important;color:#98a5b5}
@media(max-width:1300px){.traffic-v2-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.traffic-v2-channel-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.traffic-v2-main-grid{grid-template-columns:1fr}}@media(max-width:820px){.traffic-v2-hero{flex-direction:column}.traffic-v2-kpis,.traffic-v2-channel-grid{grid-template-columns:1fr 1fr}}@media(max-width:560px){.traffic-v2-kpis,.traffic-v2-channel-grid{grid-template-columns:1fr}.traffic-v2-card-head{flex-direction:column}}

/* Traffic Responsive Containment V1 */
.traffic-v2-page{width:100%;min-width:0;max-width:100%;overflow-x:hidden;box-sizing:border-box}
.traffic-v2-page>*{min-width:0;max-width:100%;box-sizing:border-box}
.traffic-v2-hero,.traffic-v2-filters,.traffic-v2-card-head,.traffic-v2-main-grid,.traffic-v2-channel-grid{min-width:0;max-width:100%}
.traffic-v2-hero>*,.traffic-v2-card-head>*,.traffic-v2-main-grid>*{min-width:0}
.traffic-v2-source-tabs{width:100%;min-width:0;max-width:100%;overscroll-behavior-inline:contain}
.traffic-v2-kpis{grid-template-columns:repeat(auto-fit,minmax(160px,1fr));width:100%;min-width:0}
.traffic-v2-kpis article,.traffic-v2-card,.traffic-v2-channel{min-width:0;max-width:100%;box-sizing:border-box}
.traffic-v2-main-grid{grid-template-columns:minmax(0,2.05fr) minmax(0,.9fr)}
.traffic-v2-channel-grid{grid-template-columns:repeat(auto-fit,minmax(170px,1fr))}
.traffic-v2-filters label{min-width:0}.traffic-v2-filters input{min-width:0;max-width:100%;box-sizing:border-box}
.traffic-v2-table-wrap{display:block;width:100%;min-width:0;max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch}
.traffic-v2-table-wrap table{width:100%;min-width:max-content}
.traffic-v2-chart,.traffic-v2-chart svg{min-width:0;max-width:100%}
@media(max-width:820px){.traffic-v2-main-grid{grid-template-columns:1fr}.traffic-v2-kpis,.traffic-v2-channel-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.traffic-v2-kpis,.traffic-v2-channel-grid{grid-template-columns:1fr}}

</style>
