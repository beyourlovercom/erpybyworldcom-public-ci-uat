import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SalesIndex from './Index.vue'

const { router, form } = vi.hoisted(() => ({
  router: { get: vi.fn(), post: vi.fn() },
  form: (initial:any) => ({ ...initial, processing:false, data(){ return initial }, transform(){ return this }, post:vi.fn(), patch:vi.fn(), reset:vi.fn() }),
}))
vi.mock('@inertiajs/vue3', () => ({
  Head: { template:'<head><slot /></head>' },
  Link: { props:['href'], template:'<a :href="href"><slot /></a>' },
  router,
  useForm: form,
  usePage: () => ({ url:'/projects/yby-irrigation/sales', props:{auth:{user:{id:1,name:'Owner'}},notificationCenter:{unread_count:0,items:[]},shellNavigation:[]} }),
}))

const props = {
  project:{id:1,key:'yby-irrigation',name:'YBY Irrigation'},
  metrics:{open:0,quoted:0,won:0,lost:0,pipeline_value_micros:0},
  filters:{}, owners:[], companies:[], opportunities:[],
}

describe('Sales OS V1 workspace', () => {
  it('renders truthful empty pipeline without fake opportunity data', () => {
    const wrapper=mount(SalesIndex,{props})
    expect(wrapper.text()).toContain('YBY Irrigation')
    expect(wrapper.text()).toContain('暂无销售商机')
    expect(wrapper.text()).toContain('真实询盘')
    expect(wrapper.text()).toContain('Pipeline Value')
  })

  it('renders lost opportunities with a strong lost marker and reason', () => {
    const lostProps = {
      ...props,
      metrics:{...props.metrics,lost:1},
      opportunities:[{
        id:7,
        opportunity_number:'YBY-OPP-LOST-001',
        title:'Paused distributor opportunity',
        status:'lost',
        pipeline_stage:'lost',
        currency:'USD',
        expected_value_micros:250_000_000,
        probability_bps:0,
        expected_close_date:null,
        next_follow_up_at:null,
        last_follow_up_at:'2026-09-21T10:00:00+08:00',
        lost_at:'2026-09-22T10:00:00+08:00',
        lost_reason_code:'价格超预算',
        lost_reason_note:'客户暂停项目。',
        quotes_count:2,
        company:{id:8,name:'Lost Customer',country_code:'US',identity_status:'verified'},
        owner:{id:1,name:'Owner'},
      }],
    }
    const wrapper=mount(SalesIndex,{props:lostProps})
    expect(wrapper.text()).toContain('已丢单')
    expect(wrapper.text()).toContain('价格超预算')
    expect(wrapper.find('tr.sales-row-lost').exists()).toBe(true)
    expect(wrapper.find('.sales-lost-badge').text()).toBe('丢单')
  })
})
