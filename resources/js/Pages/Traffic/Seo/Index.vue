<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, nextTick, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import TrafficSectionNav from '../../../Components/Traffic/TrafficSectionNav.vue'

type EvidenceAds = { clicks:number; conversions:number; cost:number } | null
type EvidenceGsc = { impressions:number; clicks:number; average_position:number|null } | null
type EvidenceAlibaba = { impressions:number; clicks:number } | null
type KeywordRow = { id:number; keyword:string; topic_cluster:string|null; suggested_action:string|null; suggested_target_type:string|null; opportunity_score:string|null; ads:EvidenceAds; gsc:EvidenceGsc; alibaba:EvidenceAlibaba }
type KeywordPaginator = { data:KeywordRow[]; current_page:number; last_page:number; total:number; prev_page_url:string|null; next_page_url:string|null }

type GrowthQueueRow = {
  key:string
  kind:'existing_page'|'new_content'
  priority:'P0'|'P1'|'P2'
  decision:string
  keyword:string
  topic_cluster:string|null
  suggested_action:string
  suggested_target_type:string|null
  target_url:string|null
  opportunity_score:number
  impressions:number
  clicks:number
  ctr:number
  average_position:number|null
  keyword_count:number
  latest_metric_date:string|null
  rationale:string
}
type Measurement = {
  metric_from:string|null
  metric_to:string|null
  impressions:number
  clicks:number
  ctr:number
  average_position:number|null
  distinct_queries:number
  competing_pages:number
} | null
type MonitoringRow = {
  publication_id:number
  work_order_id:number
  keyword:string
  topic_cluster:string|null
  baseline_url:string
  published_url:string
  production_post_id:number|null
  publish_date:string
  retest_after:string
  days_remaining:number
  owner_uat:string|null
  state:string
  baseline:{ impressions:number; clicks:number; average_position:number|null }
  t0:Measurement
  t28:Measurement
  signal:{ position_delta:number|null; impressions_daily_change:number|null; clicks_daily_change:number|null }
}
type GrowthPayload = {
  version:string
  scope:{ mode:string; hosts:string[]; rule:string }
  summary:{
    actionable:number; existing_page:number; new_content:number; p0:number
    monitoring:number; retest_due:number; winner:number; loser:number; mixed:number
    international_planned:number; published_pages_filtered:number
    low_evidence_filtered:number; migration_content_planned:number
  }
  queue:GrowthQueueRow[]
  monitoring:MonitoringRow[]
  data_health:{
    gsc_live_connected:boolean; scoped_gsc_rows:number; scoped_gsc_keywords:number
    scoped_impressions:number; latest_metric_date:string|null
    international_gsc_rows_excluded:number; international_impressions_excluded:number
    raw_keyword_count:number; consolidated_new_content_truth:boolean
  }
  international:{ planned:number; top:Array<{work_order_id:number;target_url:string;impressions:number;clicks:number;average_position:number|null;priority_score:number}> }
}

const props = defineProps<{
  project:{ key:string; name:string }
  filters:{ search:string; action:string; source:string }
  summary:{ keywords:number; evidence_rows:number; priority_opportunities:number; gsc_live_rows:number; content_backlog:number }
  action_counts:Record<string,number>
  evidence_counts:Record<string,number>
  actions:string[]
  sources:string[]
  keywords:KeywordPaginator
  data_health:{ seed_loaded:boolean; gsc_live_connected:boolean; attribution_state:string }
  growth:GrowthPayload
}>()

