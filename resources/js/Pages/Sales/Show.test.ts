import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SalesShow from './Show.vue'

const { router, form } = vi.hoisted(() => ({
  router: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
  form: (initial:any) => ({
    ...initial,
    processing:false,
    data(){ return initial },
    transform(){ return this },
    post:vi.fn(),
    patch:vi.fn(),
    reset:vi.fn(),
  }),
}))

vi.mock('@inertiajs/vue3', () => ({
  Head: { props:['title'], template:'<head><slot /></head>' },
  Link: { props:['href'], template:'<a :href="href"><slot /></a>' },
  router,
  useForm: form,
  usePage: () => ({
    url:'/projects/yby-irrigation/sales/opportunities/1',
    props:{auth:{user:{id:1,name:'Owner'}},notificationCenter:{unread_count:0,items:[]},shellNavigation:[]},
  }),
}))

const props = {
  project:{key:'yby-irrigation',name:'YBY Irrigation'},
  opportunity:{
    id:1,
    opportunity_number:'YBY-OPP-260920-ABC123',
    title:'Kenya distributor opportunity',
    status:'won',
    pipeline_stage:'won',
    currency:'USD',
    expected_value_micros:1_000_000_000,
    probability_bps:10000,
    company:{id:1,name:'Acme Irrigation'},
    owner:{id:1,name:'Owner'},
    quotes:[],
    follow_ups:[],
    lost_reason_code:null,
    lost_reason_note:null,
  },
  payment_receipts:[
    {
      id:7,
      receipt_number:'YBY-PAY-260920-ABC123',
      amount_micros:300_000_000,
      currency:'USD',
      received_at:'2026-09-20T10:30:00+08:00',
      payment_method:'alipay',
      entry_source:'manual',
      provider_reference:'ALI-001',
      evidence_reference:'sales-group-confirmation.png',
      note:'30% deposit',
      status:'confirmed' as const,
      recorded_by:{id:1,name:'Owner'},
      voided_at:null,
      void_reason:null,
      voided_by:null,
    },
  ],
  payment_totals:[{currency:'USD',amount_micros:300_000_000,receipt_count:1}],
  receivable:{
    status:'partial' as const,
    amount_micros:1_000_000_000,
    currency:'USD',
    received_micros:300_000_000,
    outstanding_micros:700_000_000,
    overpaid_micros:0,
    source:'manual' as const,
    quote_id:null,
    quote_number:null,
    confirmed_at:'2026-09-20T10:00:00+08:00',
    confirmed_by:{id:1,name:'Owner'},
    note:'Final amount confirmed',
    other_currency_totals:[],
  },
  roadmap:{
    current_stage:'won',
    current_label:'成交',
    next_stage:null,
    next_label:null,
    closed:true,
    total_elapsed_days:4,
    nodes:[
      {key:'discovery',label:'初步接洽',state:'completed' as const,entered_at:'2026-09-17T09:00:00+08:00',exited_at:'2026-09-18T09:00:00+08:00',duration_days:1,note_count:1,attachment_count:0},
      {key:'qualified',label:'已确认需求',state:'completed' as const,entered_at:'2026-09-18T09:00:00+08:00',exited_at:'2026-09-19T09:00:00+08:00',duration_days:1,note_count:2,attachment_count:1},
      {key:'solution',label:'方案阶段',state:'completed' as const,entered_at:'2026-09-19T09:00:00+08:00',exited_at:'2026-09-20T09:00:00+08:00',duration_days:1,note_count:1,attachment_count:0},
      {key:'quoted',label:'已报价',state:'completed' as const,entered_at:'2026-09-20T09:00:00+08:00',exited_at:'2026-09-20T15:00:00+08:00',duration_days:0,note_count:0,attachment_count:0},
      {key:'negotiating',label:'谈判中',state:'completed' as const,entered_at:'2026-09-20T15:00:00+08:00',exited_at:'2026-09-21T09:00:00+08:00',duration_days:0,note_count:1,attachment_count:0},
      {key:'won',label:'成交',state:'current' as const,entered_at:'2026-09-21T09:00:00+08:00',exited_at:null,duration_days:0,note_count:0,attachment_count:0},
    ],
    events:[],
  },
}

