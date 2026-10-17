import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SeoWorkspace from './Index.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn() },
  usePage: () => ({ url: '/projects/acme/traffic/seo', props: {
    auth: { user: { id: 1, name: 'Owner', email: 'owner@example.test' } },
    notificationCenter: { unread_count: 0, items: [] },
    shellNavigation: [],
  } }),
}))

const props: any = {
  project: { key: 'acme', name: 'Acme Growth' },
  filters: { search: '', action: '', source: '' },
  summary: { keywords: 7981, evidence_rows: 4622, priority_opportunities: 210, gsc_live_rows: 21487, content_backlog: 275 },
  action_counts: { NEW_CONTENT_P0:33, PAGE1_P0:63, TOP3_CTR_P0:114 },
  evidence_counts: { google_ads:120, google_search_console:997, alibaba:1486 },
  actions: ['NEW_CONTENT_P0','PAGE1_P0','TOP3_CTR_P0'],
  sources: ['google_ads','google_search_console','alibaba'],
  keywords: {
    data: [{ id:1, keyword:'drip irrigation system', topic_cluster:'Drip Irrigation System', suggested_action:'PAGE1_P0', suggested_target_type:'Product/Category Page', opportunity_score:'80.00', ads:{ clicks:758, conversions:10, cost:1584.9 }, gsc:{ impressions:1172, clicks:3, average_position:37.19 }, alibaba:{ impressions:38862, clicks:905 } }],
    current_page:1, last_page:1, total:1, prev_page_url:null, next_page_url:null,
  },
  data_health: { seed_loaded:true, gsc_live_connected:true, attribution_state:'live_query_page' },
  growth: {
    version:'SEO7-GROWTH-V1',
    scope:{ mode:'english_production_growth', hosts:['ybyirrigation.com','www.yby-irrigation.com'], rule:'exact host only' },
    summary:{ actionable:8, existing_page:5, new_content:3, p0:4, monitoring:32, retest_due:0, winner:0, loser:0, mixed:0, international_planned:63, published_pages_filtered:32, low_evidence_filtered:26, migration_content_planned:65 },
    queue:[
      { key:'page:1', kind:'existing_page', priority:'P0', decision:'push_page1', keyword:'canvas hose', topic_cluster:'Other', suggested_action:'TOP3_CTR_P0', suggested_target_type:'Review', target_url:'https://www.yby-irrigation.com/info/lay-flat-hose-vs-canvas-hose.html', opportunity_score:34.31, impressions:150, clicks:0, ctr:0, average_position:14.87, keyword_count:2, latest_metric_date:'2026-09-14', rationale:'scoped evidence' },
      { key:'content:167', kind:'new_content', priority:'P0', decision:'new_commercial_page', keyword:'solar water pump for irrigation', topic_cluster:'Solar Irrigation / Pump', suggested_action:'NEW_CONTENT_P0', suggested_target_type:'Product/Category Page', target_url:null, opportunity_score:28.12, impressions:18, clicks:0, ctr:0, average_position:41.22, keyword_count:1, latest_metric_date:'2026-09-14', rationale:'NEW_CONTENT_P0' },
      { key:'page:2', kind:'existing_page', priority:'P1', decision:'push_page1', keyword:'mulch film', topic_cluster:'Other', suggested_action:'OPTIMIZE_P2', suggested_target_type:'Review', target_url:'https://www.yby-irrigation.com/pipes-pipe-fittings/agricultural-mulch-film.html', opportunity_score:23.85, impressions:46, clicks:0, ctr:0, average_position:17.76, keyword_count:1, latest_metric_date:'2026-09-14', rationale:'scoped evidence' },
    ],
    monitoring:[{
      publication_id:1, work_order_id:2, keyword:'drip tape', topic_cluster:'Drip Tape',
      baseline_url:'https://www.yby-irrigation.com/drip-irrigation-system/precision-irrigation-drip-tape.html',
      published_url:'https://ybyirrigation.com/drip-irrigation-system/precision-irrigation-drip-tape.html',
      production_post_id:762, publish_date:'2026-09-18', retest_after:'2026-10-16', days_remaining:27, owner_uat:'pass', state:'monitoring',
      baseline:{ impressions:624, clicks:1, average_position:5.37 },
      t0:{ metric_from:'2026-09-18', metric_to:'2026-09-18', impressions:0, clicks:0, ctr:0, average_position:null, distinct_queries:0, competing_pages:0 },
      t28:null,
      signal:{ position_delta:null, impressions_daily_change:null, clicks_daily_change:null },
    }],
    data_health:{ gsc_live_connected:true, scoped_gsc_rows:7163, scoped_gsc_keywords:1382, scoped_impressions:10277, latest_metric_date:'2026-09-14', international_gsc_rows_excluded:14324, international_impressions_excluded:20577, raw_keyword_count:7981, consolidated_new_content_truth:true },
    international:{ planned:63, top:[] },
  },
}

describe('SEO Growth Engine workspace', () => {
  it('renders quality-gated growth queue and makes top KPIs navigate and filter', async () => {
    const scrollIntoView = vi.fn()
    ;(Element.prototype as any).scrollIntoView = scrollIntoView
    const wrapper = mount(SeoWorkspace, { props, attachTo: document.body })
    expect(wrapper.text()).toContain('Acme Growth')
    expect(wrapper.text()).toContain('SEO Growth Engine')
    expect(wrapper.text()).toContain('canvas hose')
    expect(wrapper.text()).toContain('solar water pump for irrigation')
    expect(wrapper.text()).toContain('drip tape')
    expect(wrapper.text()).toContain('Query × Page')
    expect(wrapper.text()).toContain('ybyirrigation.com')
    expect(wrapper.text()).toContain('www.yby-irrigation.com')
    const cards = wrapper.findAll('.growth-kpi')
    expect(cards).toHaveLength(6)
    expect(wrapper.findAll('.growth-queue tbody tr')).toHaveLength(3)
    expect(wrapper.findAll('.monitoring-card tbody tr')).toHaveLength(1)
    expect(wrapper.find('#isolated-queues').exists()).toBe(true)

    await cards[1].trigger('click')
    expect(wrapper.findAll('.growth-queue tbody tr')).toHaveLength(2)
    await vi.waitFor(() => expect(scrollIntoView).toHaveBeenCalledTimes(1))

    await cards[0].trigger('click')
    expect(wrapper.findAll('.growth-queue tbody tr')).toHaveLength(3)
    await vi.waitFor(() => expect(scrollIntoView).toHaveBeenCalledTimes(2))

    await cards[2].trigger('click')
    expect(wrapper.find('#monitoring-queue').exists()).toBe(true)
    await vi.waitFor(() => expect(scrollIntoView).toHaveBeenCalledTimes(3))
  })
})
