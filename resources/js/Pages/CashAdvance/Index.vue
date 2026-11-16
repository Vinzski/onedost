<script>
import { router } from '@inertiajs/vue3';
import { peso, date, daysUntil } from '@/Shared/format.js';

// Deadlines closer than this many days are flagged.
const DUE_SOON_DAYS = 7;

export default {
    props: {
        cashAdvances: { type: Object, required: true },
        summary: { type: Object, required: true },
        filters: { type: Object, required: true },
        deadlineDays: { type: Number, required: true },
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
            return this.cashAdvances.data;
        },
        meta() {
            return this.cashAdvances.meta;
        },
        hasFilters() {
            return this.search !== '' || this.status !== '';
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

        applyFilters() {
            const params = Object.fromEntries(Object.entries({ search: this.search.trim(), status: this.status }).filter(([, v]) => v));
            router.get(route('cash-advances.index'), params, {
                preserveState: true, preserveScroll: true, replace: true, only: ['cashAdvances', 'filters'],
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
            router.visit(route('cash-advances.edit', row.id));
        },

        badge(row) {
            const left = daysUntil(row.deadline);
            switch (row.status) {
                case 'liquidated':
                    return { variant: 'success', text: 'Liquidated' };
                case 'liquidating':
                    return { variant: 'info', text: 'Liquidation started' };
                case 'upcoming':
                    return { variant: 'secondary', text: 'Trip not over yet' };
                case 'overdue':
                    return { variant: 'danger', text: `Liquidation overdue ${-left} day${left === -1 ? '' : 's'}` };
                default:
                    return {
                        variant: left <= DUE_SOON_DAYS ? 'warning' : 'secondary',
                        text: left === 0 ? 'Liquidate today' : `${left} day${left === 1 ? '' : 's'} to liquidate`,
                    };
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
    <Head title="Cash Advance" />

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Cash Advance</h1>
            <p class="text-body-secondary mb-0">
                Before the trip: Itinerary of Travel and No Pending Cash Advance. After it, one click starts the liquidation.
            </p>
        </div>
        <Link :href="route('cash-advances.create')" class="btn btn-primary ms-auto">
            <i class="ri-add-line me-1"></i>New cash advance
        </Link>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <button type="button" class="stat card h-100 w-100 text-start" @click="showStatus('pending')">
                <div class="card-body">
                    <div class="stat-label">Not yet liquidated</div>
                    <div class="stat-value num">{{ summary.pending }}</div>
                </div>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="stat card h-100 w-100 text-start" :class="{ 'stat-alert': summary.overdue }"
                    :title="`Not liquidated within ${deadlineDays} days of returning`" @click="showStatus('overdue')">
                <div class="card-body">
                    <div class="stat-label">Liquidation overdue</div>
                    <div class="stat-value num">{{ summary.overdue }}</div>
                    <div class="small text-body-secondary">past {{ deadlineDays }} days after return</div>
                </div>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <button type="button" class="stat card h-100 w-100 text-start" @click="showStatus('liquidated')">
                <div class="card-body">
                    <div class="stat-label">Liquidated</div>
                    <div class="stat-value num">{{ summary.liquidated }}</div>
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
                               aria-label="Search cash advances">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select v-model="status" class="form-select form-select-sm" aria-label="Status" @change="applyFilters">
                        <option value="">All</option>
                        <option value="pending">Not yet liquidated</option>
                        <option value="overdue">Liquidation overdue</option>
                        <option value="liquidated">Liquidated</option>
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
                        <th scope="col" class="text-end">Cash advance</th>
                        <th scope="col">Liquidation</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="list-row" @click="open(row)">
                        <td>
                            <div class="fw-semibold">{{ row.traveler_name }}</div>
                            <div class="small text-body-secondary">{{ row.position }}</div>
                        </td>
                        <td class="text-nowrap num">{{ row.travel_order_no }}</td>
                        <td class="trip-cell">
                            <div class="text-nowrap">{{ row.travel_dates }}</div>
                            <div class="small text-body-secondary">{{ row.venue }}</div>
                        </td>
                        <td class="text-end text-nowrap">
                            <div class="num">{{ peso(row.amount) }}</div>
                            <div class="small text-body-secondary">requested {{ date(row.requested_on) }}</div>
                        </td>
                        <td class="text-nowrap">
                            <span :class="`badge text-bg-${badge(row).variant}`">{{ badge(row).text }}</span>
                            <div v-if="row.status !== 'liquidated'" class="small text-body-secondary mt-1 num">by {{ date(row.deadline) }}</div>
                            <div v-if="row.missing_papers.length" class="small text-warning-emphasis" :title="`Missing: ${row.missing_papers.join(', ')}`">
                                <i class="ri-file-warning-line"></i> {{ row.missing_papers.length }} paper{{ row.missing_papers.length === 1 ? '' : 's' }} missing
                            </div>
                        </td>
                        <td class="text-end text-nowrap">
                            <Link v-if="row.liquidation_id" :href="route('liquidations.edit', row.liquidation_id)" class="btn btn-sm btn-outline-success me-1"
                                  :aria-label="`Open the liquidation for ${row.traveler_name}`" title="Open liquidation" @click.stop>
                                <i class="ri-suitcase-line"></i>
                            </Link>
                            <a :href="route('cash-advances.download', [row.id, 'all'])" class="btn btn-sm btn-outline-secondary"
                               :aria-label="`Download the cash advance papers for ${row.traveler_name}`" title="Download IOT and certification" @click.stop>
                                <i class="ri-download-2-line"></i>
                            </a>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="6" class="text-center text-body-secondary py-5">
                            <i class="ri-hand-coin-line fs-3 d-block mb-2"></i>
                            {{ hasFilters ? 'No cash advances match these filters.' : 'No cash advances yet. Start one before your next trip.' }}
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
                        <Link v-if="link.url" :href="link.url" class="page-link" preserve-scroll preserve-state :only="['cashAdvances', 'filters']">
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
