<script>
import { router } from '@inertiajs/vue3';
import { peso, date, daysUntil } from '@/Shared/format.js';

// Deadlines closer than this many days are flagged.
const DUE_SOON_DAYS = 7;

export default {
    props: {
        liquidations: { type: Object, required: true },
        summary: { type: Object, required: true },
        filters: { type: Object, required: true },
        deadlineDays: { type: Number, required: true },
        // 'liquidation' or 'reimbursement' — same list; a reimbursement has no cash advance or deadline.
        kind: { type: String, default: 'liquidation' },
        routePrefix: { type: String, default: 'liquidations' },
    },

    data() {
        return {
            search: this.filters.search ?? '',
            status: this.filters.status ?? '',
            searchTimer: null,
        };
    },

    computed: {
        rows() {
            return this.liquidations.data;
        },
        meta() {
            return this.liquidations.meta;
        },
        hasFilters() {
            return this.search !== '' || this.status !== '';
        },
        isReimbursement() {
            return this.kind === 'reimbursement';
        },
        noun() {
            return this.isReimbursement ? 'reimbursement' : 'liquidation';
        },
        title() {
            return this.isReimbursement ? 'Reimbursement' : 'Travel Liquidation';
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
        peso,
        date,

        /** This kind's route: to('edit', id) → route('reimbursements.edit', id). */
        to(name, params) {
            return route(`${this.routePrefix}.${name}`, params);
        },

        applyFilters() {
            const params = Object.fromEntries(Object.entries({ search: this.search.trim(), status: this.status }).filter(([, v]) => v));
            router.get(this.to('index'), params, {
                preserveState: true, preserveScroll: true, replace: true, only: ['liquidations', 'filters'],
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
            this.applyFilters();
        },

        open(row) {
            router.visit(this.to('edit', row.id));
        },

        deadlineBadge(row) {
            switch (row.status) {
                case 'submitted':
                    return { variant: 'success', text: `Submitted ${date(row.submitted_on)}` };
                case 'travelling':
                    return { variant: 'info', text: 'Trip not over yet' };
                case 'pending':
                    return { variant: 'warning', text: 'Not yet submitted' };
                case 'overdue': {
                    const late = -daysUntil(row.deadline);
                    return { variant: 'danger', text: `Overdue ${late} day${late === 1 ? '' : 's'}` };
                }
                default: {
                    const left = daysUntil(row.deadline);
                    return {
                        variant: left <= DUE_SOON_DAYS ? 'warning' : 'secondary',
                        text: left === 0 ? 'Due today' : `${left} day${left === 1 ? '' : 's'} left`,
                    };
                }
            }
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
    <Head :title="title" />

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">{{ title }}</h1>
            <p class="text-body-secondary mb-0">
                <template v-if="isReimbursement">Claim back what you spent on a trip without a cash advance — every form filled in and totalled.</template>
                <template v-else>Enter a trip once and download every form it needs, filled in and totalled.</template>
            </p>
        </div>
        <Link :href="to('create')" class="btn btn-primary ms-auto">
            <i class="ri-add-line me-1"></i>New {{ noun }}
        </Link>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <button type="button" class="stat card h-100 w-100 text-start" @click="showStatus('pending')">
                <div class="card-body">
                    <div class="stat-label">Not yet submitted</div>
                    <div class="stat-value num">{{ summary.pending }}</div>
                </div>
            </button>
        </div>
        <div v-if="isReimbursement" class="col-6 col-lg-3">
            <div class="stat card h-100">
                <div class="card-body">
                    <div class="stat-label">To be reimbursed</div>
                    <div class="stat-value num">{{ peso(summary.amount_pending) }}</div>
                    <div class="small text-body-secondary">not yet submitted</div>
                </div>
            </div>
        </div>
        <div v-else class="col-6 col-lg-3">
            <button type="button" class="stat card h-100 w-100 text-start" :class="{ 'stat-alert': summary.overdue }"
                    :title="`Not submitted within ${deadlineDays} days of returning`" @click="showStatus('overdue')">
                <div class="card-body">
                    <div class="stat-label">Overdue</div>
                    <div class="stat-value num">{{ summary.overdue }}</div>
                    <div class="small text-body-secondary">past {{ deadlineDays }} days after return</div>
                </div>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="stat card h-100 w-100 text-start" @click="showStatus('submitted')">
                <div class="card-body">
                    <div class="stat-label">Submitted</div>
                    <div class="stat-value num">{{ summary.submitted }}</div>
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
                        <input v-model="search" type="search" class="form-control" placeholder="Search traveller, TO no., purpose or venue"
                               :aria-label="`Search ${noun}s`">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select v-model="status" class="form-select form-select-sm" aria-label="Status" @change="applyFilters">
                        <option value="">All</option>
                        <option value="pending">Not yet submitted</option>
                        <option v-if="!isReimbursement" value="overdue">Overdue</option>
                        <option value="submitted">Submitted</option>
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
                        <th scope="col">Traveller</th>
                        <th scope="col">Travel order</th>
                        <th scope="col">Trip</th>
                        <th scope="col" class="text-end">{{ isReimbursement ? 'To reimburse' : 'Spent' }}</th>
                        <template v-if="!isReimbursement">
                            <th scope="col" class="text-end">Cash advance</th>
                            <th scope="col" class="text-end">Balance</th>
                        </template>
                        <th scope="col">{{ isReimbursement ? 'Status' : 'Liquidate by' }}</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="list-row" @click="open(row)">
                        <td>
                            <div class="fw-semibold">{{ row.traveler_name || 'Traveller not set' }}</div>
                            <div class="small text-body-secondary">{{ row.position }}</div>
                        </td>
                        <td class="text-nowrap num">{{ row.travel_order_no }}</td>
                        <td class="trip-cell">
                            <div class="text-nowrap">{{ row.travel_dates }}</div>
                            <div class="small text-body-secondary">{{ row.venue }}</div>
                        </td>
                        <td class="text-end text-nowrap num">{{ peso(row.total_spent) }}</td>
                        <template v-if="!isReimbursement">
                            <td class="text-end text-nowrap num">{{ peso(row.cash_advance) }}</td>
                            <td class="text-end text-nowrap num">
                                <span v-if="row.refund > 0" class="text-danger" title="To refund">−{{ peso(row.refund) }}</span>
                                <span v-else-if="row.reimbursement > 0" class="text-success" title="To be reimbursed">+{{ peso(row.reimbursement) }}</span>
                                <span v-else class="text-body-secondary">—</span>
                            </td>
                        </template>
                        <td class="text-nowrap">
                            <div v-if="!isReimbursement" class="num">{{ date(row.deadline) }}</div>
                            <span :class="`badge text-bg-${deadlineBadge(row).variant}`">{{ deadlineBadge(row).text }}</span>
                            <template v-if="row.status !== 'submitted'">
                                <div v-if="row.missing_papers.length" class="small text-warning-emphasis mt-1"
                                     :title="`Missing: ${row.missing_papers.join(', ')}`">
                                    <i class="ri-file-warning-line"></i>
                                    {{ row.missing_papers.length }} paper{{ row.missing_papers.length === 1 ? '' : 's' }} missing
                                </div>
                                <div v-else class="small text-success mt-1"><i class="ri-checkbox-circle-line"></i> All papers ready</div>
                            </template>
                        </td>
                        <td class="text-end text-nowrap">
                            <a :href="to('download', [row.id, 'all'])" class="btn btn-sm btn-outline-secondary"
                               :aria-label="`Download all forms for ${row.traveler_name}`" title="Download all forms" @click.stop>
                                <i class="ri-download-2-line"></i>
                            </a>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td :colspan="isReimbursement ? 6 : 8" class="text-center text-body-secondary py-5">
                            <i class="ri-suitcase-line fs-3 d-block mb-2"></i>
                            {{ hasFilters ? `No ${noun}s match these filters.` : `No ${noun}s yet. Start one after your next trip.` }}
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
                        <Link v-if="link.url" :href="link.url" class="page-link" preserve-scroll preserve-state :only="['liquidations', 'filters']">
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
.stat:hover { border-color: var(--brand); }
.stat-alert { border-color: var(--bs-danger); }
.stat-alert .stat-value { color: var(--bs-danger); }
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
.trip-cell { min-width: 12rem; }
</style>
