import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TrafficSectionNav from './TrafficSectionNav.vue'

vi.mock('@inertiajs/vue3', () => ({
  Link: {
    props: ['href'],
    template: '<a :href="href"><slot /></a>',
  },
  usePage: () => ({ url: '/projects/yby-irrigation/traffic/seo?date_from=2026-09-01' }),
}))

describe('TrafficSectionNav', () => {
  it('renders Traffic OS destinations without Boss Dashboard and highlights SEO', () => {
    const wrapper = mount(TrafficSectionNav, { props: { projectKey: 'yby-irrigation' } })
    const links = wrapper.findAll('a')

    expect(links).toHaveLength(4)
    expect(links.map(link => link.attributes('href'))).toEqual([
      '/projects/yby-irrigation/traffic',
      '/projects/yby-irrigation/traffic/ga4',
      '/projects/yby-irrigation/traffic/landing-pages',
      '/projects/yby-irrigation/traffic/seo',
    ])
    expect(wrapper.text()).toContain('总览')
    expect(wrapper.text()).toContain('GA4')
    expect(wrapper.text()).toContain('Landing Pages')
    expect(wrapper.text()).toContain('SEO Growth')
    expect(links[3].classes()).toContain('is-active')
    expect(links[3].attributes('aria-current')).toBe('page')
  })
})
