<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type StageAttachment = {
  id:number
  name:string
  mime_type:string|null
  size_bytes:number
  url:string
}
type StageFollowUp = {
  id:number
  stage_key:string|null
  channel:string
  outcome:string|null
  summary:string
  next_plan:string|null
  happened_at:string|null
  next_follow_up_at:string|null
  actor:{id:number;name:string}|null
  attachments:StageAttachment[]
}
type RoadmapNode = {
  key:string
  label:string
  state:'completed'|'current'|'pending'|'skipped'
  entered_at:string|null
  exited_at:string|null
  duration_days:number|null
  note_count:number
  attachment_count:number
}
type Roadmap = {
  current_stage:string
  current_label:string
  next_stage:string|null
  next_label:string|null
  closed:boolean
  total_elapsed_days:number
  nodes:RoadmapNode[]
  events:Array<{
    id:number
    from_stage:string|null
    to_stage:string
    to_label:string
    entered_at:string|null
    source:string
    reason:string|null
    actor:{id:number;name:string}|null
  }>
}

type PaymentReceipt = {
  id:number
  receipt_number:string
  amount_micros:number
  currency:string
  received_at:string|null
  payment_method:string
  entry_source:string
  provider_reference:string|null
  evidence_reference:string|null
  note:string|null
  status:'confirmed'|'voided'
  recorded_by:{id:number;name:string}|null
  voided_at:string|null
  void_reason:string|null
  voided_by:{id:number;name:string}|null
}
type PaymentTotal = { currency:string; amount_micros:number; receipt_count:number }
type ReceivableSummary = {
  status:'pending_receivable'|'unpaid'|'partial'|'paid'|'overpaid'
  amount_micros:number|null
  currency:string|null
  received_micros:number
  outstanding_micros:number|null
  overpaid_micros:number
  source:'manual'|'quote'|null
  quote_id:number|null
  quote_number:string|null
  confirmed_at:string|null
  confirmed_by:{id:number;name:string}|null
  note:string|null
  other_currency_totals:PaymentTotal[]
}

const props=defineProps<{
  project:{key:string;name:string}
  opportunity:any
  payment_receipts:PaymentReceipt[]
  payment_totals:PaymentTotal[]
  receivable:ReceivableSummary
  roadmap:Roadmap
}>()

