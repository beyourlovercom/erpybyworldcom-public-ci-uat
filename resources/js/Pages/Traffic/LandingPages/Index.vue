<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import TrafficSectionNav from '../../../Components/Traffic/TrafficSectionNav.vue'

type TargetGa4Row={path:string;sessions:number;engaged_sessions:number;engagement_rate:number;key_events:number}
type GscRow={url:string;domain:string;path:string;site_role:string;impressions:number;clicks:number;ctr:number;average_position:number|null}
type MigrationRow=GscRow&{target_path_present:boolean;target_sessions:number|null;target_engaged_sessions:number|null;target_engagement_rate:number|null;migration_state:string;priority:'P0'|'P1'|'P2';priority_reason:string}
type InquiryRow={domain:string;path:string;inquiries:number;resolved:number;partial:number;unresolved:number}
type ContentPlanRow={cluster_key:string;title:string;recommended_target_type:string;seo_topic_cluster:string;execution_action:string;legacy_versions:number;legacy_domains:string[];legacy_impressions:number;legacy_clicks:number;average_position:number|null;sample_path:string}
type HandoffRow=ContentPlanRow&{handoff_state:'NEW'|'REVIEW'|'REUSE';review_resolution:string|null;review_resolution_reason:string|null;match_similarity:number;matched_backlog_id:number|null;matched_backlog_title:string|null;matched_backlog_status:string|null;matched_backlog_content_type:string|null;matched_backlog_target_url:string|null;match_origin:'migration_applied'|'preexisting_backlog'|null;proposed_backlog:{seo_keyword_id:null;topic_cluster:string;content_type:string;priority:string;status:string;title:string;target_url:null;rationale:string}}
type MigrationMappingRow={mapping_state:'TARGET_PATH_PRESENT'|'PENDING_CREATE';target_url:string|null;target_path:string|null;backlog_id:number|null;cluster_key:string|null;title:string|null;legacy_urls:string[];legacy_versions:number;legacy_impressions:number;legacy_clicks:number;target_sessions:number|null;note:string}

const props=defineProps<{
  project:{key:string;name:string}
  filters:{date_from:string;date_to:string}
  site_strategy:{target_domain:string;target_base_url:string;legacy_domain:string;legacy_base_url:string;strategy:string}
  summary:{
    target_ga4_pages:number;target_gsc_pages:number;target_gsc_impressions:number;target_gsc_clicks:number;
    legacy_reference_pages:number;legacy_gsc_impressions:number;legacy_gsc_clicks:number;
    migration_candidates:number;target_path_present:number;priority_p0:number;priority_p1:number;priority_p2:number;p1_content_clusters:number;p1_blog_clusters:number;p1_product_clusters:number;p1_category_clusters:number;handoff_reuse:number;handoff_applied_migration_rows:number;handoff_preexisting_reuse:number;handoff_review:number;handoff_new:number;mapping_total:number;mapping_target_present:number;mapping_pending_create:number;target_inquiry_pages:number;excluded_non_target_inquiry_evidence:number
  }
  target_ga4_pages:TargetGa4Row[]
  target_gsc_pages:GscRow[]
  legacy_reference_pages:GscRow[]
  migration_opportunities:MigrationRow[]
  migration_content_plan:ContentPlanRow[]
  migration_backlog_handoff:HandoffRow[]
  migration_mapping:MigrationMappingRow[]
  target_inquiry_pages:InquiryRow[]
}>()

const filters=reactive({...props.filters})
const priorityFilter=ref<'ALL'|'P0'|'P1'|'P2'>('ALL')
const format=(v:number|null|undefined)=>v===null||v===undefined?'—':v.toLocaleString('zh-CN')
const filteredMigrationOpportunities=computed(()=>priorityFilter.value==='ALL'
  ? props.migration_opportunities
  : props.migration_opportunities.filter(row=>row.priority===priorityFilter.value))
