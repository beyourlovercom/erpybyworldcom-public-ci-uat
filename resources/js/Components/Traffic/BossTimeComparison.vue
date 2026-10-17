<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'

type ReceivedTotal = { currency:string; amount_micros:number; amount:string; receipt_count:number }
type MetricKey = 'clicks'|'inquiries'|'spend'|'won'|'received'
type Bucket = { bucket_start:string; bucket_end:string; period_start:string; period_end:string; is_partial:boolean; clicks:number; inquiries:number; cost_micros:number; spend:string; won:number; received_totals:ReceivedTotal[] }
type Totals = { clicks:number; inquiries:number; cost_micros:number; spend:string; won:number; received_totals:ReceivedTotal[] }
type Range = { date_from:string; date_to:string; effective_from:string|null; effective_to:string|null }
type TimeComparison = {
  version:string
  compare_mode:'none'|'previous'|'yoy'|'custom'
  requested_granularity:'auto'|'day'|'week'|'month'|'year'
  granularity:'day'|'week'|'month'|'year'
  current_range:Range
  compare_range:Range|null
  summary:{ current:Totals; compare:Totals|null }
  series:{ current:Bucket[]; compare:Bucket[] }
}

const props = defineProps<{
  projectKey:string
  filters:{ date_from:string; date_to:string }
  currencyCode:string|null
  mixedCurrency:boolean
  timeComparison:TimeComparison
  dataHealth:{ data_to:string|null; account_last_synced_at:string|null; sync_mode:string; automatic_sync_enabled:boolean }
}>()

const controls = reactive({
  date_from: props.filters.date_from,
  date_to: props.filters.date_to,
  compare_mode: props.timeComparison.compare_mode,
  granularity: props.timeComparison.requested_granularity,
  compare_from: props.timeComparison.compare_range?.date_from || '',
  compare_to: props.timeComparison.compare_range?.date_to || '',
})
const selectedMetric = ref<MetricKey>('clicks')
const selectedIndex = ref<number|null>(null)
const receivedCurrencies = computed(() => [...new Set([
  ...props.timeComparison.summary.current.received_totals.map(row=>row.currency),
  ...(props.timeComparison.summary.compare?.received_totals || []).map(row=>row.currency),
])])
const selectedReceivedCurrency = ref(receivedCurrencies.value[0] || '')
const projectUrl = `/projects/${props.projectKey}`
const trafficUrl = `/projects/${props.projectKey}/traffic`
const inquiriesUrl = `/projects/${props.projectKey}/inquiries`
const salesUrl = `/projects/${props.projectKey}/sales`
const receiptsUrl = computed(() => `/projects/${props.projectKey}/sales/receipts?date_from=${encodeURIComponent(props.filters.date_from)}&date_to=${encodeURIComponent(props.filters.date_to)}${selectedReceivedCurrency.value ? `&currency=${encodeURIComponent(selectedReceivedCurrency.value)}` : ''}`)
const receiptDrilldownUrl = (row:Bucket) => `/projects/${props.projectKey}/sales/receipts?date_from=${encodeURIComponent(row.period_start)}&date_to=${encodeURIComponent(row.period_end)}${selectedReceivedCurrency.value ? `&currency=${encodeURIComponent(selectedReceivedCurrency.value)}` : ''}`

const metrics:{key:MetricKey;label:string;icon:string;href:string}[] = [
  { key:'clicks', label:'流量', icon:'↗', href:trafficUrl },
  { key:'inquiries', label:'新增询盘', icon:'●', href:inquiriesUrl },
  { key:'spend', label:'广告开支', icon:'$', href:trafficUrl },
  { key:'won', label:'成交数量', icon:'✓', href:salesUrl },
  { key:'received', label:'实收', icon:'¥', href:receiptsUrl.value },
]
const metricHref = (metric:{key:MetricKey;href:string}) => metric.key === 'received' ? receiptsUrl.value : metric.href

