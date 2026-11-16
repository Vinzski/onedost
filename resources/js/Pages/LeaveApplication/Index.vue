<script>
import { router } from '@inertiajs/vue3';
import { date } from '@/Shared/format.js';
import { daysApplied } from '@/Components/LeaveApplication/leave.js';

const STATUS = {
    draft: { variant: 'secondary', text: 'Draft' },
    submitted: { variant: 'info', text: 'Submitted' },
    approved: { variant: 'success', text: 'Approved' },
    disapproved: { variant: 'danger', text: 'Disapproved' },
};

export default {
    props: {
        leaveApplications: { type: Object, required: true },
        summary: { type: Object, required: true },
        filters: { type: Object, required: true },
        types: { type: Array, required: true },
    },

    data() {
        return {
            search: this.filters.search ?? '',
            status: this.filters.status ?? '',
            type: this.filters.type ?? '',
            searchTimer: null,
        };
    },

    computed: {
        rows() {
            return this.leaveApplications.data;
        },
        meta() {
            return this.leaveApplications.meta;
        },
        hasFilters() {
            return this.search !== '' || this.status !== '' || this.type !== '';
        },
    },

    watch: {
        search() {
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => this.applyFilters(), 300);
        },
    },

    beforeUnmount() {
        clearTimeout(this.searchTimer);
    },

    methods: {
        date,
        daysApplied,

        applyFilters() {
            const params = Object.fromEntries(Object.entries({ search: this.search.trim(), status: this.status, type: this.type }).filter(([, v]) => v));
            router.get(route('leave-applications.index'), params, {
                preserveState: true, preserveScroll: true, replace: true, only: ['leaveApplications', 'filters'],
            });
        },

        showStatus(status) {
            this.status = status;
            this.applyFilters();
        },

        clearFilters() {
            clearTimeout(this.searchTimer);
            this.search = '';
            this.status = '';
            this.type = '';
            this.applyFilters();
        },

        open(row) {
            router.visit(route('leave-applications.edit', row.id));
        },

        badge(row) {
            return STATUS[row.status] ?? STATUS.draft;
        },

        pageLabel(link, index) {
            if (index === 0) return '‹';
            if (index === this.meta.links.length - 1) return '›';
            return link.label;
        },
    },
};
</script>

<template>
    <Head title="Leave Application" />

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Leave Application</h1>
            <p class="text-body-secondary mb-0">
                Application for Leave (CSC Form No. 6) — fill it in once, print it ready to sign.
            </p>
        </div>
        <Link :href="route('leave-applications.create')" class="btn btn-primary ms-auto">
            <i class="ri-add-line me-1"></i>New leave application
        </Link>
    </div>

    <div class="row g-3 mb-4">
        <div v-for="tile in [
            { key: 'draft', label: 'Draft', hint: 'not yet submitted' },
            { key: 'submitted', label: 'Submitted', hint: 'awaiting a decision' },
            { key: 'approved', label: 'Approved', hint: 'leave granted' },
        ]" :key="tile.key" class="col-6 col-lg-3">
            <button type="button" class="stat card h-100 w-100 text-start" :class="{ 'stat-active': status === tile.key }" @click="showStatus(tile.key)">
                <div class="card-body">
                    <div class="stat-label">{{ tile.label }}</div>
                    <div class="stat-value num">{{ summary[tile.key] }}</div>
                    <div class="small text-body-secondary">{{ tile.hint }}</div>
                </div>
            </button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-center">
                <div class="col-12 col-md-5">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="ri-search-line"></i></span>
                        <input v-model="search" type="search" class="form-control" placeholder="Search name, position or details"
                               aria-label="Search leave applications">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select v-model="type" class="form-select form-select-sm" aria-label="Type of leave" @change="applyFilters">
                        <option value="">Any leave</option>
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select v-model="status" class="form-select form-select-sm" aria-label="Status" @change="applyFilters">
                        <option value="">All</option>
                        <option value="draft">Draft</option>
                        <option value="submitted">Submitted</option>
                        <option value="approved">Approved</option>
                        <option value="disapproved">Disapproved</option>
                    </select>
                </div>
                <div v-if="hasFilters" class="col-auto">
                    <button type="button" class="btn btn-sm btn-link px-0" @click="clearFilters">Clear filters</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 list-table">
                <thead>
                    <tr>
                        <th scope="col">Employee</th>
                        <th scope="col">Leave</th>
                        <th scope="col">Inclusive dates</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="list-row" @click="open(row)">
                        <td>
                            <div class="fw-semibold">{{ row.full_name }}</div>
                            <div class="small text-body-secondary">{{ row.position }}</div>
                        </td>
                        <td>
                            <div>{{ row.type }}</div>
                            <div class="small text-body-secondary">
                                <span class="num">{{ daysApplied(row.days).toLowerCase() }}</span><template v-if="row.detail"> · {{ row.detail }}</template>
                            </div>
                        </td>
                        <td class="text-nowrap">
                            <div>{{ row.inclusive_dates }}</div>
                            <div class="small text-body-secondary">filed {{ date(row.filed_on) }}</div>
                        </td>
                        <td class="text-nowrap">
                            <span :class="`badge text-bg-${badge(row).variant}`">{{ badge(row).text }}</span>
                            <div v-if="row.submitted_on" class="small text-body-secondary mt-1">submitted {{ date(row.submitted_on) }}</div>
                        </td>
                        <td class="text-end text-nowrap">
                            <a :href="route('leave-applications.download', row.id)" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"
                               :aria-label="`Open the leave form of ${row.full_name}`" title="Open the form to print" @click.stop>
                                <i class="ri-printer-line"></i>
                            </a>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="5" class="text-center text-body-secondary py-5">
                            <i class="ri-calendar-check-line fs-3 d-block mb-2"></i>
                            {{ hasFilters ? 'No leave applications match these filters.' : 'No leave applications yet.' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="meta.last_page > 1" class="card-footer d-flex align-items-center small">
            <span class="text-body-secondary">
                Showing <span class="num">{{ meta.from }}–{{ meta.to }}</span> of <span class="num">{{ meta.total }}</span>
            </span>
            <nav class="ms-auto" aria-label="Pages">
                <ul class="pagination pagination-sm mb-0">
                    <li v-for="(link, i) in meta.links" :key="i" class="page-item" :class="{ active: link.active, disabled: !link.url }">
                        <Link v-if="link.url" :href="link.url" class="page-link" preserve-scroll preserve-state :only="['leaveApplications', 'filters']">
                            {{ pageLabel(link, i) }}
                        </Link>
                        <span v-else class="page-link">{{ pageLabel(link, i) }}</span>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</template>

<style scoped>
.stat { color: var(--ink); cursor: pointer; transition: border-color .12s ease; }
.stat:hover, .stat-active { border-color: var(--brand); }
.stat-label {
    font-size: .6875rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--ink-soft);
}
.stat-value { font-size: 1.75rem; font-weight: 700; line-height: 1.2; }

.list-table th {
    font-size: .6875rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--ink-soft);
    background: var(--surface-sunken);
    white-space: nowrap;
}
.list-row { cursor: pointer; }
</style>
