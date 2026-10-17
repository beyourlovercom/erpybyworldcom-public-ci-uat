import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import InquiryIndex from './Index.vue'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<head><slot /></head>' },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  router: { get: vi.fn(), post },
  usePage: () => ({ url:'/projects/yby-irrigation/inquiries', props:{
    auth:{ user:{ id:1, name:'YBY Owner', email:'owner@example.test' } },
    notificationCenter:{ unread_count:0, items:[] },
    shellNavigation:[],
  } }),
}))

const props = {
  project:{ key:'yby-irrigation', name:'YBY Irrigation' },
  filters:{ status:'', stage:'', source:'' },
  summary:{ total:7, open:7, waiting:7, unresolved_identity:7 },
  owners:[{ id:1, name:'YBY Owner', email:'owner@example.test' }],
  companies:[],
  cases:[{
    id:10, case_number:'YBY-IRR-260827-ABC123', title:'Website inquiry / Buyer',
    contact_name:'Buyer', contact_email:'buyer@example.test', contact_phone:null,
    company_name_snapshot:'Example Irrigation', country_snapshot:'TZ', buyer_type:'wholesaler',
    product_interest:'Drip irrigation', quantity:'1000', source_channel:'website', source_site_key:'ybyirrigation-com',
    case_status:'open', case_stage:'new', handling_state:'waiting', identity_resolution_state:'unresolved',
    first_inbound_at:'2026-08-27T01:46:09+00:00', last_inbound_at:'2026-08-27T01:46:09+00:00',
    company:null, owner:null,
  }],
}

describe('Inquiry OS M2', () => {
  it('renders canonical cases, source truth and assignment controls', () => {
    const wrapper = mount(InquiryIndex, { props })
    expect(wrapper.text()).toContain('YBY Irrigation')
    expect(wrapper.text()).toContain('YBY-IRR-260827-ABC123')
    expect(wrapper.text()).toContain('ybyirrigation-com')
    expect(wrapper.text()).toContain('Drip irrigation')
    expect(wrapper.text()).toContain('Customer OS')
  })
})