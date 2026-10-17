import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ProjectCustomerIndex from './Index.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { post: vi.fn(), visit: vi.fn(), get: vi.fn(), delete: vi.fn() },
  usePage: () => ({ url:'/projects/yby-irrigation/customers', props:{
    auth:{ user:{ id:1, name:'YBY Owner', email:'owner@example.test' } },
    notificationCenter:{ unread_count:0, items:[] }, shellNavigation:[], flash:{},
  } }),
}))

const props = {
  project:{ key:'yby-irrigation', name:'YBY Irrigation', emoji:'🌱' },
  metrics:{ companies:1, customers:0, prospects:1, verified:1, unresolved_inquiries:7 },
  relationships:[{
    id:1, company:{ id:9, name:'Acme Irrigation', country_code:'KE', identity_status:'verified', scale_band:'medium', primary_contact:'Amina' },
    owner:{ id:1, name:'Terry' }, location:{ id:1, name:'Nairobi' }, relationship_status:'prospect',
    market_route:'Kenya', inquiry_count:2, last_source_channel:'whatsapp',
    last_inquiry_at:'2026-09-16T10:00:00+00:00', last_follow_up_at:null,
    received_totals:[{ currency:'USD', amount_micros:250_000_000, receipt_count:2 }],
  }],
  pendingInquiries:[{
    id:7, case_number:'INQ-20260917-007', title:'Website inquiry', contact_name:'John', company_name_snapshot:'Green Farm',
    country_snapshot:'Kenya', source_channel:'website', source_site_key:'ybyirrigation', handling_state:'waiting',
    identity_resolution_state:'unresolved', last_inbound_at:'2026-09-17T02:00:00+00:00', company:null, owner:null,
  }],
  owners:[{ id:1, name:'Terry', email:'terry@example.test' }], companies:[{ id:9, name:'Acme Irrigation' }], canOperate:true,
}

describe('Customer OS Canonical VI project view', () => {
  it('defaults to the pending inquiry workbench and keeps canonical KPI labels', () => {
    const wrapper = mount(ProjectCustomerIndex, { props })
    expect(wrapper.text()).toContain('YBY Irrigation · 客户')
    expect(wrapper.text()).toContain('客户公司')
    expect(wrapper.text()).toContain('正式客户')
    expect(wrapper.text()).toContain('潜在客户')
    expect(wrapper.text()).toContain('已验证')
    expect(wrapper.text()).toContain('待确认询盘')
    expect(wrapper.text()).toContain('INQ-20260917-007')
    expect(wrapper.text()).toContain('Green Farm')
    expect(wrapper.find('.customer-os-tabs button.is-active').text()).toContain('待确认询盘')
    expect(wrapper.find('.customer-os-tab-badge').text()).toBe('7')
  })

  it('switches to customer truth without promoting pending inquiries', async () => {
    const wrapper = mount(ProjectCustomerIndex, { props })
    const prospectTab = wrapper.findAll('.customer-os-tabs button').find((button) => button.text().includes('潜在客户'))
    expect(prospectTab).toBeTruthy()
    await prospectTab!.trigger('click')
    expect(wrapper.text()).toContain('Acme Irrigation')
    expect(wrapper.text()).toContain('Amina')
    expect(wrapper.text()).not.toContain('INQ-20260917-007')
  })

  it('is parameterized for other business projects', () => {
    const wrapper = mount(ProjectCustomerIndex, { props:{ ...props, project:{ key:'yby-bottle', name:'YBY Bottle', emoji:'🧴' }, metrics:{ ...props.metrics, unresolved_inquiries:0 }, pendingInquiries:[] } })
    expect(wrapper.text()).toContain('YBY Bottle · 客户')
    expect(wrapper.find('a[href="/projects/yby-bottle/inquiries"]').exists()).toBe(true)
  })
})