const integer = (value:number) => Number(value || 0).toLocaleString('zh-CN')
const money = (value:string|number) => {
  if (!props.currencyCode || props.mixedCurrency) return '—'
  return new Intl.NumberFormat('zh-CN', { style:'currency', currency:props.currencyCode, minimumFractionDigits:2, maximumFractionDigits:2 }).format(Number(value || 0))
}
const moneyForCurrency = (value:number, currencyCode:string) => currencyCode ? new Intl.NumberFormat('zh-CN', { style:'currency', currency:currencyCode, minimumFractionDigits:2, maximumFractionDigits:2 }).format(Number(value || 0)) : '—'
const receivedNumber = (row:Bucket|Totals|undefined|null) => {
  if (!row) return 0
  const match = row.received_totals.find(item=>item.currency===selectedReceivedCurrency.value)
  return match ? Number(match.amount_micros || 0) / 1_000_000 : 0
}
const metricNumber = (row:Bucket|Totals|undefined|null, key:MetricKey) => {
  if (!row) return 0
  if (key === 'spend') return Number(row.spend || 0)
  if (key === 'received') return receivedNumber(row)
  return Number(row[key] || 0)
}
const metricDisplay = (key:MetricKey, value:number) => key === 'spend' ? money(value) : key === 'received' ? moneyForCurrency(value, selectedReceivedCurrency.value) : integer(value)
const compareMetricNumber = (key:MetricKey) => metricNumber(props.timeComparison.summary.compare, key)

const delta = (key:MetricKey) => {
  const current = metricNumber(props.timeComparison.summary.current, key)
  const compare = metricNumber(props.timeComparison.summary.compare, key)
  if (!props.timeComparison.summary.compare) return { value:null as number|null, text:props.timeComparison.compare_mode === 'none' ? '未启用对比' : '对比期暂无数据' }
  if (compare === 0) return { value:null as number|null, text:current === 0 ? '0.0%' : '上期为 0' }
  const value = ((current - compare) / compare) * 100
  return { value, text:`${value >= 0 ? '+' : ''}${value.toFixed(1)}%` }
}
const deltaClass = (key:MetricKey) => {
  const value = delta(key).value
  return { up:value !== null && value > 0, down:value !== null && value < 0 }
}
const deltaArrow = (key:MetricKey) => {
  const value = delta(key).value
  return value === null ? '—' : value >= 0 ? '↑' : '↓'
}
const compareLabel = computed(() => ({ none:'不对比', previous:'上一周期', yoy:'去年同期', custom:'自定义周期' } as Record<string,string>)[props.timeComparison.compare_mode] || '对比周期')
const granularityLabel = computed(() => ({ day:'按天', week:'按周', month:'按月', year:'按年' } as Record<string,string>)[props.timeComparison.granularity])

const routeQuery = () => {
  const query:Record<string,string> = { date_from:controls.date_from, date_to:controls.date_to, compare_mode:controls.compare_mode, granularity:controls.granularity }
  if (controls.compare_mode === 'custom') { query.compare_from = controls.compare_from; query.compare_to = controls.compare_to }
  return query
}
const apply = () => router.get(projectUrl, routeQuery(), { preserveState:true, replace:true })
const setGranularity = (value:'auto'|'day'|'week'|'month'|'year') => { controls.granularity = value; apply() }
const setCompareMode = () => {
  if (controls.compare_mode === 'custom' && (!controls.compare_from || !controls.compare_to)) {
    controls.compare_from = props.timeComparison.compare_range?.date_from || controls.date_from
    controls.compare_to = props.timeComparison.compare_range?.date_to || controls.date_to
  }
  apply()
}
const parseDate = (value:string) => new Date(`${value}T00:00:00`)
const dateString = (date:Date) => {
  const y=date.getFullYear(), m=String(date.getMonth()+1).padStart(2,'0'), d=String(date.getDate()).padStart(2,'0')
  return `${y}-${m}-${d}`
}
const spanDays = computed(() => Math.round((parseDate(controls.date_to).getTime()-parseDate(controls.date_from).getTime())/86400000)+1)
const activePreset = (preset:'7d'|'30d'|'90d'|'1y'|'ytd'|'all') => {
  if (preset==='7d') return spanDays.value===7
  if (preset==='30d') return spanDays.value===30
  if (preset==='90d') return spanDays.value===90
  if (preset==='1y') return spanDays.value===365
  if (preset==='ytd') { const date=parseDate(controls.date_from); return date.getMonth()===0 && date.getDate()===1 && date.getFullYear()===parseDate(controls.date_to).getFullYear() }
  if (preset==='all') return spanDays.value>3650
  return false
}
const setPreset = (preset:'7d'|'30d'|'90d'|'1y'|'ytd'|'all') => {
  const end=parseDate(controls.date_to), start=new Date(end)
  if (preset==='7d') start.setDate(start.getDate()-6)
  if (preset==='30d') start.setDate(start.getDate()-29)
  if (preset==='90d') start.setDate(start.getDate()-89)
  if (preset==='1y') start.setDate(start.getDate()-364)
  if (preset==='ytd') start.setMonth(0,1)
  if (preset==='all') start.setFullYear(start.getFullYear()-10)
  controls.date_from=dateString(start); apply()
}

