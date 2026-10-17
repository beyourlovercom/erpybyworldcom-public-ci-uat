<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { ref } from 'vue'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout.vue'
import CustomerOsDataPolicy from '../../../Components/CustomerOS/CustomerOsDataPolicy.vue'
import CustomerOsEmptyAction from '../../../Components/CustomerOS/CustomerOsEmptyAction.vue'
import CustomerOsHeader from '../../../Components/CustomerOS/CustomerOsHeader.vue'
import CustomerOsKpiRow from '../../../Components/CustomerOS/CustomerOsKpiRow.vue'
import CustomerOsTable from '../../../Components/CustomerOS/CustomerOsTable.vue'
import CustomerOsTabs from '../../../Components/CustomerOS/CustomerOsTabs.vue'

type TabKey = 'all'|'customers'|'pending'|'verified'|'prospects'
type ReceivedTotal = { currency:string; amount_micros:number; receipt_count:number }
type Relationship = { id:number; company:{ id:number; name:string; country_code:string|null; identity_status:string; scale_band:string|null; primary_contact:string|null }; owner:{id:number;name:string}|null; location:{id:number;name:string}|null; relationship_status:string; market_route:string|null; inquiry_count:number; last_source_channel:string|null; last_inquiry_at:string|null; last_follow_up_at:string|null; received_totals:ReceivedTotal[] }
type Inquiry = { id:number; case_number:string; title:string; contact_name:string|null; company_name_snapshot:string|null; country_snapshot:string|null; source_channel:string; source_site_key:string|null; handling_state:string; identity_resolution_state:string; last_inbound_at:string|null; company:{id:number;name:string}|null; owner:{id:number;name:string}|null }

const props = defineProps<{
  project:{ key:string; name:string; emoji:string; icon_url?:string|null }
  metrics:{ companies:number; customers:number; prospects:number; verified:number; unresolved_inquiries:number }
  relationships:Relationship[]
  pendingInquiries:Inquiry[]
  owners:{ id:number; name:string; email?:string }[]
  companies:{ id:number; name:string }[]
  canOperate:boolean
}>()
const activeTab = ref<TabKey>('pending')
</script>

<template>
  <Head :title="`${project.name} · 客户 · YBY ERP`" />
  <AuthenticatedLayout title="客户" :breadcrumbs="['YBY ERP', project.name, '客户']" canonical-topbar>
    <section class="customer-os-page customer-os-unified-page customer-os-project-index">
      <CustomerOsHeader :project="project" />
      <CustomerOsKpiRow :metrics="metrics" />
      <div class="customer-os-filter-bar">
        <CustomerOsTabs :active="activeTab" :pending-count="metrics.unresolved_inquiries" @change="activeTab = $event" />
        <CustomerOsDataPolicy />
      </div>
      <CustomerOsTable
        :project-key="project.key"
        :active-tab="activeTab"
        :pending-inquiries="pendingInquiries"
        :relationships="relationships"
        :owners="owners"
        :companies="companies"
        :can-operate="canOperate"
      />
      <CustomerOsEmptyAction v-if="metrics.companies === 0" :project-key="project.key" :pending-count="metrics.unresolved_inquiries" />
    </section>
  </AuthenticatedLayout>
</template>