const apply=()=>router.get('/projects/'+props.project.key+'/traffic/landing-pages',filters,{preserveState:true,replace:true})
const reset=()=>router.get('/projects/'+props.project.key+'/traffic/landing-pages')
</script>

<template>
  <Head :title="project.name+' · Landing Migration · YBY ERP'" />
  <AuthenticatedLayout title="Landing Page Migration" :breadcrumbs="['YBY ERP',project.name,'流量 OS','Landing Page Migration']" canonical-topbar>
    <section class="lp-page">
      <header class="lp-hero">
        <div>
          <p class="lp-kicker">LEGACY → TARGET MIGRATION</p>
          <h1>旧站流量迁移到新站</h1>
          <p><strong>{{ site_strategy.legacy_domain }}</strong> 是参考站；<strong>{{ site_strategy.target_domain }}</strong> 是未来主站。优先把旧站已有 Google 流量的页面迁移并优化到新站。</p>
        </div>
        <div class="lp-actions">
          <Link class="lp-button" :href="'/projects/'+project.key+'/traffic'">← 返回流量总览</Link>
          <form class="lp-filters" @submit.prevent="apply">
            <label>开始日期<input v-model="filters.date_from" type="date"></label>
            <label>结束日期<input v-model="filters.date_to" type="date"></label>
            <button class="lp-button primary">应用</button>
            <button type="button" class="lp-button" @click="reset">最近 30 天</button>
          </form>
        </div>
      </header>

      <TrafficSectionNav :project-key="project.key" />

      <div class="strategy-banner">
        <div><span>参考站 / Legacy Reference</span><strong>{{ site_strategy.legacy_domain }}</strong><small>保留并分析现有搜索流量，不作为未来主推站</small></div>
        <div class="arrow">→</div>
        <div><span>目标站 / Target Site</span><strong>{{ site_strategy.target_domain }}</strong><small>未来一年持续承接、重做和优化旧站高价值页面</small></div>
      </div>

      <div class="lp-kpis">
        <article><span>旧站 GSC 页面</span><strong>{{ summary.legacy_reference_pages }}</strong><small>含多语言子域</small></article>
        <article><span>旧站曝光</span><strong>{{ format(summary.legacy_gsc_impressions) }}</strong><small>参考流量资产</small></article>
        <article><span>旧站点击</span><strong>{{ format(summary.legacy_gsc_clicks) }}</strong><small>迁移优先级依据</small></article>
        <article><span>P0 高优先</span><strong>{{ summary.priority_p0 }}</strong><small>旧站有点击 + 新站无同 path</small></article>
        <article><span>P1 待迁移</span><strong>{{ summary.priority_p1 }}</strong><small>旧站有曝光 + 新站无同 path</small></article>
        <article><span>P2 待优化</span><strong>{{ summary.priority_p2 }}</strong><small>新站已有同 path，继续优化核验</small></article>
      </div>

      <article class="lp-card emphasis">
        <div class="lp-card-head">
          <div><h2>迁移机会清单</h2><p>P0 = 旧站已有点击但新站无对应 path；P1 = 有曝光但暂无点击且新站无对应 path；P2 = 新站已有对应 path，继续做内容/SEO 核验与优化。</p></div>
          <span class="lp-tag">Traffic Discovery → SEO Execution</span>
        </div>
        <div class="priority-filters">
          <button :class="{active:priorityFilter==='ALL'}" @click="priorityFilter='ALL'">全部 {{ migration_opportunities.length }}</button>
          <button :class="{active:priorityFilter==='P0'}" @click="priorityFilter='P0'">P0 {{ summary.priority_p0 }}</button>
          <button :class="{active:priorityFilter==='P1'}" @click="priorityFilter='P1'">P1 {{ summary.priority_p1 }}</button>
          <button :class="{active:priorityFilter==='P2'}" @click="priorityFilter='P2'">P2 {{ summary.priority_p2 }}</button>
          <Link class="seo-handoff" :href="'/projects/'+project.key+'/traffic/seo'">进入 SEO OS →</Link>
        </div>
        <div class="lp-table-wrap"><table>
          <thead><tr><th>#</th><th>优先级</th><th>旧站页面</th><th>旧站 Clicks</th><th>旧站 Impressions</th><th>CTR</th><th>Avg Pos.</th><th>新站承接</th><th>新站 Sessions</th></tr></thead>
          <tbody>
            <tr v-for="(row,index) in filteredMigrationOpportunities" :key="row.url">
              <td>{{ index+1 }}</td>
              <td><span :class="['priority-badge',row.priority.toLowerCase()]">{{ row.priority }}</span></td>
              <td class="left"><strong>{{ row.domain }}</strong><small>{{ row.path }}</small></td>
              <td>{{ format(row.clicks) }}</td><td>{{ format(row.impressions) }}</td><td>{{ row.ctr.toFixed(1) }}%</td><td>{{ row.average_position ?? '—' }}</td>
              <td><span :class="['state',row.target_path_present?'present':'candidate']">{{ row.target_path_present ? '已有相同 path / 待优化' : '待迁移 / 待创建' }}</span></td>
              <td>{{ format(row.target_sessions) }}</td>
            </tr>
          </tbody>
        </table></div>
      </article>

      <article class="lp-card mapping-card">
        <div class="lp-card-head">
          <div>
            <h2>新旧站承接映射 / Migration Mapping</h2>
            <p>明确列出“新站哪个页面承接旧站哪些页面”。待创建内容不会伪造新站 URL；已存在 path 只表示已承接候选，仍需 SEO / 内容核验。</p>
          </div>
          <span class="lp-tag">TARGET ↔ LEGACY</span>
        </div>
        <div class="mapping-summary">
          <span>总映射 <strong>{{ summary.mapping_total }}</strong></span>
          <span class="present">已有新站 Path <strong>{{ summary.mapping_target_present }}</strong></span>
          <span class="pending">待创建 <strong>{{ summary.mapping_pending_create }}</strong></span>
        </div>
        <div class="lp-table-wrap"><table>
          <thead><tr><th>#</th><th>状态</th><th>新站页面</th><th>Backlog</th><th>承接旧站页面</th><th>旧站版本</th><th>Impressions</th><th>新站 Sessions</th></tr></thead>
          <tbody>
            <tr v-for="(row,index) in migration_mapping" :key="row.mapping_state+'|'+(row.target_url||row.cluster_key)">
              <td>{{ index+1 }}</td>
              <td><span :class="['mapping-state',row.mapping_state==='PENDING_CREATE'?'pending':'present']">{{ row.mapping_state==='PENDING_CREATE' ? '待创建' : '已有 Path / 待核验' }}</span></td>
              <td class="left">
                <a v-if="row.target_url" :href="row.target_url" target="_blank" rel="noopener">{{ row.target_url }}</a>
                <template v-else><strong>未创建</strong><small>{{ row.title }}</small></template>
              </td>
              <td>{{ row.backlog_id ? '#'+row.backlog_id : '—' }}</td>
              <td class="left legacy-list">
                <details>
                  <summary>{{ row.legacy_versions }} 个旧站页面</summary>
                  <a v-for="url in row.legacy_urls" :key="url" :href="url" target="_blank" rel="noopener">{{ url }}</a>
                </details>
              </td>
              <td>{{ row.legacy_versions }}</td>
              <td>{{ format(row.legacy_impressions) }}</td>
              <td>{{ format(row.target_sessions) }}</td>
            </tr>
          </tbody>
        </table></div>
      </article>

      <article class="lp-card content-plan">
        <div class="lp-card-head">
          <div>
            <h2>P1 Content Plan</h2>
            <p>302 条缺失旧站 URL 已按主题去重。后续 SEO Backlog 的写入单位是 Cluster，不是旧 URL，避免多语言镜像重复建设。</p>
          </div>
          <span class="lp-tag">302 URLs → {{ summary.p1_content_clusters }} Clusters</span>
        </div>
        <div class="content-plan-summary">
          <span>Blog <strong>{{ summary.p1_blog_clusters }}</strong></span>
          <span>Product <strong>{{ summary.p1_product_clusters }}</strong></span>
          <span>Category <strong>{{ summary.p1_category_clusters }}</strong></span>
          <span>执行状态 <strong>Dry-run / 未写 Backlog</strong></span>
        </div>
        <div class="lp-table-wrap"><table>
          <thead><tr><th>#</th><th>主题 Cluster</th><th>页面类型</th><th>SEO Cluster</th><th>旧站版本</th><th>Impressions</th><th>Avg Pos.</th><th>执行动作</th></tr></thead>
          <tbody>
            <tr v-for="(row,index) in migration_content_plan" :key="row.cluster_key">
              <td>{{ index+1 }}</td>
              <td class="left"><strong>{{ row.title }}</strong><small>{{ row.sample_path }}</small></td>
              <td>{{ row.recommended_target_type }}</td>
              <td>{{ row.seo_topic_cluster }}</td>
              <td>{{ row.legacy_versions }}</td>
              <td>{{ format(row.legacy_impressions) }}</td>
              <td>{{ row.average_position ?? '—' }}</td>
              <td><span class="action-badge">{{ row.execution_action }}</span></td>
            </tr>
          </tbody>
        </table></div>
      </article>

      <article class="lp-card handoff-preview">
        <div class="lp-card-head">
          <div>
            <h2>SEO Backlog Handoff Preview</h2>
            <p>先与现有 210 条 SEO Backlog 做幂等匹配。当前只是预览，不执行数据库写入。</p>
          </div>
          <span class="lp-tag">PREVIEW ONLY</span>
        </div>
        <div class="handoff-summary">
          <span class="new">NEW <strong>{{ summary.handoff_new }}</strong></span>
          <span class="review">REVIEW <strong>{{ summary.handoff_review }}</strong></span>
          <span class="reuse">TOTAL COVERED <strong>{{ summary.handoff_reuse }}</strong></span>
          <span class="applied">APPLIED MIGRATION <strong>{{ summary.handoff_applied_migration_rows }}</strong></span>
          <span>EXISTING REUSE <strong>{{ summary.handoff_preexisting_reuse }}</strong></span>
        </div>
        <div class="lp-table-wrap"><table>
          <thead><tr><th>#</th><th>状态</th><th>Cluster</th><th>Impressions</th><th>SEO Cluster</th><th>现有 Backlog</th><th>Similarity</th><th>拟执行</th></tr></thead>
          <tbody>
            <tr v-for="(row,index) in migration_backlog_handoff" :key="row.cluster_key">
              <td>{{ index+1 }}</td>
              <td><span :class="['handoff-state',row.handoff_state.toLowerCase()]">{{ row.handoff_state }}</span></td>
              <td class="left"><strong>{{ row.title }}</strong><small>{{ row.cluster_key }}</small></td>
              <td>{{ format(row.legacy_impressions) }}</td>
              <td>{{ row.seo_topic_cluster }}</td>
              <td class="left">
                <template v-if="row.matched_backlog_id">
                  <strong>#{{ row.matched_backlog_id }} {{ row.matched_backlog_title }}</strong>
                  <small>{{ row.matched_backlog_status }} / {{ row.matched_backlog_content_type }} · {{ row.match_origin==='migration_applied' ? '本次迁移已写入' : '既有 Backlog' }}</small>
                </template>
                <span v-else>—</span>
              </td>
              <td>{{ row.match_similarity.toFixed(2) }}</td>
              <td>
                <strong>{{ row.handoff_state==='NEW' ? '新增 Blog Backlog(P1)' : row.handoff_state==='REUSE' ? '复用现有 Backlog' : 'Owner Review' }}</strong>
                <small v-if="row.review_resolution">{{ row.review_resolution }} · {{ row.review_resolution_reason }}</small>
              </td>
            </tr>
          </tbody>
        </table></div>
      </article>

      <div class="lp-grid two">
        <article class="lp-card">
          <div class="lp-card-head"><div><h2>新站当前承接表现</h2><p>{{ site_strategy.target_domain }} 的 GA4 Landing Pages。</p></div><span class="lp-tag target">Target GA4</span></div>
          <div class="lp-table-wrap"><table>
            <thead><tr><th>#</th><th>Path</th><th>Sessions</th><th>Engaged</th><th>参与率</th></tr></thead>
            <tbody><tr v-for="(row,index) in target_ga4_pages" :key="row.path"><td>{{ index+1 }}</td><td class="left"><strong>{{ row.path }}</strong></td><td>{{ format(row.sessions) }}</td><td>{{ format(row.engaged_sessions) }}</td><td>{{ row.engagement_rate.toFixed(1) }}%</td></tr></tbody>
          </table></div>
        </article>

        <article class="lp-card">
          <div class="lp-card-head"><div><h2>旧站搜索资产</h2><p>{{ site_strategy.legacy_domain }} 及其多语言子域的真实 GSC 页面。</p></div><span class="lp-tag legacy">Legacy GSC</span></div>
          <div class="lp-table-wrap"><table>
            <thead><tr><th>#</th><th>URL</th><th>Impressions</th><th>Clicks</th><th>CTR</th></tr></thead>
            <tbody><tr v-for="(row,index) in legacy_reference_pages" :key="row.url"><td>{{ index+1 }}</td><td class="left"><strong>{{ row.domain }}</strong><small>{{ row.path }}</small></td><td>{{ format(row.impressions) }}</td><td>{{ format(row.clicks) }}</td><td>{{ row.ctr.toFixed(1) }}%</td></tr></tbody>
          </table></div>
        </article>
      </div>

      <article class="lp-card">
        <div class="lp-card-head"><div><h2>新站搜索可见度</h2><p>{{ site_strategy.target_domain }} 自己的 GSC 数据，不再与旧站流量混合。</p></div><span class="lp-tag target">Target GSC</span></div>
        <div class="lp-table-wrap"><table>
          <thead><tr><th>#</th><th>页面</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Avg Pos.</th></tr></thead>
          <tbody>
            <tr v-for="(row,index) in target_gsc_pages" :key="row.url"><td>{{ index+1 }}</td><td class="left"><strong>{{ row.domain }}</strong><small>{{ row.path }}</small></td><td>{{ format(row.impressions) }}</td><td>{{ format(row.clicks) }}</td><td>{{ row.ctr.toFixed(1) }}%</td><td>{{ row.average_position ?? '—' }}</td></tr>
            <tr v-if="!target_gsc_pages.length"><td colspan="6" class="empty">新站当前还没有 GSC 日粒度可见度数据。旧站流量将作为后续迁移/内容建设的参考，不会计入新站表现。</td></tr>
          </tbody>
        </table></div>
      </article>

      <p class="lp-note">当前迁移匹配仅以 path 是否已出现在新站 GA4 为证据，不等于 SEO canonical 已迁移完成。Google Ads 仍没有 landing-page dimension；Canonical landing_pages 也尚未回填。</p>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.lp-page{display:grid;gap:16px;padding:16px 0 30px;max-width:1600px}.lp-hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.lp-kicker{margin:0 0 4px;color:#0f766e;font-size:11px;font-weight:800;letter-spacing:.12em}.lp-hero h1{margin:0;color:#0d1b3e;font-size:34px}.lp-hero p:last-child{margin:8px 0 0;color:#728096}.lp-actions{display:grid;gap:8px;justify-items:end}.lp-filters{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.lp-filters label{display:grid;gap:4px;color:#718096;font-size:11px}.lp-filters input{height:38px;border:1px solid #d5dee9;border-radius:9px;padding:0 10px}.lp-button{height:38px;padding:0 14px;border:1px solid #d5dee9;border-radius:9px;background:#fff;color:#34465f;font-weight:650;text-decoration:none;display:inline-flex;align-items:center}.lp-button.primary{border-color:#0f766e;background:#0f766e;color:#fff}
.strategy-banner{display:grid;grid-template-columns:1fr auto 1fr;gap:18px;align-items:center;padding:18px;border:1px solid #cfe5df;border-radius:14px;background:#f4fbf8}.strategy-banner>div:not(.arrow){display:grid;gap:3px}.strategy-banner span{font-size:11px;font-weight:750;color:#64748b}.strategy-banner strong{font-size:20px;color:#0f3d35}.strategy-banner small{color:#718096}.strategy-banner .arrow{font-size:28px;color:#0f766e}
.lp-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.lp-kpis article,.lp-card{padding:18px;border:1px solid #dfe7f0;border-radius:14px;background:#fff;box-shadow:0 6px 22px rgb(38 63 90/.04)}.lp-card.emphasis{border-color:#b9ddd3}.lp-kpis span,.lp-kpis small{display:block}.lp-kpis span{color:#405169;font-size:12px;font-weight:700}.lp-kpis strong{display:block;margin:8px 0 5px;color:#0c1837;font-size:28px}.lp-kpis small{color:#94a1b1;font-size:10px}.lp-grid.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.lp-card-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:12px}.lp-card-head h2{margin:0;color:#122143;font-size:18px}.lp-card-head p{margin:4px 0 0;color:#8795a7;font-size:11px}.lp-tag{padding:5px 8px;border-radius:999px;background:#ecfdf5;color:#047857;font-size:10px;font-weight:700;white-space:nowrap}.lp-tag.legacy{background:#fff7ed;color:#c2410c}.lp-tag.target{background:#eff6ff;color:#1d4ed8}.lp-table-wrap{overflow:auto}.lp-table-wrap table{width:100%;border-collapse:collapse;font-size:12px}.lp-table-wrap th,.lp-table-wrap td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:right;white-space:nowrap}.lp-table-wrap th:first-child,.lp-table-wrap td:first-child,.lp-table-wrap .left{text-align:left}.lp-table-wrap th{color:#748297}.lp-table-wrap td{color:#35465f}.lp-table-wrap td small{display:block;margin-top:2px;color:#95a2b1}.empty{text-align:center!important;color:#98a5b5;padding:24px}.state{display:inline-block;padding:4px 7px;border-radius:999px;font-size:10px;font-weight:700}.state.present{background:#ecfdf5;color:#047857}.state.candidate{background:#fff7ed;color:#c2410c}.priority-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px}.priority-filters button,.seo-handoff{border:1px solid #d7e1ea;background:#fff;color:#42526a;border-radius:9px;padding:7px 10px;font-size:11px;font-weight:700;text-decoration:none}.priority-filters button.active{background:#0f766e;color:#fff;border-color:#0f766e}.seo-handoff{margin-left:auto}.priority-badge{display:inline-block;min-width:34px;padding:4px 7px;border-radius:999px;font-size:10px;font-weight:800;text-align:center}.priority-badge.p0{background:#fee2e2;color:#b91c1c}.priority-badge.p1{background:#fff7ed;color:#c2410c}.priority-badge.p2{background:#eff6ff;color:#1d4ed8}.mapping-card{border-color:#b9ddd3}.mapping-summary{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 12px}.mapping-summary span{padding:6px 9px;border-radius:9px;background:#f8fafc;color:#64748b;font-size:11px}.mapping-summary span.present{background:#eff6ff;color:#1d4ed8}.mapping-summary span.pending{background:#fff7ed;color:#c2410c}.mapping-summary strong{color:inherit}.mapping-state{display:inline-block;padding:4px 7px;border-radius:999px;font-size:10px;font-weight:800}.mapping-state.present{background:#eff6ff;color:#1d4ed8}.mapping-state.pending{background:#fff7ed;color:#c2410c}.legacy-list details{max-width:520px}.legacy-list summary{cursor:pointer;font-weight:700;color:#334155}.legacy-list a{display:block;max-width:500px;overflow:hidden;text-overflow:ellipsis;color:#2563eb;text-decoration:none;margin-top:5px}.handoff-preview{border-color:#d8d4fe}.handoff-summary{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 12px}.handoff-summary span{padding:6px 9px;border-radius:9px;background:#f8fafc;color:#64748b;font-size:11px}.handoff-summary span.new{background:#ecfdf5;color:#047857}.handoff-summary span.review{background:#fff7ed;color:#c2410c}.handoff-summary span.reuse{background:#eff6ff;color:#1d4ed8}.handoff-summary span.applied{background:#ecfdf5;color:#047857}.handoff-summary strong{color:inherit}.handoff-state{display:inline-block;padding:4px 7px;border-radius:999px;font-size:10px;font-weight:800}.handoff-state.new{background:#ecfdf5;color:#047857}.handoff-state.review{background:#fff7ed;color:#c2410c}.handoff-state.reuse{background:#eff6ff;color:#1d4ed8}.content-plan{border-color:#c7d9ee}.content-plan-summary{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 12px}.content-plan-summary span{padding:6px 9px;border-radius:9px;background:#f8fafc;color:#64748b;font-size:11px}.content-plan-summary strong{color:#1e293b}.action-badge{display:inline-block;padding:4px 7px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:10px;font-weight:800}.lp-note{margin:0;color:#7b8798;font-size:11px}
@media(max-width:1250px){.lp-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.lp-grid.two{grid-template-columns:1fr}}@media(max-width:700px){.lp-hero{flex-direction:column}.lp-actions{justify-items:start}.lp-kpis{grid-template-columns:1fr 1fr}.strategy-banner{grid-template-columns:1fr}.strategy-banner .arrow{transform:rotate(90deg);justify-self:center}}@media(max-width:480px){.lp-kpis{grid-template-columns:1fr}}

/* Traffic Responsive Containment V1 */
.lp-page{width:100%;min-width:0;max-width:100%;overflow-x:hidden;box-sizing:border-box}
.lp-page>*{min-width:0;max-width:100%;box-sizing:border-box}
.lp-hero,.lp-actions,.lp-filters,.lp-card-head,.strategy-banner,.lp-grid.two{min-width:0;max-width:100%}
.lp-hero>*,.lp-card-head>*,.strategy-banner>*{min-width:0}
.lp-kpis{grid-template-columns:repeat(auto-fit,minmax(160px,1fr));width:100%;min-width:0}
.lp-kpis article,.lp-card{min-width:0;max-width:100%;box-sizing:border-box}
.lp-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
.lp-filters label{min-width:0}
.lp-filters input{min-width:0;max-width:100%;box-sizing:border-box}
.lp-table-wrap{display:block;width:100%;min-width:0;max-width:100%;overflow-x:auto;overscroll-behavior-inline:contain;-webkit-overflow-scrolling:touch}
.lp-table-wrap table{width:100%;min-width:max-content}
.lp-table-wrap .left{max-width:420px;overflow:hidden}
.lp-table-wrap .left strong,.lp-table-wrap .left small{display:block;max-width:420px;overflow:hidden;text-overflow:ellipsis}
.legacy-list details{width:100%;min-width:0;max-width:100%}
.legacy-list a{max-width:100%}
@media(max-width:700px){.lp-grid.two{grid-template-columns:1fr}.lp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:480px){.lp-kpis{grid-template-columns:1fr}}

</style>