const chartWidth=960, chartHeight=260, chartPadX=28, chartPadY=22
const allValues = computed(() => [
  ...props.timeComparison.series.current.map(row=>metricNumber(row,selectedMetric.value)),
  ...props.timeComparison.series.compare.map(row=>metricNumber(row,selectedMetric.value)),
])
const chartMax = computed(() => Math.max(...allValues.value,1))
const chartPoint = (row:Bucket,index:number,length:number) => {
  const x=length<=1?chartWidth/2:chartPadX+index*((chartWidth-chartPadX*2)/(length-1))
  const y=chartPadY+(chartMax.value-metricNumber(row,selectedMetric.value))*((chartHeight-chartPadY*2)/(chartMax.value||1))
  return {x,y}
}
const pathFor = (rows:Bucket[]) => rows.map((row,index) => {
  const point=chartPoint(row,index,rows.length)
  return `${index===0?'M':'L'} ${point.x.toFixed(1)} ${point.y.toFixed(1)}`
}).join(' ')
const sparkPath = (key:MetricKey) => {
  const rows=props.timeComparison.series.current
  if (!rows.length) return ''
  const width=240,height=46,pad=3,values=rows.map(row=>metricNumber(row,key)),max=Math.max(...values,1)
  return rows.map((row,index)=>{
    const x=rows.length<=1?width/2:pad+index*((width-pad*2)/(rows.length-1))
    const y=pad+(max-metricNumber(row,key))*((height-pad*2)/max)
    return `${index===0?'M':'L'} ${x.toFixed(1)} ${y.toFixed(1)}`
  }).join(' ')
}
const axisLabels = computed(() => {
  const rows=props.timeComparison.series.current
  if (!rows.length) return []
  const wanted=Math.min(5,rows.length)
  const indexes=Array.from({length:wanted},(_,i)=>Math.round(i*(rows.length-1)/Math.max(wanted-1,1)))
  return [...new Set(indexes)].map(index=>rows[index])
})
const selectedCurrent = computed(() => selectedIndex.value===null?null:props.timeComparison.series.current[selectedIndex.value]||null)
const selectedCompare = computed(() => selectedIndex.value===null?null:props.timeComparison.series.compare[selectedIndex.value]||null)
const periodText = (row:Bucket) => row.period_start===row.period_end?row.period_start:`${row.period_start} ~ ${row.period_end}`
const bucketText = (row:Bucket,index:number) => {
  if (props.timeComparison.granularity==='day') return row.period_start
  if (props.timeComparison.granularity==='week') return `W${index+1}`
  if (props.timeComparison.granularity==='month') return row.bucket_start.slice(0,7)
  return row.bucket_start.slice(0,4)
}
const rate = (top:number,bottom:number) => bottom>0?`${((top/bottom)*100).toFixed(2)}%`:'0.00%'
const exportCsv = () => {
  const header = ['周期','时间范围','流量','新增询盘','广告开支','成交数量',`实收(${selectedReceivedCurrency.value})`,'询盘率','成交率']
  const body = props.timeComparison.series.current.map((row,index) => [
    bucketText(row,index), periodText(row), row.clicks, row.inquiries, row.spend, row.won,
    receivedNumber(row), rate(row.inquiries,row.clicks), rate(row.won,row.inquiries),
  ])
  const csv = [header,...body]
    .map(row => row.map(value => `"${String(value).replaceAll('"','""')}"`).join(','))
    .join('\n')
  const blob = new Blob(['\uFEFF' + csv], { type:'text/csv;charset=utf-8' })
  const objectUrl = URL.createObjectURL(blob)
  const downloadLink = document.createElement('a')
  downloadLink.href = objectUrl
  downloadLink.download = `boss-time-comparison-${controls.date_from}-${controls.date_to}.csv`
  downloadLink.click()
  URL.revokeObjectURL(objectUrl)
}
</script>

