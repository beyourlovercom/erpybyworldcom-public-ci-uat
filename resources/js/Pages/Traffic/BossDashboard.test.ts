import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import BossDashboard from './BossDashboard.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn(), post: vi.fn() },
  usePage: () => ({ url: '/marketing/traffic', props: {
    auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } },
    notificationCenter: { unread_count: 0, items: [] },
    shellNavigation: [{ key:'marketing', label:'营销', path:'/marketing', route:'shell.marketing', placeholder:false, children:[{ key:'marketing-traffic', label:'流量总览', path:'/marketing/traffic', route:'traffic.boss-dashboard', placeholder:false, children:[] }] }],
  } }),
}))

const props: any = {
  project: { key:'yby-irrigation', name:'YBY Irrigation' }, filters: { date_from:'2026-09-12', date_to:'2026-09-13' },
  metrics_contract:{ version:'boss-growth-v1', qualified_semantics:'current_case_stage_for_inbound_cohort', won_value_is_revenue:false, received_is_revenue:false, received_state:'available_from_confirmed_payment_receipts', revenue_state:'recognition_not_defined' },
  summary:{ currency_code:'CNY', mixed_currency:false, impressions:702, clicks:109, spend:'880.68', provider_conversions:27, inquiries:3, total_inquiries:4, qualified_inquiries:2, opportunities:2, quoted:1, won:1, won_value:'100.00', won_currency_code:'USD', received_totals:[{currency:'USD',amount_micros:50_000_000,amount:'50.00',receipt_count:2}], received_micros:50_000_000, received:'50.00', received_currency_code:'USD', revenue:null, cost_per_inquiry:'293.56', ctr_percent:15.53, cpc:'8.08' },
  funnel:[{key:'traffic',label:'流量',count:109,conversion_rate:null},{key:'inquiry',label:'询盘',count:3,conversion_rate:2.75},{key:'qualified',label:'有效询盘',count:2,conversion_rate:66.67},{key:'opportunity',label:'商机',count:2,conversion_rate:100},{key:'quoted',label:'已报价',count:1,conversion_rate:50},{key:'won',label:'成交',count:1,conversion_rate:100}],
  attribution_quality:{resolved:1,partial:1,unresolved:1,total:3,captured:2,missing:1},
  trend:[{ date:'2026-09-12', impressions:300, clicks:50, spend:'400.00', provider_conversions:12, inquiries:1, qualified_inquiries:1, won:0, received_totals:[{currency:'USD',amount_micros:30_000_000,amount:'30.00',receipt_count:1}] },{ date:'2026-09-13', impressions:402, clicks:59, spend:'480.68', provider_conversions:15, inquiries:2, qualified_inquiries:1, won:1, received_totals:[{currency:'USD',amount_micros:20_000_000,amount:'20.00',receipt_count:1}] }],
  weekly_comparison:[{week_start:'2026-09-07',week_end:'2026-09-13',period_start:'2026-09-12',period_end:'2026-09-13',is_partial:true,clicks:109,inquiries:3,spend:'880.68',won:1,received_totals:[{currency:'USD',amount_micros:50_000_000,amount:'50.00',receipt_count:2}]}],
  time_comparison:{version:'boss-time-comparison-v2',compare_mode:'previous',requested_granularity:'auto',granularity:'day',current_range:{date_from:'2026-09-12',date_to:'2026-09-13',effective_from:'2026-09-12',effective_to:'2026-09-13'},compare_range:{date_from:'2026-09-10',date_to:'2026-09-11',effective_from:null,effective_to:null},summary:{current:{clicks:109,inquiries:3,cost_micros:880684249,spend:'880.68',won:1,received_totals:[{currency:'USD',amount_micros:50_000_000,amount:'50.00',receipt_count:2}]},compare:{clicks:0,inquiries:0,cost_micros:0,spend:'0.00',won:0,received_totals:[]}},series:{current:[{bucket_start:'2026-09-12',bucket_end:'2026-09-12',period_start:'2026-09-12',period_end:'2026-09-12',is_partial:false,clicks:50,inquiries:1,cost_micros:400000000,spend:'400.00',won:0,received_totals:[{currency:'USD',amount_micros:30_000_000,amount:'30.00',receipt_count:1}]},{bucket_start:'2026-09-13',bucket_end:'2026-09-13',period_start:'2026-09-13',period_end:'2026-09-13',is_partial:false,clicks:59,inquiries:2,cost_micros:480684249,spend:'480.68',won:1,received_totals:[{currency:'USD',amount_micros:20_000_000,amount:'20.00',receipt_count:1}]}],compare:[]}},
  campaigns:[{ id:1, external_campaign_id:'24023703280', name:'YBY-Search-IRR-SOLUTION-Tanzania', status:'enabled', advertising_channel_type:'SEARCH', impressions:702, clicks:109, spend:'880.68', provider_conversions:27, inquiries:2, opportunities:2, won:1, won_value:'100.00', won_currency_code:'USD' }],
  sources:[{ source_type:'google_ads', inquiries:2, opportunities:2, won:1, won_value:'100.00', won_currency_code:'USD' },{ source_type:'unattributed', inquiries:1, opportunities:0, won:0, won_value:'0.00', won_currency_code:null }],
  landing_pages:[{id:1,name:'Irrigation Wholesale',path:'/lp/irrigation-wholesale/',canonical_url:'https://ybyirrigation.com/lp/irrigation-wholesale/',inquiries:1,opportunities:1,won:1,won_value:'100.00',won_currency_code:'USD'}],
  drilldown:{dimension:'campaign',value:'1',label:'YBY-Search-IRR-SOLUTION-Tanzania',total:2,rows:[{case_id:2,case_number:'GROWTH-2',company_name:'Growth UAT Co',case_stage:'qualified',first_inbound_at:'2026-09-13T09:00:00Z',source_kind:'google_ads',attribution_state:'partial',opportunity_id:2,opportunity_number:'GROWTH-OPP-2',opportunity_status:'open',won_at:null,sales_url:'/projects/yby-irrigation/sales/opportunities/2'},{case_id:1,case_number:'GROWTH-1',company_name:'Growth UAT Co',case_stage:'qualified',first_inbound_at:'2026-09-12T09:00:00Z',source_kind:'google_ads',attribution_state:'resolved',opportunity_id:1,opportunity_number:'GROWTH-OPP-1',opportunity_status:'won',won_at:'2026-09-13T11:00:00Z',sales_url:'/projects/yby-irrigation/sales/opportunities/1'}]},
  data_health:{ traffic_rows:2, provider_inquiry_rows:3, canonical_case_rows:3, data_from:'2026-09-12', data_to:'2026-09-13', account_last_synced_at:'2026-09-14T08:00:00.000000Z', sync_mode:'manual', automatic_sync_enabled:false, qualification_state:'canonical_case_current_stage', attribution_scope:'canonical_case_attribution' },
}

