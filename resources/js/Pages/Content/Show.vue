<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Article = {
  id:number
  project_key:string
  target_site:string
  language:string
  content_type:string
  title_direction:string
  primary_keyword:string|null
  supporting_keywords:string[]
  search_intent:string|null
  topic_cluster:string|null
  authoring_mode:string|null
  workflow_status:string
  writer_id:number|null
  claimed_at:string|null
  claim_expires_at:string|null
}
type LinkItem = { url?:string; target_url?:string; anchor_direction?:string|null; source?:string }
type Heading = { level?:string; direction?:string }
type DocumentSource = {
  document_url:string
  modified_time:string|null
  revision_id:string|null
  submitted_at:string|null
  imported_at:string|null
  changed_after_submission:boolean
}
type EditorialSnapshot = {
  source?:{ type?:string; version?:number; hash?:string }|null
  provider?:{ name?:string; model?:string }|null
  change_summary?:string[]
  verification_required?:{ claim?:string; reason?:string }[]
}
type PublicationPreview = {
  title?:string|null
  canonical_path?:string|null
  html?:string
  verification_required?:{ claim?:string; reason?:string }[]
  source?:{ type?:string; version?:number; hash?:string; snapshot_id?:number }|null
}
type Publication = {
  status:string
  layout_snapshot_id:number
  preview_hash:string
  verification_resolved_at:string|null
  provider:string|null
  external_id:string|null
  provider_status:string|null
  draft_url:string|null
  draft_synced_at:string|null
  writer_approved_at:string|null
  writer_approved_by:number|null
  fact_approved_at:string|null
  fact_approved_by:number|null
  seo_approved_at:string|null
  seo_approved_by:number|null
  requested_indexing:string
  published_url:string|null
  published_at:string|null
  preview:PublicationPreview|null
}
type Guideline = {
  version?:string
  primary_keyword?:string|null
  supporting_keywords?:string[]
  search_intent?:string|null
  target_audience?:string|null
  article_objective?:string|null
  title_direction?:string
  recommended_structure?:Heading[]
  questions_to_answer?:string[]
  recommended_word_count?:number
  internal_links?:LinkItem[]
  external_references?:Record<string,unknown>[]
  available_evidence?:Record<string,unknown>
  prohibited_claims?:string[]
  data_requiring_verification?:string[]
  brand_tone?:string
  language?:string
  cta_direction?:string
  submission_instructions?:string[]
}
const props = defineProps<{
  article:Article
  guideline:Guideline|null
  documentSource:DocumentSource|null
  latestWriterVersion:number
  latestEditorialVersion:number
  editorialSnapshot:EditorialSnapshot|null
  aiEditorialAvailable:boolean
  publication:Publication|null
  canReview:boolean
  canPublish:boolean
  canManage:boolean
  isOwner:boolean
}>()
const documentUrl = ref('')
const startWriting = () => router.post(`/content/articles/${props.article.id}/start`)
const release = () => router.post(`/content/articles/${props.article.id}/release`)
const canRelease = ['writer_claimed','writing','returned_for_revision'].includes(props.article.workflow_status)
const submitDocument = () => {
  if (!documentUrl.value.trim()) return
  router.post(`/content/articles/${props.article.id}/document`, { document_url: documentUrl.value.trim() })
}
const checkDocument = () => router.post(`/content/articles/${props.article.id}/document/check`)
const reimportDocument = () => router.post(`/content/articles/${props.article.id}/document/reimport`)
const runEditorial = () => router.post(`/content/articles/${props.article.id}/editorial`)
const preparePreview = () => router.post(`/content/articles/${props.article.id}/preview`)
const resolveVerification = () => router.post(`/content/articles/${props.article.id}/verification/resolve`)
const submitOwnerUat = () => router.post(`/content/articles/${props.article.id}/owner-uat`)
const approveWriter = () => router.post(`/content/articles/${props.article.id}/approvals/writer`)
const approveFact = () => router.post(`/content/articles/${props.article.id}/approvals/fact`)
const approveSeo = () => router.post(`/content/articles/${props.article.id}/approvals/seo`)
const publishArticle = () => router.post(`/content/articles/${props.article.id}/publish`)
const editorialEligible = ['doc_imported','draft_ready'].includes(props.article.workflow_status)
const hasVerification = () => (props.publication?.preview?.verification_required?.length || 0) > 0
const writerGateRequired = () => props.article.authoring_mode === 'human'
const publicationGatesPassed = () => !!((!writerGateRequired() || props.publication?.writer_approved_at) && props.publication?.fact_approved_at && props.publication?.seo_approved_at)
</script>