describe('Sales Revenue Truth payment receipt UI', () => {
  it('renders manual receipt entry, confirmed cash total and audit history separately from quote truth', () => {
    const wrapper=mount(SalesShow,{props})
    expect(wrapper.text()).toContain('实际回款')
    expect(wrapper.text()).toContain('只有员工确认钱已到账后才登记')
    expect(wrapper.text()).toContain('累计实收 · USD')
    expect(wrapper.text()).toContain('$300.00')
    expect(wrapper.text()).toContain('支付宝')
    expect(wrapper.text()).toContain('YBY-PAY-260920-ABC123')
    expect(wrapper.text()).toContain('确认到账')
    expect(wrapper.text()).toContain('应收与收款状态')
    expect(wrapper.text()).toContain('部分收款')
    expect(wrapper.text()).toContain('$1,000.00')
    expect(wrapper.text()).toContain('$700.00')
    expect(wrapper.text()).toContain('确认应收')
    expect(wrapper.text()).toContain('作废')
    expect(wrapper.text()).toContain('报价金额仍是销售事实')
    expect(wrapper.text()).toContain('商机推进路线图')
    expect(wrapper.text()).toContain('初步接洽')
    expect(wrapper.text()).toContain('已确认需求')
    expect(wrapper.text()).toContain('方案阶段')
    expect(wrapper.text()).toContain('谈判中')
    expect(wrapper.text()).toContain('阶段操作')
    expect(wrapper.text()).not.toContain('Won / Lost 必须通过独立关闭动作完成。')
  })


  it('shows a strong lost state and a reopen workflow without erasing history', async () => {
    const lostProps = {
      ...props,
      opportunity:{
        ...props.opportunity,
        status:'lost',
        pipeline_stage:'lost',
        probability_bps:0,
        lost_at:'2026-09-22T06:00:00+08:00',
        lost_reason_code:'价格超预算',
        lost_reason_note:'客户暂停项目。',
      },
      roadmap:{
        ...props.roadmap,
        current_stage:'lost',
        current_label:'丢单',
        closed:true,
        nodes:[
          ...props.roadmap.nodes.slice(0,5),
          {key:'lost',label:'丢单',state:'current' as const,entered_at:'2026-09-22T06:00:00+08:00',exited_at:null,duration_days:0,note_count:0,attachment_count:0},
        ],
        events:[
          {id:1,from_stage:'negotiating',to_stage:'lost',to_label:'丢单',entered_at:'2026-09-22T06:00:00+08:00',source:'close',reason:'价格超预算 · 客户暂停项目。',actor:{id:1,name:'Owner'}},
        ],
      },
    }
    const wrapper=mount(SalesShow,{props:lostProps})
    expect(wrapper.text()).toContain('此商机已丢单')
    expect(wrapper.text()).toContain('价格超预算')
    expect(wrapper.text()).toContain('重新联系 / 重开商机')
    await wrapper.get('.lost-opportunity-actions button').trigger('click')
    expect(wrapper.text()).toContain('重新联系 / 重开商机')
    expect(wrapper.text()).toContain('从哪个阶段重新开始')
    expect(wrapper.text()).toContain('重开原因')
    expect(wrapper.text()).toContain('不会删除之前的丢单记录')
  })


  it('keeps the full lifetime history visible after reopening to an earlier stage', () => {
    const reopenedProps = {
      ...props,
      opportunity:{
        ...props.opportunity,
        status:'open',
        pipeline_stage:'qualified',
        probability_bps:3000,
      },
      roadmap:{
        ...props.roadmap,
        current_stage:'qualified',
        current_label:'已确认需求',
        closed:false,
        nodes:[
          {key:'discovery',label:'初步接洽',state:'completed' as const,entered_at:'2026-09-17T09:00:00+08:00',exited_at:'2026-09-18T09:00:00+08:00',duration_days:1,note_count:1,attachment_count:0},
          {key:'qualified',label:'已确认需求',state:'current' as const,entered_at:'2026-09-23T09:00:00+08:00',exited_at:null,duration_days:0,note_count:2,attachment_count:1},
          {key:'solution',label:'方案阶段',state:'completed' as const,entered_at:'2026-09-19T09:00:00+08:00',exited_at:'2026-09-20T09:00:00+08:00',duration_days:1,note_count:1,attachment_count:0},
          {key:'quoted',label:'已报价',state:'completed' as const,entered_at:'2026-09-20T09:00:00+08:00',exited_at:'2026-09-20T15:00:00+08:00',duration_days:0,note_count:1,attachment_count:0},
          {key:'negotiating',label:'谈判中',state:'completed' as const,entered_at:'2026-09-20T15:00:00+08:00',exited_at:'2026-09-22T06:00:00+08:00',duration_days:1,note_count:1,attachment_count:0},
          {key:'final',label:'成交 / 丢单',state:'pending' as const,entered_at:null,exited_at:null,duration_days:null,note_count:0,attachment_count:0},
        ],
        events:[
          {id:1,from_stage:null,to_stage:'discovery',to_label:'初步接洽',entered_at:'2026-09-17T09:00:00+08:00',source:'create',reason:'opportunity_created',actor:{id:1,name:'Owner'}},
          {id:2,from_stage:'discovery',to_stage:'qualified',to_label:'已确认需求',entered_at:'2026-09-18T09:00:00+08:00',source:'manual',reason:'pipeline_stage_updated',actor:{id:1,name:'Owner'}},
          {id:3,from_stage:'qualified',to_stage:'solution',to_label:'方案阶段',entered_at:'2026-09-19T09:00:00+08:00',source:'manual',reason:'pipeline_stage_updated',actor:{id:1,name:'Owner'}},
          {id:4,from_stage:'solution',to_stage:'quoted',to_label:'已报价',entered_at:'2026-09-20T09:00:00+08:00',source:'quote',reason:'quote_created',actor:{id:1,name:'Owner'}},
          {id:5,from_stage:'quoted',to_stage:'negotiating',to_label:'谈判中',entered_at:'2026-09-20T15:00:00+08:00',source:'manual',reason:'pipeline_stage_updated',actor:{id:1,name:'Owner'}},
          {id:6,from_stage:'negotiating',to_stage:'lost',to_label:'丢单',entered_at:'2026-09-22T06:00:00+08:00',source:'close',reason:'价格超预算 · 客户暂停项目。',actor:{id:1,name:'Owner'}},
          {id:7,from_stage:'lost',to_stage:'qualified',to_label:'已确认需求',entered_at:'2026-09-23T09:00:00+08:00',source:'reopen',reason:'客户恢复预算重新联系。',actor:{id:1,name:'Owner'}},
        ],
      },
    }
    const wrapper=mount(SalesShow,{props:reopenedProps})
    expect(wrapper.text()).toContain('曾丢单后重开')
    expect(wrapper.text()).toContain('商机生命周期')
    expect(wrapper.text()).toContain('历史只叠加，不会因重开而清空')
    expect(wrapper.text()).toContain('丢单 1 次 · 重开 1 次')
    expect(wrapper.text()).toContain('价格超预算')
    expect(wrapper.text()).toContain('客户恢复预算重新联系')
    expect(wrapper.findAll('.roadmap-node.is-completed').some(node=>node.text().includes('谈判中'))).toBe(true)
  })

  it('opens the final result dialog from negotiating stage instead of ending the roadmap silently', async () => {
    const negotiatingProps = {
      ...props,
      opportunity:{
        ...props.opportunity,
        status:'open',
        pipeline_stage:'negotiating',
        probability_bps:8500,
      },
      roadmap:{
        ...props.roadmap,
        current_stage:'negotiating',
        current_label:'谈判中',
        closed:false,
        nodes:[
          {key:'discovery',label:'初步接洽',state:'completed' as const,entered_at:'2026-09-17T09:00:00+08:00',exited_at:'2026-09-18T09:00:00+08:00',duration_days:1,note_count:1,attachment_count:0},
          {key:'qualified',label:'已确认需求',state:'completed' as const,entered_at:'2026-09-18T09:00:00+08:00',exited_at:'2026-09-19T09:00:00+08:00',duration_days:1,note_count:2,attachment_count:1},
          {key:'solution',label:'方案阶段',state:'completed' as const,entered_at:'2026-09-19T09:00:00+08:00',exited_at:'2026-09-20T09:00:00+08:00',duration_days:1,note_count:1,attachment_count:0},
          {key:'quoted',label:'已报价',state:'completed' as const,entered_at:'2026-09-20T09:00:00+08:00',exited_at:'2026-09-20T15:00:00+08:00',duration_days:0,note_count:0,attachment_count:0},
          {key:'negotiating',label:'谈判中',state:'current' as const,entered_at:'2026-09-20T15:00:00+08:00',exited_at:null,duration_days:1,note_count:1,attachment_count:0},
          {key:'final',label:'成交 / 丢单',state:'pending' as const,entered_at:null,exited_at:null,duration_days:null,note_count:0,attachment_count:0},
        ],
      },
    }
    const wrapper=mount(SalesShow,{props:negotiatingProps})
    expect(wrapper.text()).toContain('进入最终结果')
    const finalNode=wrapper.findAll('button.roadmap-node').find(button=>button.text().includes('成交 / 丢单'))
    expect(finalNode).toBeTruthy()
    await finalNode!.trigger('click')
    expect(wrapper.text()).toContain('标记商机最终结果')
    expect(wrapper.text()).toContain('成交')
    expect(wrapper.text()).toContain('丢单')
    expect(wrapper.text()).toContain('成交只代表销售结果，不会自动生成实收')
  })
})