describe('Traffic Boss Dashboard', () => {
  it('renders boss KPIs, campaign evidence, inquiry sources and qualification boundary', () => {
    const wrapper = mount(BossDashboard, { props })
    expect(wrapper.text()).toContain('¥880.68')
    expect(wrapper.text()).toContain('109')
    expect(wrapper.text()).toContain('新增询盘')
    expect(wrapper.text()).toContain('商机')
    expect(wrapper.text()).toContain('US$100.00')
    expect(wrapper.text()).toContain('上一周期')
    expect(wrapper.text()).toContain('按 天 展示核心指标数据')
    expect(wrapper.text()).toContain('YBY-Search-IRR-SOLUTION-Tanzania')
    expect(wrapper.text()).toContain('归因可信度')
    expect(wrapper.text()).toContain('Irrigation Wholesale')
    expect(wrapper.text()).toContain('Google Ads')
    expect(wrapper.text()).toContain('数据趋势对比')
    expect(wrapper.text()).toContain('时间数据明细')
    expect(wrapper.text()).toContain('对比上一周期')
    expect(wrapper.text()).toContain('广告开支')
    expect(wrapper.text()).toContain('成交数量')
    expect(wrapper.text()).toContain('实收')
    expect(wrapper.text()).toContain('US$50.00')
    expect(wrapper.text()).toContain('实收只来自已确认回款')
    expect(wrapper.text()).toContain('营收确认')
    expect(wrapper.text()).toContain('未定义')
    const links = wrapper.findAll('a')
    expect(links.some(link => link.attributes('href') === '/projects/yby-irrigation/inquiries')).toBe(true)
    expect(links.some(link => link.attributes('href') === '/projects/yby-irrigation/sales')).toBe(true)
    expect(links.some(link => String(link.attributes('href')).includes('/projects/yby-irrigation/sales/receipts?date_from=2026-09-12&date_to=2026-09-13'))).toBe(true)
    expect(links.some(link => link.attributes('href') === '/projects/yby-irrigation/traffic?date_from=2026-09-12&date_to=2026-09-13')).toBe(true)
    expect(wrapper.text()).toContain('GROWTH-2')
    expect(wrapper.text()).toContain('销售详情')
    expect(wrapper.text()).toContain('当前尚未启用 Google Ads Production 自动同步')
  })
})
