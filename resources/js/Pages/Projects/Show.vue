<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout.vue'

type Site = { site_key:string; domain:string; base_url:string; is_primary:boolean }
const props = defineProps<{ project:{ key:string; name:string; status:string }; sites:Site[] }>()

const primarySite = () => props.sites.find((site) => site.is_primary) ?? props.sites[0]
</script>

<template>
  <Head :title="`${project.name} · YBY ERP`" />
  <AuthenticatedLayout :title="'项目总览'" :breadcrumbs="['YBY ERP', project.name, '项目总览']">
    <section class="customer-os-page project-overview-page">
      <header class="project-overview-hero">
        <div class="project-overview-hero-copy">
          <p class="customer-os-kicker">PROJECT OS</p>
          <h2>{{ project.name }} · 项目总览</h2>
          <p>这里是项目的核心工作台，统一管理客户、询盘、销售与流量，助力业务持续增长。</p>
        </div>
        <div class="project-overview-actions">
          <Link class="customer-os-button" :href="`/projects/${project.key}/customers`">客户</Link>
          <Link class="customer-os-button customer-os-button-primary" :href="`/projects/${project.key}/inquiries`">询盘</Link>
        </div>
      </header>
      <div class="project-overview-kpis">
        <article class="sales-kpi-card"><span class="sales-kpi-icon">👥</span><div><span>客户 OS</span><strong>已接入</strong><small>项目客户与全局主数据联通</small></div></article>
        <article class="sales-kpi-card is-blue"><span class="sales-kpi-icon">▤</span><div><span>询盘 OS</span><strong>已接入</strong><small>来源、分配与跟进统一管理</small></div></article>
        <article class="sales-kpi-card is-green"><span class="sales-kpi-icon">▥</span><div><span>销售 OS</span><strong>已接入</strong><small>Opportunity 与报价流程已启用</small></div></article>
        <article class="sales-kpi-card is-amber"><span class="sales-kpi-icon">↗</span><div><span>流量 OS</span><strong>已接入</strong><small>SEO / Ads / GSC 数据入口</small></div></article>
        <article class="sales-kpi-card is-indigo"><span class="sales-kpi-icon">●</span><div><span>项目状态</span><strong>{{ project.status === 'active' ? '运行中' : project.status }}</strong><small>{{ sites.length }} 个已绑定网站</small></div></article>
      </div>

      <article class="project-overview-card">
        <div class="project-overview-card-heading">
          <span class="project-overview-section-icon">▥</span>
          <div><h3>项目概览</h3><p>快速了解项目核心信息、业务进展与关键入口。</p></div>
        </div>

        <div class="project-overview-grid">
          <section class="project-overview-panel">
            <h4>核心信息</h4>
            <div class="project-info-grid">
              <div><span>🌐</span><p><small>官网</small><strong>{{ primarySite()?.domain ?? '暂未绑定' }}</strong></p></div>
              <div><span>●</span><p><small>项目状态</small><strong>{{ project.status === 'active' ? 'Active' : project.status }}</strong></p></div>
              <div><span>◆</span><p><small>业务范围</small><strong>客户 / 询盘 / 销售 / 流量</strong></p></div>
              <div><span>⚑</span><p><small>当前阶段</small><strong>Growth OS</strong></p></div>
            </div>
          </section>
          <section class="project-overview-panel">
            <h4>业务漏斗</h4>
            <div class="project-funnel">
              <div class="funnel-row"><span class="funnel-bar w100"></span><strong>询盘</strong><em>待接实时指标</em></div>
              <div class="funnel-row"><span class="funnel-bar w75"></span><strong>已确认客户</strong><em>待接实时指标</em></div>
              <div class="funnel-row"><span class="funnel-bar w50"></span><strong>销售商机</strong><em>待接实时指标</em></div>
              <div class="funnel-row"><span class="funnel-bar w30"></span><strong>已成交</strong><em>待接实时指标</em></div>
            </div>
          </section>

          <section class="project-overview-panel">
            <h4>快捷入口</h4>
            <div class="project-quick-links">
              <Link :href="`/projects/${project.key}/inquiries`"><span>▤</span><strong>询盘中心</strong><small>管理和跟进询盘</small><i>→</i></Link>
              <Link :href="`/projects/${project.key}/customers`"><span>👥</span><strong>客户管理</strong><small>查看与维护客户</small><i>→</i></Link>
              <Link :href="`/projects/${project.key}/traffic`"><span>▥</span><strong>流量分析</strong><small>了解渠道效果</small><i>→</i></Link>
            </div>
          </section>
        </div>

        <section class="project-sites-strip">
          <div><h4>项目网站</h4><p>当前已绑定 {{ sites.length }} 个站点</p></div>
          <div class="project-site-links" v-if="sites.length">
            <a v-for="site in sites" :key="site.site_key" :href="site.base_url" target="_blank" rel="noreferrer">{{ site.domain }} <span>{{ site.is_primary ? '主站' : '关联站点' }}</span></a>
          </div>
          <p v-else class="project-overview-empty">该项目暂未绑定网站。</p>
        </section>
      </article>
    </section>
  </AuthenticatedLayout>
</template>