const stageLabel:Record<string,string>={discovery:'初步接洽',qualified:'已确认需求',solution:'方案阶段',quoted:'已报价',negotiating:'谈判中',won:'成交',lost:'丢单'}
const paymentMethodLabel:Record<string,string>={bank_transfer:'银行转账',alipay:'支付宝',alibaba:'阿里',paypal:'PayPal',stripe:'Stripe',other:'其他'}
const receivableStatusLabel:Record<ReceivableSummary['status'],string>={pending_receivable:'待确认应收',unpaid:'未收款',partial:'部分收款',paid:'已收清',overpaid:'超额收款'}
const money=(micros:number|null,currency='USD')=>micros===null?'—':new Intl.NumberFormat('zh-CN',{style:'currency',currency,maximumFractionDigits:2}).format(micros/1_000_000)
const localDateTime=()=>{
  const d=new Date()
  const pad=(value:number)=>String(value).padStart(2,'0')
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const selectedStage=ref(props.roadmap.current_stage)
const closureOpen=ref(false)
const closureOutcome=ref<'won'|'lost'>('won')
const lostReasonCode=ref('')
const lostReasonNote=ref('')
const reopenOpen=ref(false)
const reopenStage=ref('')
const reopenReason=ref('')
const stageForm=useForm({pipeline_stage:props.opportunity.pipeline_stage})
const followForm=useForm({
  stage_key:props.roadmap.current_stage,
  channel:'note',
  outcome:'',
  summary:'',
  next_plan:'',
  happened_at:localDateTime(),
  next_follow_up_at:'',
  attachments:[] as File[],
})
const quoteForm=useForm({currency:props.opportunity.currency,subtotal:'',discount:'',tax:'',valid_until:'',notes:''})
const receivableForm=useForm({
  source:'manual',
  amount:props.receivable.amount_micros===null?'':String(props.receivable.amount_micros/1_000_000),
  currency:props.receivable.currency ?? props.opportunity.currency,
  quote_id:props.receivable.quote_id===null?'':String(props.receivable.quote_id),
  note:props.receivable.note ?? '',
})
const paymentForm=useForm({
  amount:'',
  currency:props.opportunity.currency,
  received_at:localDateTime(),
  payment_method:'bank_transfer',
  provider_reference:'',
  evidence_reference:'',
  note:'',
})

const selectedNode=computed(()=>props.roadmap.nodes.find(node=>node.key===selectedStage.value) ?? props.roadmap.nodes.find(node=>node.key===props.roadmap.current_stage) ?? props.roadmap.nodes[0])
const selectedStageKey=computed(()=>{
  const key=selectedNode.value?.key ?? props.roadmap.current_stage
  return key==='final' ? props.roadmap.current_stage : key
})
const stageFollowUps=computed<StageFollowUp[]>(()=>((props.opportunity.follow_ups ?? []) as StageFollowUp[]).filter(item=>{
  if(item.stage_key===selectedStageKey.value) return true
  return item.stage_key===null && selectedStageKey.value===props.roadmap.current_stage
}))
const currentStageFollowUps=computed<StageFollowUp[]>(()=>((props.opportunity.follow_ups ?? []) as StageFollowUp[]).filter(item=>item.stage_key===props.roadmap.current_stage || item.stage_key===null))
const latestCurrentFollowUp=computed(()=>currentStageFollowUps.value[0] ?? null)
const lastLostEvent=computed(()=>[...props.roadmap.events].reverse().find(event=>event.to_stage==='lost') ?? null)
const lastReopenEvent=computed(()=>[...props.roadmap.events].reverse().find(event=>event.source==='reopen') ?? null)
const lostHistoryCount=computed(()=>props.roadmap.events.filter(event=>event.to_stage==='lost').length)
const reopenHistoryCount=computed(()=>props.roadmap.events.filter(event=>event.source==='reopen').length)
const eventSourceLabel:Record<string,string>={create:'创建',manual:'推进',quote:'报价推进',close:'关闭',reopen:'重新联系',uat_snapshot:'历史快照'}
const openClosure=(outcome:'won'|'lost'='won')=>{
  closureOutcome.value=outcome
  closureOpen.value=true
}
const closeClosure=()=>{
  closureOpen.value=false
  lostReasonCode.value=''
  lostReasonNote.value=''
}
const openReopen=()=>{
  reopenStage.value=''
  reopenReason.value=''
  reopenOpen.value=true
}
const closeReopen=()=>{
  reopenOpen.value=false
  reopenStage.value=''
  reopenReason.value=''
}
const submitReopen=()=>{
  if(!reopenStage.value || !reopenReason.value.trim()) return
  router.post(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/reopen`,{
    pipeline_stage:reopenStage.value,
    reopen_reason:reopenReason.value.trim(),
  },{preserveScroll:true,onSuccess:closeReopen})
}
const selectStage=(node:RoadmapNode)=>{
  if(node.key==='final' && !props.roadmap.closed){
    openClosure('won')
    return
  }
  selectedStage.value=node.key
  followForm.stage_key=node.key==='final'?props.roadmap.current_stage:node.key
}
const advanceStage=()=>{
  if(!props.roadmap.next_stage) return
  router.patch(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/stage`,{pipeline_stage:props.roadmap.next_stage},{preserveScroll:true})
}
const handleAttachments=(event:Event)=>{
  const input=event.target as HTMLInputElement
  followForm.attachments=Array.from(input.files ?? [])
}
const channelLabel:Record<string,string>={note:'内部记录',email:'Email',whatsapp:'WhatsApp',call:'电话',meeting:'会议'}
const isImage=(attachment:StageAttachment)=>Boolean(attachment.mime_type?.startsWith('image/'))
const fileSize=(bytes:number)=>bytes>=1024*1024?`${(bytes/1024/1024).toFixed(1)} MB`:`${Math.max(1,Math.round(bytes/1024))} KB`
const shortDate=(value:string|null)=>value?new Date(value).toLocaleDateString('zh-CN',{month:'2-digit',day:'2-digit'}).replaceAll('/','-'):'—'
const actorInitial=(name:string|null|undefined)=>String(name||'S').trim().slice(0,1).toUpperCase()

const updateStage=()=>stageForm.patch(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/stage`,{preserveScroll:true})
const addFollow=()=>followForm.post(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/follow-ups`,{
  preserveScroll:true,
  forceFormData:true,
  onSuccess:()=>{
    followForm.reset('outcome','summary','next_plan','next_follow_up_at','attachments')
    followForm.stage_key=selectedStageKey.value
    followForm.happened_at=localDateTime()
  },
})
const addQuote=()=>quoteForm.transform((d:any)=>({
  currency:d.currency,
  subtotal_micros:Math.round(Number(d.subtotal||0)*1_000_000),
  discount_micros:Math.round(Number(d.discount||0)*1_000_000),
  tax_micros:Math.round(Number(d.tax||0)*1_000_000),
  valid_until:d.valid_until||null,
  notes:d.notes||null,
})).post(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/quotes`)
const confirmReceivable=()=>receivableForm.transform((d:any)=>({
  source:d.source,
  amount_micros:d.source==='manual'?Math.round(Number(d.amount||0)*1_000_000):null,
  currency:d.source==='manual'?String(d.currency||'').toUpperCase():null,
  quote_id:d.source==='quote'?Number(d.quote_id||0):null,
  note:d.note||null,
})).patch(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/receivable`,{preserveScroll:true})
const addPayment=()=>paymentForm.transform((d:any)=>({
  amount_micros:Math.round(Number(d.amount||0)*1_000_000),
  currency:String(d.currency||'').toUpperCase(),
  received_at:d.received_at,
  payment_method:d.payment_method,
  provider_reference:d.provider_reference||null,
  evidence_reference:d.evidence_reference||null,
  note:d.note||null,
})).post(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/payment-receipts`,{
  preserveScroll:true,
  onSuccess:()=>{
    paymentForm.reset('amount','provider_reference','evidence_reference','note')
    paymentForm.received_at=localDateTime()
  },
})
const voidPayment=(receipt:PaymentReceipt)=>{
  const reason=prompt(`作废回款 ${receipt.receipt_number}：请输入原因`)
  if(reason?.trim()) router.patch(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/payment-receipts/${receipt.id}/void`,{void_reason:reason.trim()},{preserveScroll:true})
}
const submitClosure=()=>{
  if(closureOutcome.value==='won'){
    router.post(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/won`,{}, {preserveScroll:true,onSuccess:closeClosure})
    return
  }
  const reason=lostReasonCode.value.trim()
  if(!reason) return
  router.post(`/projects/${props.project.key}/sales/opportunities/${props.opportunity.id}/lost`,{
    lost_reason_code:reason,
    lost_reason_note:lostReasonNote.value.trim()||null,
  },{preserveScroll:true,onSuccess:closeClosure})
}
const won=()=>openClosure('won')
const lost=()=>openClosure('lost')
const formatDate=(value:string|null)=>value?new Date(value).toLocaleString('zh-CN',{hour12:false}):'—'
const isUrl=(value:string|null)=>Boolean(value && /^https?:\/\//i.test(value))
</script>

<template>
<Head :title="`${opportunity.opportunity_number} · 销售 · YBY ERP`"/>
<AuthenticatedLayout title="商机" :breadcrumbs="['YBY ERP',project.name,'销售',opportunity.opportunity_number]" canonical-topbar>
<section class="customer-os-page sales-canonical-page sales-opportunity-detail">
  <header class="sales-canonical-hero">
    <div class="sales-canonical-hero-copy"><p class="sales-canonical-kicker">SALES OS · OPPORTUNITY</p><h2>{{ opportunity.title }}</h2><p>{{ opportunity.opportunity_number }} · {{ opportunity.company?.name }} · <span class="opportunity-status-pill" :class="`is-${opportunity.status}`">{{ opportunity.status==='won'?'已成交':opportunity.status==='lost'?'已丢单':'进行中' }}</span></p></div>
    <div class="sales-canonical-actions"><Link class="customer-os-button" :href="`/projects/${project.key}/sales`">返回 Pipeline</Link><Link class="customer-os-button" :href="`/projects/${project.key}/sales/receipts`">回款明细</Link><button v-if="opportunity.status==='open'" class="customer-os-button customer-os-button-primary" @click="won">标记成交</button><button v-if="opportunity.status==='open'" class="customer-os-button" @click="lost">标记丢单</button></div>
  </header>

  <section v-if="opportunity.status==='lost'" class="lost-opportunity-banner">
    <div class="lost-opportunity-icon">×</div>
    <div class="lost-opportunity-copy"><p class="sales-detail-kicker">LOST OPPORTUNITY</p><h3>此商机已丢单</h3><p><strong>{{ opportunity.lost_reason_code || '未记录原因' }}</strong><span v-if="opportunity.lost_reason_note"> · {{ opportunity.lost_reason_note }}</span></p><small v-if="opportunity.lost_at">关闭时间：{{ formatDate(opportunity.lost_at) }}</small></div>
    <div class="lost-opportunity-actions"><button type="button" class="customer-os-button customer-os-button-primary" @click="openReopen">重新联系 / 重开商机</button></div>
  </section>

  <section v-else-if="lastReopenEvent" class="reopened-opportunity-banner">
    <div><strong>曾丢单后重开</strong><span>当前已重新进入销售流程</span></div>
    <small>{{ lastReopenEvent.entered_at ? formatDate(lastReopenEvent.entered_at) : '—' }} · {{ lastReopenEvent.reason }}</small>
  </section>

  <div class="sales-kpi-grid opportunity-kpi-grid">
    <article class="sales-kpi-card"><span class="sales-kpi-icon">◎</span><div><span>当前阶段</span><strong>{{ stageLabel[opportunity.pipeline_stage] ?? opportunity.pipeline_stage }}</strong><small>Sales Pipeline 当前节点</small></div></article>
    <article class="sales-kpi-card is-indigo"><span class="sales-kpi-icon">¥</span><div><span>预计金额</span><strong>{{ money(opportunity.expected_value_micros,opportunity.currency) }}</strong><small>销售预估，不代表实收</small></div></article>
    <article class="sales-kpi-card is-blue"><span class="sales-kpi-icon">%</span><div><span>成交概率</span><strong>{{ (opportunity.probability_bps/100).toFixed(0) }}%</strong><small>当前商机概率</small></div></article>
    <article class="sales-kpi-card is-green"><span class="sales-kpi-icon">●</span><div><span>负责人</span><strong>{{ opportunity.owner?.name ?? '待分配' }}</strong><small>当前跟进 Owner</small></div></article>
    <article class="sales-kpi-card is-amber"><span class="sales-kpi-icon">▤</span><div><span>报价版本</span><strong>{{ opportunity.quotes.length }}</strong><small>历史报价版本数量</small></div></article>
  </div>

  <article class="opportunity-roadmap-card">
    <div class="roadmap-heading">
      <div><p class="sales-detail-kicker">Opportunity Roadmap V1</p><h3>商机推进路线图</h3><p>从左到右推进，记录每个阶段的沟通、文件和进展。</p></div>
      <div class="roadmap-summary"><span>总时长 <strong>{{ roadmap.total_elapsed_days }} 天</strong></span><span v-if="opportunity.expected_close_date">预计 {{ opportunity.expected_close_date }}</span></div>
    </div>

    <div class="roadmap-track">
      <button
        v-for="(node,index) in roadmap.nodes"
        :key="node.key"
        type="button"
        class="roadmap-node"
        :class="['is-'+node.state,{'is-selected':selectedNode?.key===node.key,'is-final-action':node.key==='final'&&!roadmap.closed}]"
        @click="selectStage(node)"
      >
        <span class="roadmap-dot">{{ node.state==='completed'?'✓':index+1 }}</span>
        <span class="roadmap-node-copy">
          <strong>{{ node.label }}</strong>
          <small>{{ node.entered_at ? shortDate(node.entered_at) : (node.state==='pending'?'未开始':node.state==='skipped'?'已跳过':'记录起点未知') }}</small>
          <small v-if="node.duration_days!==null">停留 {{ node.duration_days }} 天</small>
          <small v-else>—</small>
          <span class="roadmap-counts"><i>▧ {{ node.note_count }}</i><i>↗ {{ node.attachment_count }}</i></span>
        </span>
      </button>
    </div>

    <div v-if="roadmap.events.length" class="roadmap-lifetime">
      <div class="roadmap-lifetime-heading"><div><strong>商机生命周期</strong><span>历史只叠加，不会因重开而清空</span></div><small v-if="lostHistoryCount || reopenHistoryCount">丢单 {{ lostHistoryCount }} 次 · 重开 {{ reopenHistoryCount }} 次</small></div>
      <div class="roadmap-lifetime-track">
        <article v-for="event in roadmap.events" :key="event.id" class="roadmap-lifetime-event" :class="{'is-lost':event.to_stage==='lost','is-reopen':event.source==='reopen','is-won':event.to_stage==='won'}">
          <span class="roadmap-lifetime-dot"></span>
          <div><strong>{{ event.to_label }}</strong><small>{{ eventSourceLabel[event.source] ?? event.source }} · {{ event.entered_at ? formatDate(event.entered_at) : '—' }}</small><p v-if="event.reason">{{ event.reason }}</p></div>
        </article>
      </div>
    </div>
  </article>

  <div class="roadmap-workspace">
    <article class="sales-detail-card stage-detail-card">
      <div class="stage-detail-header">
        <div class="stage-title-mark">▤</div>
        <div><div class="stage-title-line"><h3>{{ selectedNode?.label }}</h3><span v-if="selectedNode?.state==='current'" class="stage-current-pill">当前阶段</span><span v-else-if="selectedNode?.state==='completed'" class="stage-done-pill">已完成</span></div><p>查看并记录这个阶段发生的沟通、文件和下一步计划。</p></div>
        <dl>
          <div><dt>进入时间</dt><dd>{{ selectedNode?.entered_at ? formatDate(selectedNode.entered_at) : '—' }}</dd></div>
          <div><dt>停留天数</dt><dd>{{ selectedNode?.duration_days===null?'—':selectedNode?.duration_days+' 天' }}</dd></div>
          <div><dt>负责人</dt><dd>{{ opportunity.owner?.name ?? '待分配' }}</dd></div>
        </dl>
      </div>

      <form v-if="selectedNode?.state!=='pending'" class="stage-record-form" @submit.prevent="addFollow">
        <div class="stage-record-form-row">
          <label><span>沟通方式</span><select v-model="followForm.channel"><option value="note">内部记录</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="call">电话</option><option value="meeting">会议</option></select></label>
          <label><span>发生时间</span><input v-model="followForm.happened_at" type="datetime-local"></label>
          <label><span>结果</span><input v-model="followForm.outcome" placeholder="可选，例如：客户确认需求"></label>
        </div>
        <label class="stage-record-summary"><span>阶段沟通记录</span><textarea v-model="followForm.summary" required placeholder="记录客户反馈、内部判断、价格讨论或当前进度…"></textarea></label>
        <div class="stage-record-form-row">
          <label class="stage-plan-input"><span>下一步计划</span><input v-model="followForm.next_plan" placeholder="例如：准备 16mm 快接管方案和 PI"></label>
          <label><span>预计下次跟进</span><input v-model="followForm.next_follow_up_at" type="datetime-local"></label>
          <label class="stage-file-picker"><span>图片 / 文件</span><input type="file" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" @change="handleAttachments"><small v-if="followForm.attachments.length">已选 {{ followForm.attachments.length }} 个文件</small></label>
        </div>
        <div class="stage-record-submit"><span>每条记录都会固定归属到「{{ selectedNode?.label }}」阶段。</span><button class="customer-os-button customer-os-button-primary" :disabled="followForm.processing">+ 添加记录</button></div>
      </form>

      <div v-if="stageFollowUps.length" class="stage-communication-timeline">
        <article v-for="item in stageFollowUps" :key="item.id" class="stage-communication-item">
          <div class="stage-avatar">{{ actorInitial(item.actor?.name) }}</div>
          <div class="stage-communication-body">
            <div class="stage-communication-meta"><strong>{{ item.actor?.name ?? 'System' }}</strong><span>{{ channelLabel[item.channel] ?? item.channel }}</span><time>{{ formatDate(item.happened_at) }}</time></div>
            <p>{{ item.summary }}</p>
            <div v-if="item.outcome" class="stage-outcome">结果：{{ item.outcome }}</div>
            <div v-if="item.attachments.length" class="stage-attachments">
              <a v-for="attachment in item.attachments" :key="attachment.id" :href="attachment.url" target="_blank" rel="noreferrer" :class="['stage-attachment',{'is-image':isImage(attachment)}]">
                <img v-if="isImage(attachment)" :src="attachment.url" :alt="attachment.name">
                <span v-else class="stage-file-icon">FILE</span>
                <span class="stage-file-copy"><strong>{{ attachment.name }}</strong><small>{{ fileSize(attachment.size_bytes) }}</small></span>
              </a>
            </div>
            <div v-if="item.next_plan || item.next_follow_up_at" class="stage-next-plan"><strong>下一步</strong><span v-if="item.next_plan">{{ item.next_plan }}</span><small v-if="item.next_follow_up_at">{{ formatDate(item.next_follow_up_at) }}</small></div>
          </div>
        </article>
      </div>
      <div v-else class="sales-detail-empty">这个阶段还没有沟通记录。</div>
    </article>

    <aside class="stage-action-column">
      <article class="sales-detail-card stage-action-card">
        <div class="sales-detail-heading"><div><h3>阶段操作</h3><p>推进主流程，必要时也可以手动校正阶段。</p></div></div>
        <div class="stage-action-current"><span>当前阶段</span><strong>{{ roadmap.current_label }}</strong></div>
        <template v-if="opportunity.status==='open'">
          <div v-if="roadmap.next_stage" class="stage-next-action"><span>推进到下一阶段</span><strong>{{ roadmap.next_label }}</strong><button class="customer-os-button customer-os-button-primary" type="button" @click="advanceStage">确认推进 →</button></div>
          <div v-else class="stage-next-action final-result-action"><span>销售流程已到最后阶段</span><strong>进入最终结果</strong><button class="customer-os-button customer-os-button-primary" type="button" @click="openClosure('won')">标记成交 / 丢单 →</button></div>
          <form class="stage-adjust-form" @submit.prevent="updateStage"><label><span>调整当前阶段</span><select v-model="stageForm.pipeline_stage"><option value="discovery">初步接洽</option><option value="qualified">已确认需求</option><option value="solution">方案阶段</option><option value="quoted">已报价</option><option value="negotiating">谈判中</option></select></label><button class="customer-os-button">保存调整</button></form>
        </template>
      </article>

      <article class="sales-detail-card stage-plan-card">
        <div class="sales-detail-heading"><div><h3>预计下次跟进</h3><p>来自当前阶段最近一条沟通记录。</p></div></div>
        <strong class="stage-next-date">{{ opportunity.next_follow_up_at ? formatDate(opportunity.next_follow_up_at) : '尚未安排' }}</strong>
        <p>{{ latestCurrentFollowUp?.next_plan ?? '还没有填写下一步计划。' }}</p>
      </article>
    </aside>
  </div>

  <article class="sales-detail-card receivable-truth-card">
    <div class="sales-detail-heading">
      <div><p class="sales-detail-kicker">Revenue Truth V1 · R3</p><h3>应收与收款状态</h3><p>应收必须人工确认；预计金额和报价不会自动成为应收。</p></div>
    </div>

    <div class="receivable-status-grid">
      <div><span>当前状态</span><strong>{{ receivableStatusLabel[receivable.status] }}</strong></div>
      <div><span>确认应收</span><strong>{{ receivable.amount_micros===null?'—':money(receivable.amount_micros,receivable.currency || opportunity.currency) }}</strong></div>
      <div><span>已确认实收</span><strong>{{ receivable.currency?money(receivable.received_micros,receivable.currency):'—' }}</strong></div>
      <div><span>{{ receivable.status==='overpaid'?'超额':'待收' }}</span><strong>{{ receivable.status==='overpaid' ? money(receivable.overpaid_micros,receivable.currency || opportunity.currency) : (receivable.outstanding_micros===null?'—':money(receivable.outstanding_micros,receivable.currency || opportunity.currency)) }}</strong></div>
    </div>

    <p v-if="receivable.source" class="receivable-meta">
      应收来源：{{ receivable.source==='quote' ? `报价 ${receivable.quote_number ?? ''}` : '人工确认' }}
      <span v-if="receivable.confirmed_by"> · {{ receivable.confirmed_by.name }}</span>
      <span v-if="receivable.confirmed_at"> · {{ formatDate(receivable.confirmed_at) }}</span>
    </p>
    <div v-if="receivable.other_currency_totals.length" class="receivable-other">
      其他币种回款不参与当前状态：
      <span v-for="total in receivable.other_currency_totals" :key="total.currency">{{ money(total.amount_micros,total.currency) }} ({{ total.receipt_count }} 笔)</span>
    </div>

    <form v-if="opportunity.status!=='lost'" class="receivable-form" @submit.prevent="confirmReceivable">
      <label><span>应收来源</span><select v-model="receivableForm.source"><option value="manual">人工确认最终应收</option><option value="quote">从报价版本确认</option></select></label>
      <template v-if="receivableForm.source==='manual'">
        <label><span>最终应收金额</span><input v-model="receivableForm.amount" required min="0.000001" step="0.01" inputmode="decimal" placeholder="例如 10000"></label>
        <label><span>币种</span><input v-model="receivableForm.currency" required maxlength="3" placeholder="USD"></label>
      </template>
      <label v-else><span>报价版本</span><select v-model="receivableForm.quote_id" required><option value="">选择报价</option><option v-for="quote in opportunity.quotes" :key="quote.id" :value="String(quote.id)">V{{ quote.version }} · {{ money(quote.total_micros,quote.currency) }}</option></select></label>
      <label class="receivable-note"><span>确认备注</span><textarea v-model="receivableForm.note" placeholder="例如：客户已确认最终 PI / 报价金额"></textarea></label>
      <div class="receivable-submit"><p>修改应收会留下审计记录，不会修改历史回款。</p><button class="customer-os-button customer-os-button-primary" :disabled="receivableForm.processing">确认应收</button></div>
    </form>
  </article>

  <article class="sales-detail-card payment-truth-card">
    <div class="sales-detail-heading">
      <div><p class="sales-detail-kicker">Revenue Truth V1</p><h3>实际回款</h3><p>只有员工确认钱已到账后才登记。报价金额、预计金额和“成交”状态都不会自动计入实收。</p></div>
    </div>

    <div v-if="payment_totals.length" class="payment-summary">
      <div v-for="total in payment_totals" :key="total.currency" class="payment-summary-item">
        <span>累计实收 · {{ total.currency }}</span>
        <strong>{{ money(total.amount_micros,total.currency) }}</strong>
        <small>{{ total.receipt_count }} 笔已确认回款</small>
      </div>
    </div>
    <div v-else class="sales-detail-empty payment-empty">尚无已确认实收</div>

    <form class="payment-form" @submit.prevent="addPayment">
      <label><span>到账金额</span><input v-model="paymentForm.amount" required min="0.000001" step="0.01" inputmode="decimal" placeholder="例如 3000"></label>
      <label><span>币种</span><input v-model="paymentForm.currency" required maxlength="3" placeholder="USD"></label>
      <label><span>收款方式</span><select v-model="paymentForm.payment_method" required><option value="bank_transfer">银行转账</option><option value="alipay">支付宝</option><option value="alibaba">阿里</option><option value="paypal">PayPal</option><option value="stripe">Stripe</option><option value="other">其他</option></select></label>
      <label><span>到账时间</span><input v-model="paymentForm.received_at" required type="datetime-local"></label>
      <label><span>付款参考号</span><input v-model="paymentForm.provider_reference" placeholder="银行流水 / 支付宝 / 阿里参考号"></label>
      <label><span>凭证引用</span><input v-model="paymentForm.evidence_reference" placeholder="群确认截图、文件名或凭证链接"></label>
      <label class="payment-note"><span>备注</span><textarea v-model="paymentForm.note" placeholder="例如：30% 定金，销售群已确认"></textarea></label>
      <div class="payment-submit"><p>登记后会形成独立到账记录；如录错，只能作废，不删除历史。</p><button class="customer-os-button customer-os-button-primary" :disabled="paymentForm.processing">确认到账</button></div>
    </form>

    <div v-if="payment_receipts.length" class="sales-detail-table-wrap payment-table-wrap">
      <table class="customer-os-table">
        <thead><tr><th>回款号</th><th>到账时间</th><th>金额</th><th>方式</th><th>登记人</th><th>参考 / 凭证</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="receipt in payment_receipts" :key="receipt.id" :class="{'payment-voided':receipt.status==='voided'}">
            <td><strong>{{ receipt.receipt_number }}</strong><small v-if="receipt.note">{{ receipt.note }}</small></td>
            <td>{{ formatDate(receipt.received_at) }}</td>
            <td><strong>{{ money(receipt.amount_micros,receipt.currency) }}</strong></td>
            <td>{{ paymentMethodLabel[receipt.payment_method] ?? receipt.payment_method }}</td>
            <td>{{ receipt.recorded_by?.name ?? 'System' }}</td>
            <td>
              <div v-if="receipt.provider_reference">{{ receipt.provider_reference }}</div>
              <a v-if="isUrl(receipt.evidence_reference)" :href="receipt.evidence_reference || '#'" target="_blank" rel="noreferrer">查看凭证</a>
              <small v-else-if="receipt.evidence_reference">{{ receipt.evidence_reference }}</small>
              <span v-if="!receipt.provider_reference && !receipt.evidence_reference">—</span>
            </td>
            <td><span class="payment-status" :class="receipt.status">{{ receipt.status==='confirmed'?'已确认':'已作废' }}</span><small v-if="receipt.status==='voided'">{{ receipt.void_reason }}</small></td>
            <td><button v-if="receipt.status==='confirmed'" type="button" class="customer-os-button payment-void-button" @click="voidPayment(receipt)">作废</button><span v-else>—</span></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-else class="sales-detail-empty">暂无回款记录</div>
  </article>

  <article class="sales-detail-card quote-detail-card">
    <div class="sales-detail-heading"><div><h3>报价</h3><p>报价金额仍是销售事实，不自动记为真实 Revenue。</p></div></div>
    <form v-if="opportunity.status==='open'" class="sales-detail-form-grid" @submit.prevent="addQuote"><input v-model="quoteForm.subtotal" required inputmode="decimal" placeholder="小计"><input v-model="quoteForm.discount" inputmode="decimal" placeholder="折扣"><input v-model="quoteForm.tax" inputmode="decimal" placeholder="税费"><input v-model="quoteForm.currency" maxlength="3"><input v-model="quoteForm.valid_until" type="date"><textarea v-model="quoteForm.notes" placeholder="报价备注"></textarea><button class="customer-os-button customer-os-button-primary">创建报价版本</button></form>
    <div v-if="opportunity.quotes.length" class="sales-detail-table-wrap"><table class="customer-os-table"><thead><tr><th>报价号</th><th>版本</th><th>状态</th><th>总额</th><th>有效期</th></tr></thead><tbody><tr v-for="q in opportunity.quotes" :key="q.id"><td>{{ q.quote_number }}</td><td>V{{ q.version }}</td><td>{{ q.status }}</td><td>{{ money(q.total_micros,q.currency) }}</td><td>{{ q.valid_until ?? '—' }}</td></tr></tbody></table></div><div v-else class="sales-detail-empty">暂无报价</div>
  </article>

  <div v-if="reopenOpen" class="closure-modal-backdrop" @click.self="closeReopen">
    <section class="closure-modal reopen-modal" role="dialog" aria-modal="true" aria-label="重新联系并重开商机">
      <div class="closure-modal-heading">
        <div><p class="sales-detail-kicker">REOPEN OPPORTUNITY</p><h3>重新联系 / 重开商机</h3><p>不会删除之前的丢单记录；系统会新增一条重开事件并继续 Roadmap。</p></div>
        <button type="button" class="closure-modal-close" @click="closeReopen">×</button>
      </div>
      <div class="reopen-history-box">
        <span>上次丢单</span>
        <strong>{{ opportunity.lost_reason_code || lastLostEvent?.reason || '未记录原因' }}</strong>
        <small>{{ opportunity.lost_at ? formatDate(opportunity.lost_at) : (lastLostEvent?.entered_at ? formatDate(lastLostEvent.entered_at) : '—') }}</small>
      </div>
      <div class="closure-lost-form">
        <label><span>从哪个阶段重新开始 <b>*</b></span><select v-model="reopenStage"><option value="">请选择阶段</option><option value="discovery">初步接洽</option><option value="qualified">已确认需求</option><option value="solution">方案阶段</option><option value="quoted">已报价</option><option value="negotiating">谈判中</option></select></label>
        <label><span>重开原因 <b>*</b></span><textarea v-model="reopenReason" maxlength="1000" placeholder="例如：客户重新联系，预算已恢复，希望重新确认报价。"></textarea></label>
      </div>
      <div class="closure-modal-actions">
        <button type="button" class="customer-os-button" @click="closeReopen">取消</button>
        <button type="button" class="customer-os-button customer-os-button-primary" :disabled="!reopenStage || !reopenReason.trim()" @click="submitReopen">确认重开</button>
      </div>
    </section>
  </div>

  <div v-if="closureOpen" class="closure-modal-backdrop" @click.self="closeClosure">
    <section class="closure-modal" role="dialog" aria-modal="true" aria-label="标记商机最终结果">
      <div class="closure-modal-heading">
        <div><p class="sales-detail-kicker">FINAL RESULT</p><h3>标记商机最终结果</h3><p>Roadmap 最后一格将记录真实成交或丢单结果。</p></div>
        <button type="button" class="closure-modal-close" @click="closeClosure">×</button>
      </div>

      <div class="closure-choice-grid">
        <button type="button" class="closure-choice is-won" :class="{active:closureOutcome==='won'}" @click="closureOutcome='won'">
          <span class="closure-choice-icon">✓</span><strong>成交</strong><small>商机关闭为 Won</small>
        </button>
        <button type="button" class="closure-choice is-lost" :class="{active:closureOutcome==='lost'}" @click="closureOutcome='lost'">
          <span class="closure-choice-icon">×</span><strong>丢单</strong><small>商机关闭为 Lost</small>
        </button>
      </div>

      <div v-if="closureOutcome==='won'" class="closure-confirm-panel is-won">
        <strong>确认标记为成交</strong>
        <p>成交只代表销售结果，不会自动生成实收，也不会修改 Payment Receipt。</p>
      </div>

      <div v-else class="closure-lost-form">
        <label><span>丢单原因 <b>*</b></span><input v-model="lostReasonCode" maxlength="64" placeholder="例如：价格超预算 / 项目取消 / 选择其他供应商"></label>
        <label><span>补充说明</span><textarea v-model="lostReasonNote" maxlength="1000" placeholder="记录客户反馈、竞争情况或后续是否值得重新跟进…"></textarea></label>
      </div>

      <div class="closure-modal-actions">
        <button type="button" class="customer-os-button" @click="closeClosure">取消</button>
        <button type="button" class="customer-os-button customer-os-button-primary" :disabled="closureOutcome==='lost'&&!lostReasonCode.trim()" @click="submitClosure">
          {{ closureOutcome==='won'?'确认成交':'确认丢单' }}
        </button>
      </div>
    </section>
  </div>
</section>
</AuthenticatedLayout>
</template>

<style scoped>
.receivable-truth-card{display:grid;gap:14px}.receivable-status-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.receivable-status-grid>div{display:grid;gap:4px;padding:12px 14px;border:1px solid #e3e8e6;border-radius:10px;background:#fbfcfc}.receivable-status-grid span{font-size:11px;color:#71817b}.receivable-status-grid strong{font-size:18px}.receivable-meta,.receivable-other{margin:0;font-size:11px;color:#6f7d78}.receivable-other{display:flex;gap:10px;flex-wrap:wrap}.receivable-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px;border:1px solid #e1e8e5;border-radius:12px;background:#fbfcfc}.receivable-form label{display:grid;gap:6px}.receivable-form label>span{font-size:11px;font-weight:700;color:#5f6f69}.receivable-form input,.receivable-form select,.receivable-form textarea{width:100%;min-width:0}.receivable-note{grid-column:span 2}.receivable-submit{display:flex;align-items:end;justify-content:space-between;gap:12px;grid-column:1/-1}.receivable-submit p{margin:0;color:#7a8883;font-size:11px}
.payment-truth-card{display:grid;gap:16px}.payment-summary{display:flex;gap:12px;flex-wrap:wrap}.payment-summary-item{display:grid;gap:4px;min-width:190px;padding:13px 15px;border:1px solid #dce8e3;border-radius:12px;background:#f3faf7}.payment-summary-item span{font-size:11px;color:#567267}.payment-summary-item strong{font-size:22px;color:#126b4d}.payment-summary-item small{font-size:10px;color:#84958e}.payment-empty{padding:14px}.payment-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px;border:1px solid #e1e8e5;border-radius:12px;background:#fbfcfc}.payment-form label{display:grid;gap:6px}.payment-form label>span{font-size:11px;font-weight:700;color:#5f6f69}.payment-form input,.payment-form select,.payment-form textarea{width:100%;min-width:0}.payment-note{grid-column:span 2}.payment-submit{display:flex;align-items:end;justify-content:space-between;gap:12px;grid-column:1/-1}.payment-submit p{margin:0;color:#7a8883;font-size:11px}.payment-table-wrap{margin-top:2px}.payment-table td{vertical-align:top}.payment-table td small{display:block;margin-top:4px;color:#87948f;max-width:260px;white-space:normal}.payment-table a{color:#087f5b;font-weight:700}.payment-status{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:800}.payment-status.confirmed{background:#e7f7ef;color:#087f5b}.payment-status.voided{background:#f2f3f4;color:#879198}.payment-voided{opacity:.62}.payment-voided td:nth-child(3) strong{text-decoration:line-through}.payment-void-button{font-size:11px}
@media(max-width:900px){.payment-form{grid-template-columns:1fr 1fr}.payment-note{grid-column:1/-1}}@media(max-width:640px){.payment-form{grid-template-columns:1fr}.payment-note{grid-column:auto}.payment-submit{align-items:stretch;flex-direction:column}.payment-summary-item{width:100%}}

.sales-opportunity-detail{display:grid;gap:16px;padding-bottom:30px}
.opportunity-status-pill{display:inline-flex;align-items:center;min-height:24px;padding:0 9px;border-radius:999px;background:#eef4f1;color:#607168;font-size:11px;font-weight:800}
.opportunity-status-pill.is-won{background:#e6f6ec;color:#0b8a55}.opportunity-status-pill.is-lost{background:#fdecec;color:#b74646}
.opportunity-kpi-grid .sales-kpi-card strong{overflow:hidden;text-overflow:ellipsis}
.sales-detail-card{display:grid;gap:16px;padding:20px;border:1px solid var(--customer-border);border-radius:17px;background:#fff;box-shadow:0 8px 25px rgb(28 51 63 / .05)}
.sales-detail-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.sales-detail-heading h3{margin:0 0 4px;color:#14203a;font-size:19px;font-weight:800}.sales-detail-heading p{margin:0;color:#6d7b8d;font-size:12px;line-height:1.55}
.sales-detail-kicker{margin:0 0 7px!important;color:var(--customer-green)!important;font-size:10px!important;font-weight:800!important;letter-spacing:.12em}
.sales-stage-form{display:flex;align-items:center;gap:10px}.sales-stage-form select{min-width:210px;min-height:42px;padding:0 12px;border:1px solid #cdd8dd;border-radius:9px;background:#fff;color:#34475e;font:inherit}
.receivable-truth-card,.payment-truth-card{border-color:#d7e9e0;background:linear-gradient(180deg,#fff 0%,#fbfefc 100%)}
.receivable-status-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.receivable-status-grid>div{display:grid;gap:5px;min-height:92px;padding:15px;border:1px solid #dfe8e4;border-radius:13px;background:#f9fcfb}.receivable-status-grid>div:nth-child(3){border-color:#cfe7dc;background:#f1faf6}.receivable-status-grid span{font-size:11px;font-weight:700;color:#71817b}.receivable-status-grid strong{color:#17253f;font-size:20px;line-height:1.15}.receivable-status-grid>div:nth-child(3) strong{color:#087f5b}
.receivable-meta,.receivable-other{margin:0;padding:9px 11px;border-radius:9px;background:#f6f9f8;color:#6f7d78;font-size:11px}.receivable-other{display:flex;gap:10px;flex-wrap:wrap}
.receivable-form,.payment-form,.sales-detail-form-grid{display:grid;gap:12px;padding:15px;border:1px solid #e1e8e5;border-radius:13px;background:#fbfcfc}.receivable-form,.payment-form{grid-template-columns:repeat(3,minmax(0,1fr))}.sales-detail-form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
.receivable-form label,.payment-form label{display:grid;gap:6px}.receivable-form label>span,.payment-form label>span{font-size:11px;font-weight:750;color:#5f6f69}
.receivable-form input,.receivable-form select,.receivable-form textarea,.payment-form input,.payment-form select,.payment-form textarea,.sales-detail-form-grid input,.sales-detail-form-grid select,.sales-detail-form-grid textarea{width:100%;min-width:0;min-height:42px;padding:9px 11px;border:1px solid #cfd9d5;border-radius:9px;background:#fff;color:#34475e;font:inherit;font-size:13px;box-sizing:border-box}
.receivable-form textarea,.payment-form textarea,.sales-detail-form-grid textarea{min-height:82px;resize:vertical}.receivable-note,.payment-note{grid-column:span 2}
.receivable-submit,.payment-submit{display:flex;align-items:center;justify-content:space-between;gap:12px;grid-column:1/-1;padding-top:2px}.receivable-submit p,.payment-submit p{margin:0;color:#7a8883;font-size:11px}
.payment-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}.payment-summary-item{display:grid;gap:5px;padding:16px;border:1px solid #cfe7dc;border-radius:14px;background:#eef9f4}.payment-summary-item span{font-size:11px;font-weight:750;color:#567267}.payment-summary-item strong{font-size:25px;color:#087f5b}.payment-summary-item small{font-size:10px;color:#84958e}
.sales-detail-table-wrap{width:100%;overflow-x:auto;border:1px solid #e4eaed;border-radius:12px;background:#fff}.sales-detail-table-wrap .customer-os-table{min-width:780px}.payment-table-wrap .customer-os-table{min-width:1050px}
.payment-table-wrap td{vertical-align:top}.payment-table-wrap td small{display:block;margin-top:4px;color:#87948f;max-width:260px;white-space:normal}.payment-table-wrap a{color:#087f5b;font-weight:700}.payment-status{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:800}.payment-status.confirmed{background:#e7f7ef;color:#087f5b}.payment-status.voided{background:#f2f3f4;color:#879198}.payment-voided{opacity:.62}.payment-voided td:nth-child(3) strong{text-decoration:line-through}.payment-void-button{font-size:11px}
.sales-detail-two-column{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.sales-timeline{display:grid;gap:9px}.sales-timeline-item{padding:12px 14px;border:1px solid #e4ebe8;border-radius:11px;background:#fbfcfc}.sales-timeline-item strong{color:#263b32;font-size:12px}.sales-timeline-item p{margin:5px 0;color:#52645c;font-size:12px;line-height:1.5}.sales-timeline-item small{color:#91a09a;font-size:10px}.sales-detail-empty{padding:24px;border:1px dashed #dce5e1;border-radius:11px;background:#fbfcfc;text-align:center;color:#8a9993;font-size:12px}
@media(max-width:1280px){.opportunity-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.receivable-status-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:900px){.sales-detail-two-column{grid-template-columns:1fr}.receivable-form,.payment-form{grid-template-columns:1fr 1fr}.receivable-note,.payment-note{grid-column:1/-1}.sales-detail-form-grid{grid-template-columns:1fr}}
@media(max-width:800px){.sales-opportunity-detail .sales-canonical-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.opportunity-kpi-grid{display:flex;overflow-x:auto;scroll-snap-type:x mandatory}.opportunity-kpi-grid .sales-kpi-card{min-width:235px;scroll-snap-align:start}.sales-stage-form{align-items:stretch;flex-direction:column}.sales-stage-form select{width:100%}}
@media(max-width:640px){.receivable-status-grid,.receivable-form,.payment-form{grid-template-columns:1fr}.receivable-note,.payment-note{grid-column:auto}.receivable-submit,.payment-submit{align-items:stretch;flex-direction:column}.sales-opportunity-detail .sales-canonical-actions{grid-template-columns:1fr}.payment-summary-item{width:100%}}


.opportunity-roadmap-card{display:grid;gap:18px;padding:22px 24px;border:1px solid #dce7e3;border-radius:17px;background:#fff;box-shadow:0 8px 25px rgb(28 51 63 / .05)}
.roadmap-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.roadmap-heading h3{margin:0 0 5px;color:#14203a;font-size:20px}.roadmap-heading p{margin:0;color:#718079;font-size:12px}.roadmap-summary{display:flex;gap:18px;align-items:center;color:#718079;font-size:11px}.roadmap-summary strong{color:#24364d}
.roadmap-track{display:grid;grid-template-columns:repeat(6,minmax(135px,1fr));gap:0;overflow-x:auto;padding:4px 2px 2px}
.roadmap-node{position:relative;display:grid;justify-items:center;gap:8px;min-width:135px;padding:0 12px 8px;border:0;background:transparent;color:#52645d;cursor:pointer}
.roadmap-node::before{content:"";position:absolute;top:21px;left:-50%;width:100%;height:2px;background:#dfe7e4}.roadmap-node:first-child::before{display:none}.roadmap-node.is-completed::before,.roadmap-node.is-current::before{background:#42a985}
.roadmap-node.is-disabled{cursor:default}.roadmap-dot{position:relative;z-index:1;display:grid;place-items:center;width:44px;height:44px;border:2px solid #d4dfdb;border-radius:50%;background:#f5f8f7;color:#718079;font-size:14px;font-weight:850;box-shadow:0 0 0 5px #fff}
.roadmap-node.is-completed .roadmap-dot{border-color:#16966e;background:#16966e;color:#fff}.roadmap-node.is-current .roadmap-dot{border-color:#0c9067;background:#eaf8f2;color:#087f5b;box-shadow:0 0 0 5px #fff,0 0 0 7px #9ed5c2}.roadmap-node.is-skipped .roadmap-dot{border-style:dashed;background:#f7f8f8;color:#9aa6a1}.roadmap-node.is-skipped .roadmap-node-copy strong{color:#8b9893}.roadmap-node.is-selected .roadmap-node-copy strong{color:#087f5b}
.roadmap-node-copy{display:grid;justify-items:center;gap:3px;text-align:center}.roadmap-node-copy strong{color:#24364d;font-size:12px}.roadmap-node-copy small{color:#91a09a;font-size:9px}.roadmap-counts{display:flex;gap:10px;margin-top:3px}.roadmap-counts i{font-style:normal;color:#73847c;font-size:9px}
.roadmap-lifetime{display:grid;gap:10px;padding-top:15px;border-top:1px solid #e7ecea}.roadmap-lifetime-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}.roadmap-lifetime-heading>div{display:flex;align-items:center;gap:9px}.roadmap-lifetime-heading strong{color:#34475e;font-size:11px}.roadmap-lifetime-heading span,.roadmap-lifetime-heading small{color:#87958f;font-size:9px}.roadmap-lifetime-track{display:flex;gap:0;overflow-x:auto;padding:2px 0 4px}.roadmap-lifetime-event{position:relative;display:grid;grid-template-columns:16px minmax(118px,auto);gap:7px;min-width:155px;padding:5px 18px 5px 0}.roadmap-lifetime-event:not(:last-child)::after{content:"";position:absolute;top:12px;left:8px;right:-2px;height:1px;background:#dfe7e4}.roadmap-lifetime-dot{position:relative;z-index:1;width:9px;height:9px;margin-top:3px;border:2px solid #fff;border-radius:50%;background:#7bb39e;box-shadow:0 0 0 1px #a9cabc}.roadmap-lifetime-event>div{position:relative;z-index:1;display:grid;gap:2px;padding-right:4px;background:#fff}.roadmap-lifetime-event strong{color:#40534b;font-size:10px}.roadmap-lifetime-event small{color:#8a9892;font-size:8px}.roadmap-lifetime-event p{margin:2px 0 0;max-width:210px;color:#6e7d77;font-size:8px;line-height:1.35;white-space:normal}.roadmap-lifetime-event.is-lost .roadmap-lifetime-dot{background:#c95858;box-shadow:0 0 0 1px #e2aaaa}.roadmap-lifetime-event.is-lost strong{color:#a33c3c}.roadmap-lifetime-event.is-reopen .roadmap-lifetime-dot{background:#337eaa;box-shadow:0 0 0 1px #a9cbdf}.roadmap-lifetime-event.is-reopen strong{color:#286f99}.roadmap-lifetime-event.is-won .roadmap-lifetime-dot{background:#0b9167;box-shadow:0 0 0 1px #8fcbb6}
.roadmap-workspace{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:16px;align-items:start}.stage-action-column{display:grid;gap:16px}.stage-detail-card{min-width:0}
.stage-detail-header{display:grid;grid-template-columns:48px minmax(0,1fr) auto;gap:14px;align-items:center;padding-bottom:14px;border-bottom:1px solid #e7ecea}.stage-title-mark{display:grid;place-items:center;width:48px;height:48px;border-radius:50%;background:#e8f7f0;color:#087f5b;font-size:21px}.stage-title-line{display:flex;align-items:center;gap:9px}.stage-title-line h3{margin:0;color:#14203a;font-size:20px}.stage-detail-header p{margin:4px 0 0;color:#718079;font-size:11px}.stage-detail-header dl{display:flex;gap:20px;margin:0}.stage-detail-header dl div{display:grid;gap:3px}.stage-detail-header dt{color:#8b9893;font-size:9px}.stage-detail-header dd{margin:0;color:#34475e;font-size:11px;font-weight:750}.stage-current-pill,.stage-done-pill{display:inline-flex;padding:3px 8px;border-radius:999px;font-size:9px;font-weight:800}.stage-current-pill{background:#dff5eb;color:#087f5b}.stage-done-pill{background:#eef2f1;color:#63736d}
.stage-record-form{display:grid;gap:12px;padding:15px;border:1px solid #deebe5;border-radius:13px;background:#f8fcfa}.stage-record-form-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.stage-record-form label{display:grid;gap:5px}.stage-record-form label>span{color:#60716a;font-size:10px;font-weight:750}.stage-record-form input,.stage-record-form select,.stage-record-form textarea{width:100%;min-width:0;box-sizing:border-box;border:1px solid #ccd9d4;border-radius:9px;background:#fff;color:#34475e;font:inherit;font-size:12px;padding:9px 10px}.stage-record-form input,.stage-record-form select{min-height:40px}.stage-record-form textarea{min-height:86px;resize:vertical}.stage-record-summary{grid-column:1/-1}.stage-file-picker input{padding:7px}.stage-file-picker small{color:#88968f;font-size:9px}.stage-record-submit{display:flex;align-items:center;justify-content:space-between;gap:12px}.stage-record-submit>span{color:#819089;font-size:10px}
.stage-communication-timeline{display:grid;gap:0}.stage-communication-item{position:relative;display:grid;grid-template-columns:38px minmax(0,1fr);gap:12px;padding:15px 0}.stage-communication-item:not(:last-child)::after{content:"";position:absolute;left:18px;top:50px;bottom:-4px;width:1px;background:#dfe7e4}.stage-avatar{position:relative;z-index:1;display:grid;place-items:center;width:36px;height:36px;border:3px solid #fff;border-radius:50%;background:#e5f5ed;color:#087f5b;font-size:11px;font-weight:850;box-shadow:0 0 0 1px #d7e7e0}.stage-communication-body{min-width:0;padding-bottom:4px}.stage-communication-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.stage-communication-meta strong{color:#263b32;font-size:11px}.stage-communication-meta span{padding:2px 7px;border-radius:999px;background:#eef4f2;color:#64756e;font-size:9px}.stage-communication-meta time{margin-left:auto;color:#929f9a;font-size:9px}.stage-communication-body>p{margin:7px 0 0;color:#3e5149;font-size:12px;line-height:1.65}.stage-outcome{margin-top:7px;padding:7px 9px;border-left:3px solid #75bca3;background:#f2f9f6;color:#53675e;font-size:10px}
.stage-attachments{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.stage-attachment{display:flex;align-items:center;gap:8px;max-width:260px;min-height:58px;padding:7px;border:1px solid #dfe7e4;border-radius:9px;background:#fff;color:inherit;text-decoration:none}.stage-attachment.is-image{display:block;width:130px;padding:0;overflow:hidden}.stage-attachment img{display:block;width:130px;height:80px;object-fit:cover}.stage-file-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:7px;background:#eef4f2;color:#60736a;font-size:8px;font-weight:850}.stage-file-copy{display:grid;gap:2px;min-width:0}.stage-file-copy strong{overflow:hidden;color:#34475e;font-size:10px;text-overflow:ellipsis;white-space:nowrap}.stage-file-copy small{color:#919e99;font-size:9px}.stage-next-plan{display:grid;gap:3px;margin-top:10px;padding:9px 11px;border-radius:9px;background:#f6f8fa}.stage-next-plan strong{color:#087f5b;font-size:9px}.stage-next-plan span{color:#495d54;font-size:10px}.stage-next-plan small{color:#8d9a95;font-size:9px}
.stage-action-card,.stage-plan-card{padding:18px}.stage-action-current,.stage-next-action{display:grid;gap:5px;padding:12px;border-radius:11px;background:#f7faf9}.stage-action-current span,.stage-next-action span{color:#819089;font-size:9px}.stage-action-current strong,.stage-next-action strong{color:#263b32;font-size:15px}.stage-next-action{gap:9px;background:#f0faf6}.stage-adjust-form{display:grid;gap:8px;padding-top:12px;border-top:1px solid #e7ecea}.stage-adjust-form label{display:grid;gap:5px}.stage-adjust-form label span{color:#778780;font-size:9px}.stage-adjust-form select{width:100%;min-height:40px;padding:0 10px;border:1px solid #cfdbd6;border-radius:9px;background:#fff;color:#34475e}.stage-next-date{color:#087f5b;font-size:17px}.stage-plan-card>p{margin:0;color:#64756e;font-size:11px;line-height:1.55}
.quote-detail-card{margin-top:0}
.final-result-action{border:1px solid #cfe7dc;background:#edf9f4}.roadmap-node.is-final-action .roadmap-dot{border-color:#7cc5ab;background:#f0faf6;color:#087f5b}.roadmap-node.is-final-action:hover .roadmap-dot{background:#087f5b;color:#fff}.roadmap-node.is-final-action .roadmap-node-copy strong{color:#087f5b}
.closure-modal-backdrop{position:fixed;inset:0;z-index:1200;display:grid;place-items:center;padding:24px;background:rgb(17 31 27 / .42);backdrop-filter:blur(3px)}.closure-modal{width:min(560px,100%);display:grid;gap:18px;padding:22px;border:1px solid #d9e6e1;border-radius:18px;background:#fff;box-shadow:0 28px 80px rgb(16 42 34 / .24)}.closure-modal-heading{display:flex;justify-content:space-between;gap:16px}.closure-modal-heading h3{margin:0 0 5px;color:#14203a;font-size:21px}.closure-modal-heading p{margin:0;color:#718079;font-size:11px}.closure-modal-close{display:grid;place-items:center;width:34px;height:34px;border:1px solid #d8e1de;border-radius:50%;background:#fff;color:#6d7c76;font-size:22px;cursor:pointer}.closure-choice-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.closure-choice{display:grid;justify-items:center;gap:5px;padding:16px;border:1px solid #dfe7e4;border-radius:13px;background:#fbfcfc;color:#596b63;cursor:pointer}.closure-choice-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:50%;font-size:18px;font-weight:900}.closure-choice strong{font-size:15px}.closure-choice small{font-size:9px}.closure-choice.is-won .closure-choice-icon{background:#e8f7f0;color:#087f5b}.closure-choice.is-lost .closure-choice-icon{background:#fdeeee;color:#b84b4b}.closure-choice.is-won.active{border-color:#67b99a;background:#f0faf6;box-shadow:0 0 0 2px #d5eee4 inset}.closure-choice.is-lost.active{border-color:#d98c8c;background:#fff7f7;box-shadow:0 0 0 2px #f6dede inset}.closure-confirm-panel{display:grid;gap:5px;padding:12px 14px;border-radius:11px}.closure-confirm-panel.is-won{border:1px solid #d3eadf;background:#f3faf7}.closure-confirm-panel strong{color:#087f5b;font-size:12px}.closure-confirm-panel p{margin:0;color:#60736a;font-size:10px;line-height:1.5}.closure-lost-form{display:grid;gap:12px}.closure-lost-form label{display:grid;gap:5px}.closure-lost-form label span{color:#5f7069;font-size:10px;font-weight:750}.closure-lost-form label b{color:#b94a4a}.closure-lost-form input,.closure-lost-form textarea{width:100%;box-sizing:border-box;border:1px solid #cfd9d5;border-radius:9px;background:#fff;color:#34475e;font:inherit;font-size:12px;padding:9px 10px}.closure-lost-form input{min-height:42px}.closure-lost-form textarea{min-height:92px;resize:vertical}.closure-modal-actions{display:flex;justify-content:flex-end;gap:9px;padding-top:2px}.closure-lost-form select{width:100%;min-height:42px;padding:0 10px;border:1px solid #cfd9d5;border-radius:9px;background:#fff;color:#34475e;font:inherit;font-size:12px}
.lost-opportunity-banner{display:grid;grid-template-columns:54px minmax(0,1fr) auto;gap:16px;align-items:center;padding:17px 19px;border:1px solid #efcaca;border-radius:15px;background:linear-gradient(135deg,#fff7f7 0%,#fffafa 100%);box-shadow:0 8px 24px rgb(130 42 42 / .06)}.lost-opportunity-icon{display:grid;place-items:center;width:50px;height:50px;border-radius:50%;background:#f7dede;color:#b63f3f;font-size:27px;font-weight:850}.lost-opportunity-copy h3{margin:0 0 5px;color:#8f3030;font-size:19px}.lost-opportunity-copy p{margin:0;color:#704d4d;font-size:12px}.lost-opportunity-copy small{display:block;margin-top:5px;color:#9b7777;font-size:10px}.lost-opportunity-copy .sales-detail-kicker{color:#b64a4a!important}.lost-opportunity-actions{display:flex;align-items:center}.lost-opportunity-actions .customer-os-button-primary{background:#aa4141;border-color:#aa4141}.reopened-opportunity-banner{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:10px 14px;border:1px solid #d7e6df;border-left:4px solid #5baa8b;border-radius:10px;background:#f5faf8}.reopened-opportunity-banner>div{display:flex;gap:8px;align-items:center}.reopened-opportunity-banner strong{color:#28755a;font-size:11px}.reopened-opportunity-banner span,.reopened-opportunity-banner small{color:#72827b;font-size:10px}.reopen-history-box{display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:center;padding:11px 12px;border:1px solid #f0d6d6;border-radius:10px;background:#fff7f7}.reopen-history-box span{color:#9a6b6b;font-size:9px}.reopen-history-box strong{color:#8e3737;font-size:11px}.reopen-history-box small{color:#a48383;font-size:9px}
@media(max-width:1180px){.roadmap-workspace{grid-template-columns:1fr}.stage-action-column{grid-template-columns:repeat(2,minmax(0,1fr))}.stage-detail-header{grid-template-columns:48px 1fr}.stage-detail-header dl{grid-column:1/-1}}
@media(max-width:800px){.roadmap-track{grid-template-columns:repeat(6,145px);padding-bottom:8px}.roadmap-heading{flex-direction:column}.roadmap-summary{width:100%;justify-content:space-between}.stage-record-form-row{grid-template-columns:1fr}.stage-action-column{grid-template-columns:1fr}.stage-detail-header dl{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.stage-record-submit{align-items:stretch;flex-direction:column}.stage-record-submit .customer-os-button{width:100%}}
@media(max-width:560px){.opportunity-roadmap-card{padding:17px 14px}.stage-detail-header{grid-template-columns:40px 1fr}.stage-title-mark{width:40px;height:40px}.stage-detail-header dl{grid-template-columns:1fr 1fr}.stage-detail-header dl div:last-child{grid-column:1/-1}.stage-communication-meta time{width:100%;margin-left:0}.stage-attachment.is-image,.stage-attachment img{width:110px}}

</style>
