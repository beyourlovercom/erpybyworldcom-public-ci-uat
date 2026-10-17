<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, reactive, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Article = {
  id:number
  project_key:string
  brand_key:string|null
  target_site:string
  language:string
  content_type:string
  title_direction:string
  primary_keyword:string|null
  search_intent:string|null
  topic_cluster:string|null
  recommended_word_count:number|null
  workflow_status:string
  writer_id:number|null
  claimed_at:string|null
  claim_expires_at:string|null
}
type Opportunity = {
  id:number
  project_key:string
  project_name:string
  content_type:string
  priority:string
  title:string
  topic_cluster:string|null
  primary_keyword:string|null
  intent:string|null
  opportunity_score:number|null
  rationale:string|null
}
type Scope = { id:number; project_key:string; language:string; active:boolean }
type Writer = { id:number; name:string; email:string; scopes:Scope[] }
type Project = { project_key:string; name:string }

const props = defineProps<{
  pool:Article[]
  mine:Article[]
  canWrite:boolean
  canManage:boolean
  opportunities:Opportunity[]
  writers:Writer[]
  projects:Project[]
}>()

type Tab = 'opportunities'|'pool'|'mine'|'scopes'
const tab = ref<Tab>(props.canManage ? 'opportunities' : 'pool')
const scopeForm = reactive({
  user_id: props.writers[0]?.id ?? null as number|null,
  project_key: props.projects[0]?.project_key ?? '',
  language: 'en',
})

const tabs = computed(() => {
  const result:{key:Tab;label:string;count?:number}[] = []
  if (props.canManage) result.push({ key:'opportunities', label:'待创建', count:props.opportunities.length })
  result.push({ key:'pool', label:'创作池', count:props.pool.length })
  result.push({ key:'mine', label:'我的文章', count:props.mine.length })
  if (props.canManage) result.push({ key:'scopes', label:'写手范围', count:props.writers.length })
  return result
})

const contentTypeLabel = (value:string) => ({
  blog:'Blog',
  guide:'Guide',
  comparison:'Comparison',
  comparison_blog:'Comparison',
}[value] ?? value)

const statusLabel = (value:string) => ({
  article_base_ready:'Article Base',
  waiting_for_writer:'待领取',
  writer_claimed:'已领取',
  writing:'写作中',
  returned_for_revision:'退回修改',
  doc_submitted:'已提交文档',
  doc_imported:'文档已导入',
  editorial_review:'编辑审核',
  editorial_ready:'编辑完成',
  layout_ready:'排版完成',
  owner_uat:'Owner UAT',
  published:'已发布',
  monitoring:'监测中',
  ai_writing:'AI 创作中',
  draft_ready:'AI 草稿完成',
}[value] ?? value)

const claimNext = () => router.post('/content/claim-next')
const createFromOpportunity = (id:number, mode:'human'|'ai') =>
  router.post(`/content/sources/seo-backlog/${id}`, { authoring_mode: mode })
const addScope = () => {
  if (!scopeForm.user_id || !scopeForm.project_key || !scopeForm.language) return
  router.post('/content/writer-scopes', scopeForm)
}
const removeScope = (id:number) => router.delete(`/content/writer-scopes/${id}`)
</script>