<template>
  <Head :title="`${article.title_direction} · Content OS`" />
  <AuthenticatedLayout :title="article.title_direction" :breadcrumbs="['YBY ERP', 'Content OS', article.title_direction]">
    <section class="writer-page">
      <div class="writer-topbar">
        <Link href="/content">← 返回 Content OS</Link>
        <div class="writer-actions" v-if="isOwner">
          <button v-if="article.workflow_status === 'writer_claimed'" class="writer-btn writer-btn-primary" type="button" @click="startWriting">开始写作</button>
          <button v-if="canRelease" class="writer-btn" type="button" @click="release">释放文章</button>
        </div>
      </div>

      <article class="writer-hero">
        <div class="writer-badges">
          <span>{{ article.project_key }}</span><span>{{ article.language.toUpperCase() }}</span><span>{{ article.content_type }}</span><span>{{ article.workflow_status }}</span>
        </div>
        <h1>{{ article.title_direction }}</h1>
        <div class="writer-core-grid">
          <div><small>Primary Keyword</small><strong>{{ article.primary_keyword || '—' }}</strong></div>
          <div><small>Intent</small><strong>{{ article.search_intent || '—' }}</strong></div>
          <div><small>Topic</small><strong>{{ article.topic_cluster || '—' }}</strong></div>
        </div>
      </article>

      <template v-if="guideline">
        <section class="writer-section">
          <h2>写作目标</h2>
          <div class="writer-two-col">
            <div><h3>目标读者</h3><p>{{ guideline.target_audience }}</p></div>
            <div><h3>文章目标</h3><p>{{ guideline.article_objective }}</p></div>
            <div><h3>品牌语气</h3><p>{{ guideline.brand_tone }}</p></div>
            <div><h3>CTA 方向</h3><p>{{ guideline.cta_direction }}</p></div>
          </div>
        </section>

        <section class="writer-section">
          <h2>关键词与篇幅</h2>
          <div class="writer-stat-row">
            <div><small>Primary</small><strong>{{ guideline.primary_keyword || '—' }}</strong></div>
            <div><small>Intent</small><strong>{{ guideline.search_intent || '—' }}</strong></div>
            <div><small>建议字数</small><strong>{{ guideline.recommended_word_count || '—' }}</strong></div>
          </div>
          <div v-if="guideline.supporting_keywords?.length" class="writer-chips">
            <span v-for="keyword in guideline.supporting_keywords" :key="keyword">{{ keyword }}</span>
          </div>
        </section>

        <section class="writer-section">
          <h2>推荐结构</h2>
          <ol class="writer-list structure-list">
            <li v-for="(item,index) in guideline.recommended_structure || []" :key="index">
              <b>{{ item.level || 'H2' }}</b><span>{{ item.direction }}</span>
            </li>
          </ol>
        </section>

        <section class="writer-section">
          <h2>必须回答的问题</h2>
          <ul class="writer-list"><li v-for="question in guideline.questions_to_answer || []" :key="question">{{ question }}</li></ul>
        </section>

        <section v-if="guideline.internal_links?.length" class="writer-section">
          <h2>内部链接</h2>
          <ul class="writer-link-list">
            <li v-for="(link,index) in guideline.internal_links" :key="index">
              <a :href="link.url || link.target_url" target="_blank" rel="noreferrer">{{ link.anchor_direction || link.url || link.target_url }}</a>
            </li>
          </ul>
        </section>

        <section class="writer-section writer-warning">
          <h2>不能编造</h2>
          <ul class="writer-list"><li v-for="item in guideline.prohibited_claims || []" :key="item">{{ item }}</li></ul>
          <h3>需要核实的数据</h3>
          <ul class="writer-list"><li v-for="item in guideline.data_requiring_verification || []" :key="item">{{ item }}</li></ul>
        </section>

        <section class="writer-section">
          <h2>提交方式</h2>
          <ol class="writer-list"><li v-for="item in guideline.submission_instructions || []" :key="item">{{ item }}</li></ol>
        </section>
      </template>

      <div v-else class="writer-empty">当前分支尚未生成 Writer Guideline。</div>

      <section v-if="article.authoring_mode" class="writer-section">
        <h2>{{ article.authoring_mode === 'ai' ? 'AI Draft → Human Writer Revision' : 'Google Docs 稿件' }}</h2>
        <template v-if="documentSource">
          <div class="document-source">
            <div>
              <small>Writer Original</small>
              <strong>v{{ latestWriterVersion }}</strong>
            </div>
            <div>
              <small>提交后变化</small>
              <strong>{{ documentSource.changed_after_submission ? '有新修改' : '未检测到变化' }}</strong>
            </div>
            <div>
              <small>Revision</small>
              <strong>{{ documentSource.revision_id || '—' }}</strong>
            </div>
          </div>
          <p class="document-link"><a :href="documentSource.document_url" target="_blank" rel="noreferrer">打开 Google Doc ↗</a></p>
          <div v-if="isOwner || canManage" class="writer-actions document-actions">
            <button class="writer-btn" type="button" @click="checkDocument">检查文档变化</button>
            <button v-if="documentSource.changed_after_submission" class="writer-btn writer-btn-primary" type="button" @click="reimportDocument">明确重新导入</button>
          </div>
          <p class="document-note">已导入的 Writer Original 不会被 Google Doc 后续修改静默覆盖；重新导入会创建新的版本。</p>
        </template>
        <form v-else-if="isOwner && article.workflow_status === 'writing'" class="document-submit" @submit.prevent="submitDocument">
          <label>
            Google Doc URL
            <input v-model="documentUrl" type="url" required placeholder="https://docs.google.com/document/d/..." />
          </label>
          <button class="writer-btn writer-btn-primary" type="submit">提交并导入</button>
          <p>ERP 只读取并保存不可变 Writer Original 快照；AI Draft 只作为起稿参考，最终内容必须由写手在 Google Docs 完善后提交。</p>
        </form>
        <div v-else class="document-note">开始写作后，可在这里提交 Google Doc；已提交稿件会保留为不可变 Writer Original。</div>
      </section>

      <section v-if="canReview || editorialSnapshot" class="writer-section">
        <h2>AI Editorial</h2>
        <template v-if="editorialSnapshot">
          <div class="document-source">
            <div><small>Editorial Snapshot</small><strong>v{{ latestEditorialVersion }}</strong></div>
            <div><small>Source</small><strong>{{ editorialSnapshot.source?.type || '—' }} v{{ editorialSnapshot.source?.version || '—' }}</strong></div>
            <div><small>Provider</small><strong>{{ editorialSnapshot.provider?.name || '—' }} · {{ editorialSnapshot.provider?.model || '—' }}</strong></div>
          </div>
          <div v-if="editorialSnapshot.change_summary?.length" class="editorial-block">
            <h3>编辑摘要</h3>
            <ul class="writer-list"><li v-for="item in editorialSnapshot.change_summary" :key="item">{{ item }}</li></ul>
          </div>
          <div v-if="editorialSnapshot.verification_required?.length" class="editorial-block editorial-verification">
            <h3>发布前必须核实</h3>
            <ul class="writer-list">
              <li v-for="(item,index) in editorialSnapshot.verification_required" :key="index">
                <b>{{ item.claim }}</b><span> — {{ item.reason }}</span>
              </li>
            </ul>
          </div>
          <p class="document-note">Editorial 是独立快照；Writer Original / AI Draft 不会被覆盖。</p>
        </template>
        <template v-else-if="canReview && editorialEligible">
          <button v-if="aiEditorialAvailable" class="writer-btn writer-btn-primary" type="button" @click="runEditorial">生成 AI Editorial</button>
          <p v-else class="document-note">AI Editorial provider 尚未配置；核心流程与权限已就绪，但不会伪装成真实 AI 执行。</p>
        </template>
        <p v-else class="document-note">文章进入已接受稿件状态后，Editorial 才能开始。</p>
      </section>

      <section v-if="canPublish || publication" class="writer-section">
        <h2>Layout / Publication</h2>
        <template v-if="publication?.preview">
          <div class="document-source">
            <div><small>Publication</small><strong>{{ publication.status }}</strong></div>
            <div><small>Source</small><strong>{{ publication.preview.source?.type || '—' }} v{{ publication.preview.source?.version || '—' }}</strong></div>
            <div><small>Preview Hash</small><strong class="hash-text">{{ publication.preview_hash.slice(0, 16) }}…</strong></div>
          </div>

          <div class="publication-preview">
            <div class="publication-preview-meta">
              <div><small>Title</small><strong>{{ publication.preview.title || '—' }}</strong></div>
              <div><small>Canonical</small><strong>{{ publication.preview.canonical_path || '—' }}</strong></div>
            </div>
            <article class="publication-html" v-html="publication.preview.html || ''"></article>
          </div>

          <div v-if="hasVerification()" class="editorial-block editorial-verification">
            <h3>发布前核实项</h3>
            <ul class="writer-list">
              <li v-for="(item,index) in publication.preview.verification_required" :key="index">
                <b>{{ item.claim }}</b><span> — {{ item.reason }}</span>
              </li>
            </ul>
            <p class="document-note">
              {{ publication.verification_resolved_at ? '已明确确认完成。' : '未确认；Owner UAT 会被阻止。' }}
            </p>
          </div>

          <div v-if="canPublish" class="writer-actions document-actions">
            <button
              v-if="article.workflow_status === 'layout_ready' && hasVerification() && !publication.verification_resolved_at"
              class="writer-btn"
              type="button"
              @click="resolveVerification"
            >确认核实完成</button>
            <button
              v-if="article.workflow_status === 'layout_ready' && (!hasVerification() || publication.verification_resolved_at)"
              class="writer-btn writer-btn-primary"
              type="button"
              @click="submitOwnerUat"
            >生成 WordPress Draft 并送 Owner UAT</button>
            <button
              v-if="article.workflow_status === 'owner_uat' && publicationGatesPassed()"
              class="writer-btn writer-btn-primary"
              type="button"
              @click="publishArticle"
            >Owner PASS 并发布</button>
          </div>

          <div v-if="article.workflow_status === 'owner_uat'" class="editorial-block publication-gates">
            <h3>Publication Gate</h3>
            <div class="gate-grid">
              <div><small>Writer PASS</small><strong>{{ writerGateRequired() ? (publication.writer_approved_at ? 'PASS' : 'Pending') : 'N/A · AI' }}</strong></div>
              <div><small>Fact PASS</small><strong>{{ publication.fact_approved_at ? 'PASS' : 'Pending' }}</strong></div>
              <div><small>SEO PASS</small><strong>{{ publication.seo_approved_at ? 'PASS' : 'Pending' }}</strong></div>
              <div><small>Indexing Request</small><strong>{{ publication.requested_indexing || 'noindex' }}</strong></div>
            </div>
            <div class="writer-actions document-actions">
              <button v-if="writerGateRequired() && isOwner && !publication.writer_approved_at" class="writer-btn" type="button" @click="approveWriter">Writer PASS</button>
              <button v-if="canReview && !publication.fact_approved_at" class="writer-btn" type="button" @click="approveFact">Fact PASS</button>
              <button v-if="canPublish && !publication.seo_approved_at" class="writer-btn" type="button" @click="approveSeo">SEO PASS</button>
            </div>
            <p class="document-note">Human 文章需要 Writer + Fact + SEO PASS；AI 文章需要 Fact + SEO PASS。两种模式都必须 Owner UAT。WordPress Draft 固定请求 noindex，正式发布切换为 index，并校验 provider 回读。</p>
          </div>

          <div v-if="publication.draft_url && article.workflow_status === 'owner_uat'" class="editorial-block">
            <h3>Owner UAT Draft</h3>
            <p class="document-link">
              <a :href="publication.draft_url" target="_blank" rel="noreferrer">打开 WordPress Draft ↗</a>
            </p>
            <p class="document-note">
              Provider {{ publication.provider || '—' }} · Post {{ publication.external_id || '—' }} · {{ publication.provider_status || 'draft' }}
            </p>
          </div>
          <p v-if="article.workflow_status === 'owner_uat' && canPublish && !publication.draft_url" class="document-note">
            Owner UAT 必须先存在真实 WordPress Draft；若 Draft 同步失败，系统保持 fail-closed。
          </p>
          <p v-if="publication.published_url" class="document-link">
            <a :href="publication.published_url" target="_blank" rel="noreferrer">打开已发布页面 ↗</a>
          </p>
        </template>

        <template v-else-if="canPublish && article.workflow_status === 'editorial_ready'">
          <button class="writer-btn writer-btn-primary" type="button" @click="preparePreview">生成 Layout Preview</button>
          <p class="document-note">Preview 由 Editorial Snapshot 确定性生成；不会调用 WordPress，也不会发布。</p>
        </template>

        <p v-else class="document-note">Editorial Ready 后才能创建 Layout Preview。</p>
      </section>
    </section>
  </AuthenticatedLayout>