const filters = reactive({ ...props.filters })
const baseUrl = `/projects/${props.project.key}/traffic/seo`
const apply = () => router.get(baseUrl, filters, { preserveState:true, replace:true })
const clear = () => router.get(baseUrl)
const integer = (value:number) => Number(value || 0).toLocaleString('zh-CN')
const position = (value:number|null|undefined) => value == null ? '—' : Number(value).toFixed(2)
const score = (value:string|number|null) => value == null ? '—' : Number(value).toFixed(1)
const percent = (value:number|null|undefined) => value == null ? '—' : `${(value * 100).toFixed(1)}%`
const shortUrl = (value:string|null) => value ? value.replace(/^https?:\/\//, '').replace(/\/$/, '') : '待创建'
const actionLabels:Record<string,string> = {
  NEW_CONTENT_P0:'新内容 P0', PAGE1_P0:'冲首页 P0', TOP3_CTR_P0:'冲 Top 3 / CTR P0',
  TOP3_P1:'Top 3 P1', CTR_P1:'CTR 优化 P1', OPTIMIZE_P2:'现有页优化 P2',
  RESEARCH_P2:'研究 P2', BACKLOG_P3:'长期内容库 P3', DEFEND:'防守已有排名',
}
const decisionLabels:Record<string,string> = {
  push_page1:'冲 Google 首页', push_top3_ctr:'冲 Top 3 / CTR', improve_ctr:'提升 CTR',
  defend:'防守已有排名', optimize_existing:'优化现有页', research_existing:'研究现有页',
  new_blog:'新建 Blog', comparison_content:'新建比较内容', new_commercial_page:'新建商业页',
}
const stateLabels:Record<string,string> = {
  monitoring:'监测中', retest_due:'到期复测', winner:'Winner', loser:'Loser', mixed:'Mixed',
}
const sourceLabels:Record<string,string> = { google_ads:'Google Ads', google_search_console:'GSC', alibaba:'Alibaba' }
const targetLabel = (value:string|null) => ({
  'Blog Article':'Blog', 'Commercial Landing Page':'商业落地页', 'Product/Category Page':'产品/分类页',
  'Existing Page Optimization':'优化现有页', 'Topic Cluster Merge':'Topic Cluster 合并',
  'Landing Page':'落地页', 'Comparison Blog':'比较内容', 'Blog':'Blog', 'Review':'待复核',
} as Record<string,string>)[value || ''] || value || '待判断'
const priorityCards = computed(() => [
  { key:'NEW_CONTENT_P0', label:'原始新内容', note:'Keyword evidence 层，不等于最终 Growth Queue' },
  { key:'PAGE1_P0', label:'原始冲首页', note:'需再次通过 scoped GSC Quality Gate' },
  { key:'TOP3_CTR_P0', label:'原始 Top 3 / CTR', note:'需再次通过 scoped GSC Quality Gate' },
])
const queuePriority = ref<'ALL'|'P0'>('ALL')
const monitoringState = ref<'ALL'|'monitoring'|'retest_due'>('ALL')
const filteredGrowthQueue = computed(() => queuePriority.value === 'ALL'
  ? props.growth.queue
  : props.growth.queue.filter(row => row.priority === queuePriority.value))
const filteredMonitoring = computed(() => monitoringState.value === 'ALL'
  ? props.growth.monitoring
  : props.growth.monitoring.filter(row => row.state === monitoringState.value))
const growthCards = computed(() => [
  { key:'actionable', label:'可执行机会', value:props.growth.summary.actionable, note:`${props.growth.summary.existing_page} 现有页 + ${props.growth.summary.new_content} 新内容`, tone:'strong', target:'growth-queue', queue:'ALL' },
  { key:'p0', label:'P0 队列', value:props.growth.summary.p0, note:'Quality Gate 后真正高优先级', tone:'strong', target:'growth-queue', queue:'P0' },
  { key:'monitoring', label:'监测中', value:props.growth.summary.monitoring, note:'点击查看未来 28 天跟踪任务', tone:'normal', target:'monitoring-queue', monitoring:'monitoring' },
  { key:'retest', label:'到期复测', value:props.growth.summary.retest_due, note:'点击查看需要 T28 snapshot 的任务', tone:props.growth.summary.retest_due ? 'warn' : 'normal', target:'monitoring-queue', monitoring:'retest_due' },
  { key:'migration', label:'迁移内容隔离', value:props.growth.summary.migration_content_planned, note:'点击查看迁移任务去向', tone:'normal', target:'isolated-queues' },
  { key:'international', label:'国际任务隔离', value:props.growth.summary.international_planned, note:'点击查看多语言任务', tone:'normal', target:'isolated-queues' },
])
const jumpToGrowthSection = async (card:{target:string;queue?:string;monitoring?:string}) => {
  if (card.queue === 'P0' || card.queue === 'ALL') queuePriority.value = card.queue
  if (card.monitoring === 'monitoring' || card.monitoring === 'retest_due') monitoringState.value = card.monitoring
  await nextTick()
  document.getElementById(card.target)?.scrollIntoView({ behavior:'smooth', block:'start' })
}
const filterAction = (action:string) => { filters.action = action; apply() }
</script>

<template>
  <Head :title="`${project.name} · SEO Growth Engine`" />
  <AuthenticatedLayout title="SEO Growth Engine" :breadcrumbs="[project.name, '流量', 'SEO Growth Engine']">
    <section class="mvp-page seo-workspace">
      <header class="workspace-heading">
        <div>
          <p class="mvp-kicker">SEO-7 / GROWTH ENGINE V1</p>
          <h2>{{ project.name }} · SEO Growth Engine</h2>
          <p>机会发现 → Quality Gate → Growth Queue → 发布监测 → 28 天复测 → Winner / Loser 反馈。执行仍走 Work Order / Owner UAT，不自动改站。</p>
        </div>
        <Link class="mvp-button" :href="`/projects/${project.key}/traffic`">返回流量总览</Link>
      </header>

      <TrafficSectionNav :project-key="project.key" />

      <div class="growth-kpi-grid">
        <button
          v-for="card in growthCards"
          :key="card.key"
          type="button"
          class="growth-kpi"
          :data-tone="card.tone"
          :aria-label="`${card.label} ${integer(card.value)}，点击查看对应区域`"
          @click="jumpToGrowthSection(card)"
        >
          <span>{{ card.label }}</span>
          <strong>{{ integer(card.value) }}</strong>
          <small>{{ card.note }}</small>
        </button>
      </div>

      <article class="mvp-card growth-scope">
        <div>
          <p class="mvp-kicker">QUALITY / SCOPE GATE</p>
          <h3>English Production Growth</h3>
          <p>只接受英文根域的 Query × Page 证据；旧站多语言子域不参与英文 Growth 排名。</p>
        </div>
        <dl>
          <div><dt>Scoped GSC</dt><dd>{{ integer(growth.data_health.scoped_gsc_rows) }} rows</dd><small>{{ integer(growth.data_health.scoped_gsc_keywords) }} keywords · {{ integer(growth.data_health.scoped_impressions) }} impressions</small></div>
          <div><dt>最新 GSC</dt><dd>{{ growth.data_health.latest_metric_date || '—' }}</dd><small>{{ growth.data_health.gsc_live_connected ? 'Live Query × Page 已接入' : '尚未接入' }}</small></div>
          <div><dt>已发布去重</dt><dd>{{ integer(growth.summary.published_pages_filtered) }}</dd><small>Monitoring 中，不重复派任务</small></div>
          <div><dt>低证据过滤</dt><dd>{{ integer(growth.summary.low_evidence_filtered) }}</dd><small>最低 20 impressions + scoped evidence</small></div>
        </dl>
        <div class="scope-hosts">
          <span v-for="host in growth.scope.hosts" :key="host">{{ host }}</span>
        </div>
      </article>

      <article id="growth-queue" class="mvp-card growth-queue jump-target">
        <div class="section-heading">
          <div><p class="mvp-kicker">GROWTH QUEUE</p><h3>下一批该做什么</h3></div>
          <span>{{ queuePriority === 'P0' ? '仅 P0 · ' : '' }}{{ integer(filteredGrowthQueue.length) }} 个当前结果</span>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>优先级</th><th>机会</th><th>决策</th><th>Scoped GSC</th><th>承接页</th></tr></thead>
            <tbody>
              <tr v-for="row in filteredGrowthQueue" :key="row.key">
                <td><span class="priority-badge" :data-priority="row.priority">{{ row.priority }}</span></td>
                <td><strong>{{ row.keyword }}</strong><small>{{ row.topic_cluster || '—' }} · {{ row.kind === 'new_content' ? 'New Content' : `${row.keyword_count} keyword cluster` }}</small></td>
                <td><strong>{{ decisionLabels[row.decision] || row.decision }}</strong><small>原始信号：{{ actionLabels[row.suggested_action] || row.suggested_action }}</small></td>
                <td><strong>{{ integer(row.impressions) }} 展示 · {{ integer(row.clicks) }} 点击</strong><small>CTR {{ percent(row.ctr) }} · 平均位置 {{ position(row.average_position) }}</small></td>
                <td class="url-cell"><span>{{ shortUrl(row.target_url) }}</span><small>{{ row.target_url ? targetLabel(row.suggested_target_type) : '进入内容/页面建设队列' }}</small></td>
              </tr>
              <tr v-if="!filteredGrowthQueue.length"><td colspan="5" class="empty-cell">当前筛选条件没有 Growth Opportunity。</td></tr>
            </tbody>
          </table>
        </div>
      </article>

      <article id="monitoring-queue" class="mvp-card monitoring-card jump-target">
        <div class="section-heading">
          <div><p class="mvp-kicker">POST-PUBLISH MONITORING</p><h3>28 天复测队列</h3></div>
          <span>{{ monitoringState === 'monitoring' ? '监测中 · ' : monitoringState === 'retest_due' ? '到期复测 · ' : '' }}{{ integer(filteredMonitoring.length) }} 个当前结果</span>
        </div>
        <div class="monitoring-summary">
          <span>监测中 <strong>{{ integer(growth.summary.monitoring) }}</strong></span>
          <span>到期 <strong>{{ integer(growth.summary.retest_due) }}</strong></span>
          <span>Winner <strong>{{ integer(growth.summary.winner) }}</strong></span>
          <span>Loser <strong>{{ integer(growth.summary.loser) }}</strong></span>
          <span>Mixed <strong>{{ integer(growth.summary.mixed) }}</strong></span>
        </div>
        <div class="table-wrap">
          <table>
            <thead><tr><th>WO</th><th>页面 / Keyword</th><th>状态</th><th>复测</th><th>Baseline</th><th>T28</th></tr></thead>
            <tbody>
              <tr v-for="row in filteredMonitoring" :key="row.publication_id">
                <td><strong>#{{ row.work_order_id }}</strong><small>Post {{ row.production_post_id || '—' }}</small></td>
                <td class="url-cell"><strong>{{ row.keyword }}</strong><span>{{ shortUrl(row.published_url) }}</span></td>
                <td><span class="state-badge" :data-state="row.state">{{ stateLabels[row.state] || row.state }}</span><small>Owner UAT {{ row.owner_uat || '—' }}</small></td>
                <td><strong>{{ row.retest_after }}</strong><small>{{ row.days_remaining > 0 ? `${row.days_remaining} 天后` : (row.days_remaining === 0 ? '今天' : `已过 ${Math.abs(row.days_remaining)} 天`) }}</small></td>
                <td><strong>{{ integer(row.baseline.impressions) }} 展示</strong><small>{{ integer(row.baseline.clicks) }} 点击 · Pos {{ position(row.baseline.average_position) }}</small></td>
                <td><template v-if="row.t28"><strong>{{ integer(row.t28.impressions) }} 展示</strong><small>{{ integer(row.t28.clicks) }} 点击 · Pos {{ position(row.t28.average_position) }}</small></template><span v-else>等待 T28</span></td>
              </tr>
              <tr v-if="!filteredMonitoring.length"><td colspan="6" class="empty-cell">当前筛选状态没有任务。</td></tr>
            </tbody>
          </table>
        </div>
      </article>

      <article id="isolated-queues" class="mvp-card isolated-queues jump-target">
        <div class="section-heading">
          <div><p class="mvp-kicker">SEPARATED WORKSTREAMS</p><h3>隔离队列</h3></div>
          <span>避免迁移任务污染 English Growth 排名</span>
        </div>
        <div class="isolated-grid">
          <section>
            <span>Legacy Migration Content</span>
            <strong>{{ integer(growth.summary.migration_content_planned) }}</strong>
            <p>旧站内容迁移继续走 Landing Page Migration / SEO Backlog，不作为新的 Growth Opportunity 重复创建。</p>
            <Link class="mvp-button" :href="`/projects/${project.key}/traffic/landing-pages`">进入迁移页面</Link>
          </section>
          <section>
            <span>International Migration</span>
            <strong>{{ integer(growth.summary.international_planned) }}</strong>
            <p>fr / es / ar / id 等语言子域独立排队，不参与 English Growth Queue。</p>
            <small v-if="growth.international.top.length">当前最高优先：WO #{{ growth.international.top[0].work_order_id }} · {{ shortUrl(growth.international.top[0].target_url) }}</small>
          </section>
        </div>
        <div v-if="growth.international.top.length" class="table-wrap compact-table">
          <table><thead><tr><th>WO</th><th>国际旧站页面</th><th>Impressions</th><th>Avg Pos.</th><th>Priority</th></tr></thead>
          <tbody><tr v-for="row in growth.international.top" :key="row.work_order_id">
            <td>#{{ row.work_order_id }}</td><td class="url-cell"><span>{{ shortUrl(row.target_url) }}</span></td>
            <td>{{ integer(row.impressions) }}</td><td>{{ position(row.average_position) }}</td><td>{{ score(row.priority_score) }}</td>
          </tr></tbody></table>
        </div>
      </article>

      <div class="kpi-grid">
        <article class="kpi"><span>关键词库</span><strong>{{ integer(summary.keywords) }}</strong><small>Raw Canonical Keywords</small></article>
        <article class="kpi"><span>多源证据</span><strong>{{ integer(summary.evidence_rows) }}</strong><small>Ads / GSC / Marketplace</small></article>
        <article class="kpi"><span>原始 P0 信号</span><strong>{{ integer(summary.priority_opportunities) }}</strong><small>需经过 Growth Quality Gate</small></article>
        <article class="kpi"><span>GSC Live</span><strong>{{ summary.gsc_live_rows ? integer(summary.gsc_live_rows) : '—' }}</strong><small>{{ data_health.gsc_live_connected ? 'Query × Page 已接入' : '未接入' }}</small></article>
      </div>

      <div class="opportunity-grid">
        <button v-for="card in priorityCards" :key="card.key" type="button" class="opportunity-card" @click="filterAction(card.key)">
          <span>{{ card.label }}</span><strong>{{ integer(action_counts[card.key] || 0) }}</strong><small>{{ card.note }}</small>
        </button>
      </div>

      <article class="mvp-card source-strip">
        <div><strong>Evidence Coverage</strong><small>Keyword Library 是证据层；Growth Queue 才是执行层。</small></div>
        <dl><div v-for="(count, source) in evidence_counts" :key="source"><dt>{{ sourceLabels[source] || source }}</dt><dd>{{ integer(count) }}</dd></div></dl>
      </article>

      <form class="mvp-card filters" @submit.prevent="apply">
        <label>搜索关键词 / Topic Cluster<input v-model="filters.search" type="search" placeholder="drip irrigation..."></label>
        <label>SEO 动作<select v-model="filters.action"><option value="">全部动作</option><option v-for="action in actions" :key="action" :value="action">{{ actionLabels[action] || action }} ({{ action_counts[action] || 0 }})</option></select></label>
        <label>证据来源<select v-model="filters.source"><option value="">全部来源</option><option v-for="source in sources" :key="source" :value="source">{{ sourceLabels[source] || source }}</option></select></label>
        <div class="filter-actions"><button class="mvp-button mvp-button-primary">筛选</button><button type="button" class="mvp-button" @click="clear">清除</button></div>
      </form>

      <article class="mvp-card keyword-library">
        <div class="section-heading"><div><p class="mvp-kicker">EVIDENCE LIBRARY</p><h3>关键词证据库</h3></div><span>{{ integer(keywords.total) }} 个结果 · 第 {{ keywords.current_page }} / {{ keywords.last_page }} 页</span></div>
        <div class="table-wrap"><table><thead><tr>
          <th>关键词 / Topic</th><th>原始动作</th><th>目标</th><th>Score</th><th>Ads</th><th>GSC Seed</th><th>Alibaba</th>
        </tr></thead><tbody>
          <tr v-for="row in keywords.data" :key="row.id">
            <td><strong>{{ row.keyword }}</strong><small>{{ row.topic_cluster || '—' }}</small></td>
            <td><span class="action-badge" :data-priority="row.suggested_action?.includes('P0') ? 'p0' : 'normal'">{{ actionLabels[row.suggested_action || ''] || row.suggested_action || '未分类' }}</span></td>
            <td>{{ targetLabel(row.suggested_target_type) }}</td><td>{{ score(row.opportunity_score) }}</td>
            <td><template v-if="row.ads"><strong>{{ integer(row.ads.clicks) }} 点击</strong><small>{{ row.ads.conversions.toFixed(2) }} 转化 · ¥{{ row.ads.cost.toFixed(2) }}</small></template><span v-else>—</span></td>
            <td><template v-if="row.gsc"><strong>{{ integer(row.gsc.impressions) }} 展示</strong><small>{{ integer(row.gsc.clicks) }} 点击 · 平均位置 {{ position(row.gsc.average_position) }}</small></template><span v-else>—</span></td>
            <td><template v-if="row.alibaba"><strong>{{ integer(row.alibaba.impressions) }} 展示</strong><small>{{ integer(row.alibaba.clicks) }} 点击</small></template><span v-else>—</span></td>
          </tr>
          <tr v-if="!keywords.data.length"><td colspan="7" class="empty-cell">当前筛选条件没有关键词。</td></tr>
        </tbody></table></div>
        <div class="pagination">
          <Link v-if="keywords.prev_page_url" class="mvp-button" :href="keywords.prev_page_url" preserve-state>上一页</Link><span v-else></span>
          <span>{{ integer(keywords.total) }} 个关键词</span>
          <Link v-if="keywords.next_page_url" class="mvp-button" :href="keywords.next_page_url" preserve-state>下一页</Link><span v-else></span>
        </div>
      </article>

      <article class="mvp-card health-note">
        <strong>SEO-7 数据边界</strong>
        <p>GSC Live Query × Page 已接入；Growth Queue 只使用 English exact-host scoped evidence，并过滤已发布页面、低证据机会、Legacy Migration Content 和多语言子域任务。Keyword Seed 继续作为证据来源，但不会直接触发自动发布。</p>
      </article>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.seo-workspace{display:grid;gap:14px;min-width:0;max-width:100%;overflow-x:hidden}.seo-workspace>*{min-width:0;max-width:100%;box-sizing:border-box}.workspace-heading,.section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;min-width:0}.workspace-heading>*,.section-heading>*{min-width:0}.workspace-heading h2,.section-heading h3,.growth-scope h3{margin:2px 0 6px}.workspace-heading p,.growth-scope p{margin:0;color:#64748b}.growth-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;min-width:0;width:100%}.growth-kpi{display:grid;gap:6px;min-width:0;width:100%;box-sizing:border-box;padding:15px;border:1px solid #dbe4ec;border-radius:12px;background:#fff;text-align:left;font:inherit;color:inherit;cursor:pointer;transition:border-color .16s ease,box-shadow .16s ease,transform .16s ease}.growth-kpi:hover,.growth-kpi:focus-visible{border-color:#0f766e;box-shadow:0 8px 20px rgba(15,118,110,.10);transform:translateY(-1px);outline:none}.growth-kpi[data-tone="strong"]{border-top:3px solid #0f766e}.growth-kpi[data-tone="warn"]{border-top:3px solid #b45309}.growth-kpi span,.growth-kpi small{color:#64748b;font-size:11px}.growth-kpi strong{font-size:27px;font-variant-numeric:tabular-nums}.growth-scope{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:18px;align-items:start;min-width:0}.growth-scope dl{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0;min-width:0}.growth-scope dl>div{display:grid;gap:3px;padding:10px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc}.growth-scope dt,.growth-scope small{color:#64748b;font-size:11px}.growth-scope dd{margin:0;font-weight:800}.scope-hosts{grid-column:1/-1;display:flex;gap:7px;flex-wrap:wrap}.scope-hosts span{padding:5px 8px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#475569;font-size:11px}.jump-target{scroll-margin-top:88px}.isolated-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}.isolated-grid section{display:grid;gap:7px;padding:15px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc}.isolated-grid section>span,.isolated-grid p,.isolated-grid small{color:#64748b}.isolated-grid section>strong{font-size:28px}.isolated-grid p{margin:0;line-height:1.55}.isolated-grid .mvp-button{justify-self:start}.compact-table{margin-top:12px}.monitoring-summary{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}.monitoring-summary span{padding:7px 10px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px}.monitoring-summary strong{margin-left:4px}.priority-badge,.state-badge{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;background:#f1f5f9;font-weight:800;font-size:11px}.priority-badge[data-priority="P0"]{background:#ecfdf5;color:#047857}.priority-badge[data-priority="P1"]{background:#eff6ff;color:#1d4ed8}.state-badge[data-state="retest_due"],.state-badge[data-state="loser"]{background:#fff7ed;color:#c2410c}.state-badge[data-state="winner"]{background:#ecfdf5;color:#047857}.url-cell{max-width:300px;overflow:hidden}.url-cell span{display:block;max-width:300px;overflow:hidden;text-overflow:ellipsis}.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.kpi{display:grid;gap:7px;padding:16px;border:1px solid #dbe4ec;border-radius:12px;background:#fff}.kpi span,.kpi small{color:#64748b;font-size:12px}.kpi strong{font-size:28px;font-variant-numeric:tabular-nums}.opportunity-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.opportunity-card{display:grid;gap:7px;text-align:left;padding:16px;border:1px solid #dbe4ec;border-radius:12px;background:#fff;cursor:pointer}.opportunity-card:hover{border-color:#0f766e}.opportunity-card span,.opportunity-card small{color:#64748b}.opportunity-card strong{font-size:30px}.source-strip{display:flex;justify-content:space-between;align-items:center;gap:20px;min-width:0}.source-strip>div{display:grid;gap:4px;min-width:0}.source-strip small{color:#64748b}.source-strip dl{display:flex;gap:20px;margin:0;flex-wrap:wrap;min-width:0}.source-strip dl>div{display:grid;gap:2px}.source-strip dt{color:#64748b;font-size:11px}.source-strip dd{margin:0;font-weight:700}.filters{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr) minmax(0,1fr) auto;align-items:end;gap:10px;min-width:0}.filters label{display:grid;gap:5px;min-width:0;color:#64748b;font-size:12px}.filters input,.filters select{min-width:0;width:100%;box-sizing:border-box;min-height:38px;border:1px solid #cbd5e1;border-radius:8px;padding:7px 10px;background:#fff}.filter-actions{display:flex;gap:8px}.table-wrap{display:block;min-width:0;max-width:100%;width:100%;overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch}table{width:100%;min-width:max-content;border-collapse:collapse;font-size:12px}th,td{padding:10px 8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top;white-space:nowrap}th{color:#64748b}td{color:#334155}td strong,td small{display:block}td small{margin-top:3px;color:#94a3b8}.action-badge{display:inline-block;padding:4px 7px;border-radius:999px;background:#f1f5f9}.action-badge[data-priority="p0"]{background:#ecfdf5;color:#047857;font-weight:700}.pagination{display:flex;justify-content:space-between;align-items:center;margin-top:12px;color:#64748b;font-size:12px}.health-note p{margin:6px 0 0;color:#64748b}.empty-cell{text-align:center;color:#94a3b8}@media(max-width:1280px){.growth-kpi-grid{grid-template-columns:repeat(3,1fr)}.growth-scope{grid-template-columns:1fr}.growth-scope dl{grid-template-columns:repeat(2,1fr)}}@media(max-width:1100px){.kpi-grid{grid-template-columns:repeat(2,1fr)}.filters{grid-template-columns:1fr 1fr}}@media(max-width:720px){.workspace-heading,.source-strip{flex-direction:column}.growth-kpi-grid,.kpi-grid,.opportunity-grid,.filters,.growth-scope dl,.isolated-grid{grid-template-columns:1fr}.growth-kpi{grid-template-columns:1fr auto;align-items:center}.growth-kpi small{grid-column:1/-1}.growth-kpi strong{font-size:24px}.scope-hosts{display:none}.table-wrap{margin-inline:-14px;padding-inline:14px}.monitoring-summary{display:grid;grid-template-columns:repeat(2,1fr)}}
</style>