<template>
  <Head title="Content OS · YBY ERP" />
  <AuthenticatedLayout title="Content OS" :breadcrumbs="['YBY ERP', 'Content OS']">
    <section class="content-page">
      <header class="content-hero">
        <div>
          <p class="content-kicker">CONTENT PRODUCTION V1</p>
          <h2>内容生产</h2>
        </div>
        <button v-if="canWrite" class="content-btn content-btn-primary" type="button" :disabled="pool.length === 0" @click="claimNext">
          获取下一篇
        </button>
      </header>

      <nav class="content-tabs" aria-label="内容生产导航">
        <button
          v-for="item in tabs"
          :key="item.key"
          type="button"
          :class="{ active: tab === item.key }"
          @click="tab = item.key"
        >
          {{ item.label }}<span v-if="item.count !== undefined">{{ item.count }}</span>
        </button>
      </nav>

      <div v-if="tab === 'opportunities' && canManage" class="content-stack">
        <article v-for="item in opportunities" :key="item.id" class="content-card">
          <div class="content-card-top">
            <div class="content-badges">
              <span>{{ item.priority }}</span>
              <span>{{ contentTypeLabel(item.content_type) }}</span>
              <span>{{ item.project_name }}</span>
            </div>
            <strong v-if="item.opportunity_score !== null">{{ item.opportunity_score.toFixed(1) }}</strong>
          </div>
          <h3>{{ item.title }}</h3>
          <dl class="content-meta">
            <div><dt>Primary Keyword</dt><dd>{{ item.primary_keyword || '—' }}</dd></div>
            <div><dt>Intent</dt><dd>{{ item.intent || '—' }}</dd></div>
            <div><dt>Topic</dt><dd>{{ item.topic_cluster || '—' }}</dd></div>
          </dl>
          <div class="content-actions">
            <button class="content-btn content-btn-primary" type="button" @click="createFromOpportunity(item.id, 'human')">人工写手</button>
            <button class="content-btn" type="button" @click="createFromOpportunity(item.id, 'ai')">AI 创作</button>
          </div>
        </article>
        <div v-if="!opportunities.length" class="content-empty">当前没有待进入 Content Production 的文章型机会。</div>
      </div>

      <div v-if="tab === 'pool'" class="content-stack">
        <article v-for="article in pool" :key="article.id" class="content-card">
          <div class="content-card-top">
            <div class="content-badges">
              <span>{{ contentTypeLabel(article.content_type) }}</span>
              <span>{{ article.project_key }}</span>
              <span>{{ article.language.toUpperCase() }}</span>
            </div>
            <span>{{ statusLabel(article.workflow_status) }}</span>
          </div>
          <h3>{{ article.title_direction }}</h3>
          <dl class="content-meta">
            <div><dt>Primary Keyword</dt><dd>{{ article.primary_keyword || '—' }}</dd></div>
            <div><dt>Intent</dt><dd>{{ article.search_intent || '—' }}</dd></div>
            <div><dt>Topic</dt><dd>{{ article.topic_cluster || '—' }}</dd></div>
          </dl>
        </article>
        <div v-if="!pool.length" class="content-empty">当前没有符合你项目与语言范围的待领取文章。</div>
      </div>

      <div v-if="tab === 'mine'" class="content-stack">
        <article v-for="article in mine" :key="article.id" class="content-card">
          <div class="content-card-top">
            <div class="content-badges">
              <span>{{ contentTypeLabel(article.content_type) }}</span>
              <span>{{ article.project_key }}</span>
              <span>{{ article.language.toUpperCase() }}</span>
            </div>
            <span>{{ statusLabel(article.workflow_status) }}</span>
          </div>
          <h3><Link :href="`/content/articles/${article.id}`">{{ article.title_direction }}</Link></h3>
          <dl class="content-meta">
            <div><dt>Primary Keyword</dt><dd>{{ article.primary_keyword || '—' }}</dd></div>
            <div><dt>Intent</dt><dd>{{ article.search_intent || '—' }}</dd></div>
            <div><dt>Topic</dt><dd>{{ article.topic_cluster || '—' }}</dd></div>
          </dl>
        </article>
        <div v-if="!mine.length" class="content-empty">你还没有领取文章。</div>
      </div>

      <div v-if="tab === 'scopes' && canManage" class="content-scope-panel">
        <form class="scope-form" @submit.prevent="addScope">
          <label>写手
            <select v-model="scopeForm.user_id">
              <option v-for="writer in writers" :key="writer.id" :value="writer.id">{{ writer.name }} · {{ writer.email }}</option>
            </select>
          </label>
          <label>项目
            <select v-model="scopeForm.project_key">
              <option v-for="project in projects" :key="project.project_key" :value="project.project_key">{{ project.name }}</option>
            </select>
          </label>
          <label>语言
            <input v-model="scopeForm.language" maxlength="16" placeholder="en" />
          </label>
          <button class="content-btn content-btn-primary" type="submit">添加范围</button>
        </form>

        <div class="content-stack">
          <article v-for="writer in writers" :key="writer.id" class="content-card">
            <h3>{{ writer.name }}</h3>
            <p class="writer-email">{{ writer.email }}</p>
            <div class="scope-list">
              <span v-for="scope in writer.scopes" :key="scope.id" class="scope-chip">
                {{ scope.project_key }} · {{ scope.language.toUpperCase() }}
                <button type="button" @click="removeScope(scope.id)">×</button>
              </span>
              <span v-if="!writer.scopes.length">暂无内容范围</span>
            </div>
          </article>
        </div>
      </div>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.content-page{max-width:1180px;margin:0 auto;padding:8px 0 32px;color:#111827}.content-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:18px}.content-kicker{margin:0 0 5px;font-size:12px;font-weight:700;letter-spacing:.12em;color:#627267}.content-hero h2{margin:0;font-size:30px;line-height:1.15}.content-tabs{display:flex;gap:8px;overflow-x:auto;padding-bottom:12px;margin-bottom:12px}.content-tabs button{display:flex;gap:8px;align-items:center;white-space:nowrap;border:1px solid #d8ded9;background:#fff;border-radius:999px;padding:9px 14px;color:#24332a;cursor:pointer}.content-tabs button.active{background:#0f5132;border-color:#0f5132;color:#fff}.content-tabs span{font-variant-numeric:tabular-nums}.content-stack{display:grid;gap:12px}.content-card{border:1px solid #dfe5e1;border-radius:16px;background:#fff;padding:18px;box-shadow:0 1px 2px rgba(15,23,42,.04)}.content-card-top{display:flex;justify-content:space-between;gap:12px;align-items:center;color:#58645c;font-size:13px}.content-badges{display:flex;gap:7px;flex-wrap:wrap}.content-badges span{border-radius:999px;background:#f1f5f2;padding:5px 9px}.content-card h3{margin:13px 0 14px;font-size:19px;line-height:1.35}.content-card h3 a{color:#111827;text-decoration:none}.content-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0}.content-meta div{min-width:0}.content-meta dt{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#78847d}.content-meta dd{margin:4px 0 0;overflow-wrap:anywhere}.content-actions{display:flex;gap:8px;margin-top:16px}.content-btn{border:1px solid #cbd5ce;background:#fff;color:#193126;border-radius:10px;padding:9px 13px;font-weight:600;cursor:pointer}.content-btn-primary{background:#0f5132;border-color:#0f5132;color:#fff}.content-btn:disabled{opacity:.45;cursor:not-allowed}.content-empty{border:1px dashed #cbd5ce;border-radius:16px;padding:34px 18px;text-align:center;color:#647069;background:#fbfcfb}.content-scope-panel{display:grid;gap:16px}.scope-form{display:grid;grid-template-columns:1.2fr 1.1fr .7fr auto;gap:10px;align-items:end;border:1px solid #dfe5e1;border-radius:16px;background:#fff;padding:16px}.scope-form label{display:grid;gap:6px;font-size:12px;font-weight:600;color:#536159}.scope-form select,.scope-form input{width:100%;border:1px solid #cbd5ce;border-radius:9px;padding:9px 10px;background:#fff;color:#111827}.scope-list{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.scope-chip{display:inline-flex;gap:7px;align-items:center;background:#eef5f0;border-radius:999px;padding:6px 9px}.scope-chip button{border:0;background:transparent;cursor:pointer;font-size:16px;line-height:1}.writer-email{margin:-7px 0 0;color:#637068}@media(max-width:800px){.content-page{padding:0 2px 24px}.content-hero{align-items:flex-start}.content-hero h2{font-size:26px}.content-meta{grid-template-columns:1fr}.scope-form{grid-template-columns:1fr}.content-actions{flex-wrap:wrap}.content-btn{min-height:42px}.content-card{padding:15px}}
</style>