</template>

<style scoped>
.writer-page{max-width:980px;margin:0 auto;padding:4px 0 40px;color:#111827}.writer-topbar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}.writer-topbar>a{color:#315b45;text-decoration:none}.writer-actions{display:flex;gap:8px}.writer-btn{border:1px solid #cbd5ce;background:#fff;color:#193126;border-radius:10px;padding:9px 13px;font-weight:600;cursor:pointer}.writer-btn-primary{background:#0f5132;border-color:#0f5132;color:#fff}.writer-hero,.writer-section{background:#fff;border:1px solid #dfe5e1;border-radius:18px;padding:22px;margin-bottom:14px}.writer-badges,.writer-chips{display:flex;gap:7px;flex-wrap:wrap}.writer-badges span,.writer-chips span{background:#eef5f0;border-radius:999px;padding:5px 9px;font-size:12px;color:#315b45}.writer-hero h1{font-size:30px;line-height:1.25;margin:16px 0 20px}.writer-core-grid,.writer-stat-row,.writer-two-col{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.writer-two-col{grid-template-columns:repeat(2,minmax(0,1fr))}.writer-core-grid div,.writer-stat-row div,.writer-two-col div{min-width:0}.writer-core-grid small,.writer-stat-row small{display:block;color:#68746d;margin-bottom:5px}.writer-core-grid strong,.writer-stat-row strong{overflow-wrap:anywhere}.writer-section h2{margin:0 0 16px;font-size:20px}.writer-section h3{margin:0 0 6px;font-size:14px}.writer-section p{margin:0;line-height:1.7}.writer-list{margin:0;padding-left:22px;display:grid;gap:10px;line-height:1.6}.structure-list{list-style:none;padding-left:0}.structure-list li{display:grid;grid-template-columns:44px 1fr;gap:10px}.structure-list b{color:#0f5132}.writer-chips{margin-top:14px}.writer-link-list{margin:0;padding-left:20px}.writer-link-list a{color:#17603a}.writer-warning{border-color:#dccca7;background:#fffdf8}.writer-empty{border:1px dashed #cbd5ce;border-radius:16px;padding:34px;text-align:center;color:#647069}.document-source{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.document-source div{border:1px solid #e4e8e5;border-radius:12px;padding:12px}.document-source small{display:block;color:#68746d;margin-bottom:5px}.document-link{margin:14px 0 0}.document-link a{color:#17603a}.document-actions{margin-top:14px}.editorial-block{margin-top:18px;padding-top:16px;border-top:1px solid #e4e8e5}.editorial-block h3{margin-bottom:10px}.editorial-verification{background:#fffdf8;border:1px solid #dccca7;border-radius:12px;padding:14px}.publication-preview{margin-top:16px;border:1px solid #dfe5e1;border-radius:14px;overflow:hidden}.publication-preview-meta{display:grid;grid-template-columns:1fr 1fr;gap:12px;background:#f7faf8;padding:14px;border-bottom:1px solid #e4e8e5}.publication-preview-meta small{display:block;color:#68746d;margin-bottom:4px}.publication-html{padding:22px;line-height:1.75}.publication-html :deep(h2),.publication-html :deep(h3),.publication-html :deep(h4){margin:20px 0 10px;color:#193126}.publication-html :deep(p){margin:0 0 14px}.publication-html :deep(ul),.publication-html :deep(ol){padding-left:24px;margin:0 0 14px}.hash-text{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}.document-note{margin:12px 0 0!important;color:#68746d;font-size:13px}.gate-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.gate-grid div{border:1px solid #e4e8e5;border-radius:10px;padding:10px}.gate-grid small{display:block;color:#68746d;margin-bottom:5px}.publication-gates strong{color:#193126}.document-submit{display:grid;gap:12px}.document-submit label{display:grid;gap:7px;font-size:13px;font-weight:600}.document-submit input{border:1px solid #cbd5ce;border-radius:10px;padding:10px 12px}.document-submit .writer-btn{justify-self:start}.document-submit p{color:#68746d;font-size:13px}@media(max-width:800px){.writer-page{padding:0 2px 28px}.writer-topbar{align-items:flex-start}.writer-actions{flex-wrap:wrap;justify-content:flex-end}.writer-hero,.writer-section{padding:16px;border-radius:14px}.writer-hero h1{font-size:24px}.writer-core-grid,.writer-stat-row,.writer-two-col,.document-source,.gate-grid{grid-template-columns:1fr}.writer-btn{min-height:42px}}
</style>