<template>
  <section class="time-comparison-shell">
    <div class="comparison-topbar">
      <div class="date-range-control">
        <input v-model="controls.date_from" type="date" aria-label="开始日期"><span>→</span><input v-model="controls.date_to" type="date" aria-label="结束日期"><button type="button" @click="apply">应用</button>
      </div>
      <div class="preset-row" aria-label="快速时间范围">
        <button type="button" :class="{'is-active':activePreset('7d')}" @click="setPreset('7d')">7天</button><button type="button" :class="{'is-active':activePreset('30d')}" @click="setPreset('30d')">30天</button><button type="button" :class="{'is-active':activePreset('90d')}" @click="setPreset('90d')">90天</button><button type="button" :class="{'is-active':activePreset('1y')}" @click="setPreset('1y')">1年</button><button type="button" :class="{'is-active':activePreset('ytd')}" @click="setPreset('ytd')">本年</button><button type="button" :class="{'is-active':activePreset('all')}" @click="setPreset('all')">全部</button>
      </div>
    </div>

    <div class="comparison-kpis">
      <Link v-for="metric in metrics" :key="metric.key" :href="metricHref(metric)" class="comparison-kpi">
        <div class="kpi-title"><span class="kpi-icon">{{ metric.icon }}</span><span>{{ metric.label }}</span><b>›</b></div>
        <strong>{{ metricDisplay(metric.key,metricNumber(timeComparison.summary.current,metric.key)) }}</strong>
        <div class="delta-line" :class="deltaClass(metric.key)"><span>{{ deltaArrow(metric.key) }}</span><b>{{ delta(metric.key).text }}</b></div>
        <small v-if="timeComparison.summary.compare">{{ compareLabel }} {{ metricDisplay(metric.key,compareMetricNumber(metric.key)) }}</small><small v-else>{{ timeComparison.compare_mode === 'none' ? '当前未启用对比' : compareLabel + '暂无数据' }}</small>
        <svg viewBox="0 0 240 46" role="img" :aria-label="metric.label + '趋势'"><path :d="sparkPath(metric.key)" /></svg>
      </Link>
    </div>

    <article class="comparison-panel">
      <div class="panel-heading"><div><p>▥ 数据趋势对比</p><h3>选择时间范围和对比方式，查看关键指标的趋势变化</h3></div><button type="button" class="export-button" @click="exportCsv">⇩ 导出数据</button></div>
      <div class="comparison-controls">
        <div class="control-group"><label>时间范围</label><div class="segmented"><button type="button" :class="{'is-active':activePreset('7d')}" @click="setPreset('7d')">7天</button><button type="button" :class="{'is-active':activePreset('30d')}" @click="setPreset('30d')">30天</button><button type="button" :class="{'is-active':activePreset('90d')}" @click="setPreset('90d')">90天</button><button type="button" :class="{'is-active':activePreset('1y')}" @click="setPreset('1y')">1年</button><button type="button" :class="{'is-active':activePreset('ytd')}" @click="setPreset('ytd')">本年</button><button type="button" :class="{'is-active':activePreset('all')}" @click="setPreset('all')">全部</button></div></div>
        <div class="control-group compare-mode"><label>对比方式</label><select v-model="controls.compare_mode" @change="setCompareMode"><option value="none">不对比</option><option value="previous">对比上一周期</option><option value="yoy">同比去年同期</option><option value="custom">自定义对比区间</option></select></div>
        <div class="control-group"><label>粒度 <small>当前 {{ granularityLabel }}</small></label><div class="segmented"><button type="button" :class="{'is-active':controls.granularity==='auto'}" @click="setGranularity('auto')">自动</button><button type="button" :class="{'is-active':timeComparison.granularity==='day'&&controls.granularity!=='auto'}" @click="setGranularity('day')">按天</button><button type="button" :class="{'is-active':timeComparison.granularity==='week'&&controls.granularity!=='auto'}" @click="setGranularity('week')">按周</button><button type="button" :class="{'is-active':timeComparison.granularity==='month'&&controls.granularity!=='auto'}" @click="setGranularity('month')">按月</button><button type="button" :class="{'is-active':timeComparison.granularity==='year'&&controls.granularity!=='auto'}" @click="setGranularity('year')">按年</button></div></div>
      </div>
      <div v-if="controls.compare_mode==='custom'" class="custom-compare-row"><label>对比开始<input v-model="controls.compare_from" type="date"></label><label>对比结束<input v-model="controls.compare_to" type="date"></label><button type="button" @click="apply">应用自定义对比</button></div>
      <div class="metric-tabs"><button v-for="metric in metrics" :key="metric.key" type="button" :class="{active:selectedMetric===metric.key}" @click="selectedMetric=metric.key"><span>{{ metric.icon }}</span>{{ metric.label }}<small v-if="metric.key==='received'">{{ selectedReceivedCurrency }}</small></button></div>
      <div v-if="selectedMetric==='received' && receivedCurrencies.length>1" class="received-currency-tabs"><span>实收币种</span><button v-for="code in receivedCurrencies" :key="code" type="button" :class="{active:selectedReceivedCurrency===code}" @click="selectedReceivedCurrency=code">{{ code }}</button></div>

      <div v-if="timeComparison.series.current.length" class="main-chart">
        <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" role="img" :aria-label="metrics.find(item=>item.key===selectedMetric)?.label + '时间对比趋势'">
          <g class="grid-lines"><line v-for="n in 5" :key="n" :x1="chartPadX" :x2="chartWidth-chartPadX" :y1="chartPadY+(n-1)*((chartHeight-chartPadY*2)/4)" :y2="chartPadY+(n-1)*((chartHeight-chartPadY*2)/4)" /></g>
          <path v-if="timeComparison.series.compare.length" :d="pathFor(timeComparison.series.compare)" class="compare-line" /><path :d="pathFor(timeComparison.series.current)" class="current-line" />
          <circle v-for="(row,index) in timeComparison.series.current" :key="row.bucket_start" :cx="chartPoint(row,index,timeComparison.series.current.length).x" :cy="chartPoint(row,index,timeComparison.series.current.length).y" r="5" class="current-point" @mouseenter="selectedIndex=index" @click="selectedIndex=index"><title>{{ periodText(row) }} · {{ metricDisplay(selectedMetric,metricNumber(row,selectedMetric)) }}</title></circle>
        </svg>
        <div v-if="selectedCurrent" class="chart-tooltip"><strong>{{ periodText(selectedCurrent) }}</strong><span><i class="dot current"></i>当前周期 <b>{{ metricDisplay(selectedMetric,metricNumber(selectedCurrent,selectedMetric)) }}</b></span><span v-if="selectedCompare"><i class="dot compare"></i>{{ compareLabel }} <b>{{ metricDisplay(selectedMetric,metricNumber(selectedCompare,selectedMetric)) }}</b></span><Link v-if="selectedMetric==='received'" class="received-drill-link" :href="receiptDrilldownUrl(selectedCurrent)">查看本周期回款明细 →</Link></div>
        <div class="axis-labels"><span v-for="row in axisLabels" :key="row.bucket_start">{{ row.period_start.slice(5) }}<small v-if="row.is_partial">部分</small></span></div>
        <div class="chart-legend"><span><i class="dot current"></i>当前周期（{{ timeComparison.current_range.date_from }} ~ {{ timeComparison.current_range.effective_to || timeComparison.current_range.date_to }}）</span><span v-if="timeComparison.compare_range"><i class="dot compare"></i>{{ compareLabel }}（{{ timeComparison.compare_range.date_from }} ~ {{ timeComparison.compare_range.effective_to || timeComparison.compare_range.date_to }}）</span></div>
      </div>
      <p v-else class="empty-chart">当前日期范围暂无可用于趋势对比的数据。</p>
    </article>

    <article class="comparison-table-card">
      <div class="table-heading"><div><p>▤ 时间数据明细</p><h3>按 {{ granularityLabel.replace('按','') }} 展示核心指标数据</h3></div><span>共 {{ timeComparison.series.current.length }} 个周期</span></div>
      <div class="comparison-table-wrap"><table><thead><tr><th>周期</th><th>时间范围</th><th>流量</th><th>新增询盘</th><th>广告开支</th><th>成交数量</th><th>实收 · {{ selectedReceivedCurrency }}</th><th>询盘率</th><th>成交率</th></tr></thead><tbody>
        <tr v-for="(row,index) in timeComparison.series.current" :key="row.bucket_start"><td><strong>{{ bucketText(row,index) }}</strong><small v-if="row.is_partial">部分周期</small></td><td>{{ periodText(row) }}</td><td>{{ integer(row.clicks) }}</td><td>{{ integer(row.inquiries) }}</td><td>{{ money(row.spend) }}</td><td>{{ integer(row.won) }}</td><td><Link class="received-drill-link" :href="receiptDrilldownUrl(row)">{{ metricDisplay('received',receivedNumber(row)) }}</Link></td><td>{{ rate(row.inquiries,row.clicks) }}</td><td>{{ rate(row.won,row.inquiries) }}</td></tr>
        <tr v-if="!timeComparison.series.current.length"><td colspan="9">当前没有时间对比数据。</td></tr>
      </tbody></table></div>
      <div class="period-note">ⓘ 当前数据按自然{{ granularityLabel.replace('按','') }}聚合；边界不足完整周期时标记“部分周期”。对比周期遵循相同指标口径，不把尚未同步的数据冒充为 0。<strong>最新数据 {{ dataHealth.data_to || '—' }}</strong><span v-if="!dataHealth.automatic_sync_enabled">当前尚未启用 Google Ads Production 自动同步</span></div>
    </article>
  </section>
</template>

<style scoped>
.time-comparison-shell{display:grid;gap:16px;min-width:0}.comparison-topbar{display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap}.date-range-control{display:flex;align-items:center;gap:7px;padding:7px 9px;border:1px solid #d9e3e7;border-radius:11px;background:#fff;box-shadow:0 4px 14px rgb(26 55 65 / .04)}.date-range-control input{border:0;background:transparent;color:#283746;font-size:12px;outline:0}.date-range-control button,.preset-row button,.segmented button{border:0;background:transparent;color:#617183;font-weight:700;cursor:pointer}.date-range-control button{padding:7px 11px;border-radius:8px;background:#087f5b;color:#fff}
.preset-row{display:flex;align-items:center;border:1px solid #d9e3e7;border-radius:11px;background:#fff;overflow:hidden}.preset-row button{min-height:40px;padding:0 13px;border-right:1px solid #eef2f3}.preset-row button:last-child{border-right:0}.preset-row button:hover,.preset-row .is-active{background:#eaf7f1;color:#087f5b}
.comparison-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.comparison-kpi{display:grid;gap:7px;min-width:0;padding:17px 18px 12px;border:1px solid #e0e8eb;border-radius:15px;background:#fff;color:#17223d;text-decoration:none;box-shadow:0 8px 26px rgb(28 51 63 / .05);transition:.15s ease}.comparison-kpi:hover{transform:translateY(-1px);box-shadow:0 10px 30px rgb(28 51 63 / .08)}.kpi-title{display:flex;align-items:center;gap:9px;color:#415269;font-size:12px;font-weight:800}.kpi-title b{margin-left:auto;color:#7690a5;font-size:20px}.kpi-icon{display:grid;place-items:center;width:30px;height:30px;border-radius:999px;background:#e4f7ef;color:#087f5b;font-size:17px}.comparison-kpi:nth-child(2) .kpi-icon{background:#e9f2ff;color:#2b7de9}.comparison-kpi:nth-child(3) .kpi-icon{background:#fff1d8;color:#e78913}.comparison-kpi:nth-child(4) .kpi-icon{background:#f0e9ff;color:#7c4ce4}
.comparison-kpi>strong{font-size:28px;line-height:1;font-variant-numeric:tabular-nums}.comparison-kpi small{color:#92a0af;font-size:10px}.delta-line{display:flex;align-items:center;gap:5px;color:#708092;font-size:11px}.delta-line.up{color:#0b9b67}.delta-line.down{color:#d64b45}.delta-line b{font-weight:800}.comparison-kpi svg{width:100%;height:46px;min-width:0}.comparison-kpi path{fill:none;stroke:#159b69;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round}.comparison-kpi:nth-child(2) path{stroke:#2b7de9}.comparison-kpi:nth-child(3) path{stroke:#ee941e}.comparison-kpi:nth-child(4) path{stroke:#7b4be4}.comparison-kpi:nth-child(5) .kpi-icon{background:#e8f7ef;color:#087f5b}.comparison-kpi:nth-child(5) path{stroke:#087f5b}
.comparison-panel,.comparison-table-card{position:relative;min-width:0;padding:18px;border:1px solid #dce6e9;border-radius:16px;background:#fff;box-shadow:0 8px 28px rgb(28 51 63 / .05)}.panel-heading,.table-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}.panel-heading p,.table-heading p{margin:0;color:#087f5b;font-size:15px;font-weight:850}.panel-heading h3,.table-heading h3{margin:4px 0 0;color:#657488;font-size:11px;font-weight:500}.export-button{padding:9px 13px;border:1px solid #cfdade;border-radius:9px;background:#fff;color:#415269;font-weight:700;cursor:pointer}
.comparison-controls{display:grid;grid-template-columns:1.35fr .9fr 1.25fr;gap:12px;margin-top:18px}.control-group{display:grid;gap:7px}.control-group>label{display:flex;justify-content:space-between;color:#64748b;font-size:11px;font-weight:700}.control-group label small{color:#99a6b3;font-weight:500}.segmented{display:flex;min-width:0;border:1px solid #dce5e8;border-radius:9px;background:#f8fafb;overflow:hidden}.segmented button{flex:1;min-height:38px;padding:0 10px;border-right:1px solid #e5ebed;white-space:nowrap}.segmented button:last-child{border-right:0}.segmented button.is-active,.segmented button:hover{background:#087f5b;color:#fff}.compare-mode select{min-height:40px;padding:0 12px;border:1px solid #dce5e8;border-radius:9px;background:#fff;color:#334155}
.custom-compare-row{display:flex;align-items:end;gap:10px;margin-top:12px;padding:11px;border-radius:10px;background:#f7faf9}.custom-compare-row label{display:grid;gap:4px;color:#64748b;font-size:10px}.custom-compare-row input{min-height:35px;border:1px solid #dce5e8;border-radius:8px;padding:0 9px}.custom-compare-row button{min-height:35px;padding:0 12px;border:0;border-radius:8px;background:#087f5b;color:#fff;font-weight:750}.metric-tabs{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin:16px 0}.metric-tabs button{min-height:42px;border:1px solid #dfe7ea;border-radius:8px;background:#f8fafb;color:#59697c;font-weight:750;cursor:pointer}.metric-tabs button span{margin-right:7px}.metric-tabs button small{margin-left:5px;font-size:9px}.metric-tabs button.active{background:#119267;color:#fff;border-color:#119267}.received-currency-tabs{display:flex;align-items:center;gap:7px;margin:-6px 0 14px;color:#6b7b8d;font-size:10px}.received-currency-tabs button{padding:5px 9px;border:1px solid #dce5e8;border-radius:999px;background:#fff;color:#526477;font-size:10px;font-weight:800;cursor:pointer}.received-currency-tabs button.active{border-color:#119267;background:#eaf7f1;color:#087f5b}
.main-chart{position:relative;min-width:0;padding:6px 2px 0;overflow-x:auto}.main-chart>svg{display:block;width:100%;height:260px;min-width:580px;overflow:visible}.grid-lines line{stroke:#e7edef;stroke-width:1;stroke-dasharray:3 4}.current-line,.compare-line{fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}.current-line{stroke:#10956a}.compare-line{stroke:#91a5b8;stroke-dasharray:7 6}.current-point{fill:#10956a;stroke:#fff;stroke-width:2;cursor:pointer}.chart-tooltip{position:absolute;top:18px;right:28px;display:grid;gap:6px;min-width:190px;padding:10px 12px;border:1px solid #dde6e9;border-radius:10px;background:#fff;box-shadow:0 12px 26px rgb(30 50 60 / .13);font-size:11px;color:#657488}.chart-tooltip strong{color:#26384b}.chart-tooltip span{display:flex;align-items:center;gap:6px}.chart-tooltip b{margin-left:auto;color:#1f3144}.received-drill-link{color:#087f5b;font-weight:800;text-decoration:none}.received-drill-link:hover{text-decoration:underline}
.dot{display:inline-block;width:9px;height:9px;border-radius:999px}.dot.current{background:#10956a}.dot.compare{background:#91a5b8}.axis-labels{display:flex;justify-content:space-between;gap:10px;color:#7e8ea0;font-size:10px}.axis-labels span{display:grid;text-align:center}.axis-labels small{color:#b07b28;font-size:8px}.chart-legend{display:flex;justify-content:center;gap:22px;flex-wrap:wrap;margin-top:13px;color:#758599;font-size:10px}.chart-legend span{display:flex;align-items:center;gap:6px}.empty-chart{padding:42px;text-align:center;color:#94a3b8}
.table-heading>span{color:#7c8b9a;font-size:10px}.comparison-table-wrap{margin-top:12px;overflow-x:auto}.comparison-table-wrap table{width:100%;border-collapse:collapse;font-size:11px;min-width:820px}.comparison-table-wrap th,.comparison-table-wrap td{padding:10px 11px;border-bottom:1px solid #e7edef;text-align:right;color:#43546a;white-space:nowrap}.comparison-table-wrap th{background:#f6f8fa;color:#607186;font-weight:800}.comparison-table-wrap th:first-child,.comparison-table-wrap td:first-child,.comparison-table-wrap th:nth-child(2),.comparison-table-wrap td:nth-child(2){text-align:left}.comparison-table-wrap td strong{display:block;color:#26384b}.comparison-table-wrap td small{display:block;color:#b17c2e;font-size:8px}.comparison-table-wrap tr:hover td{background:#f0faf6}.period-note{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding:10px 12px;border-radius:9px;background:#eef7ff;color:#60758a;font-size:10px}.period-note strong{margin-left:auto;color:#47647d}.period-note span{color:#8b6b2e}
@media(max-width:1200px){.comparison-kpis{grid-template-columns:repeat(2,1fr)}.comparison-controls{grid-template-columns:1fr}.comparison-topbar{justify-content:flex-start}}@media(max-width:700px){.comparison-kpis{grid-template-columns:1fr}.comparison-topbar,.date-range-control,.custom-compare-row{align-items:stretch;flex-direction:column}.preset-row{display:grid;grid-template-columns:repeat(3,1fr)}.preset-row button{border-right:0;border-bottom:1px solid #eef2f3}.metric-tabs{grid-template-columns:repeat(2,1fr)}.chart-tooltip{position:static;margin-bottom:8px}}
</style>
