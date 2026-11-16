<script>
import { router } from '@inertiajs/vue3';
import { date } from '@/Shared/format.js';

const STATUS = {
    draft: { variant: 'secondary', text: 'Draft' },
    submitted: { variant: 'info', text: 'With the Supply Unit' },
    booked: { variant: 'success', text: 'Booked' },
};

export default {
    props: {
        bookingRequests: { type: Object, required: true },
        summary: { type: Object, required: true },
        filters: { type: Object, required: true },
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
            return this.bookingRequests.data;
        },
        meta() {
            return this.bookingRequests.meta;
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
        date,

        applyFilters() {
            const params = Object.fromEntries(Object.entries({ search: this.search.trim(), status: this.status }).filter(([, v]) => v));
            router.get(route('booking-requests.index'), params, {
                preserveState: true, preserveScroll: true, replace: true, only: ['bookingRequests', 'filters'],
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
            router.visit(route('booking-requests.edit', row.id));
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
    <Head title="Booking Request" />

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div>
            <h1 class="h4 mb-1">Booking Request</h1>
            <p class="text-body-secondary mb-0">
                Request for Airline Booking for one traveller or a whole travel order — the Supply Unit's form, filled in.
            </p>
        </div>
        <Link :href="route('booking-requests.create')" class="btn btn-primary ms-auto">
            <i class="ri-add-line me-1"></i>New booking request
        </Link>
    </div>

    <div class="row g-3 mb-4">
        <div v-for="tile in [
            { key: 'draft', label: 'Draft', hint: 'not yet given to the Supply Unit' },
            { key: 'submitted', label: 'With the Supply Unit', hint: 'waiting for a booking reference' },
            { key: 'booked', label: 'Booked', hint: 'has a booking reference' },
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
                        <input v-model="search" type="search" class="form-control" placeholder="Search passenger, TO no., destination or purpose"
                               aria-label="Search booking requests">
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <select v-model="status" class="form-select form-select-sm" aria-label="Status" @change="applyFilters">
                        <option value="">All</option>
                        <option value="draft">Draft</option>
                        <option value="submitted">With the Supply Unit</option>
                        <option value="booked">Booked</option>
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
                        <th scope="col">Flight</th>
                        <th scope="col">Travel order</th>
                        <th scope="col">Passengers</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="list-row" @click="open(row)">
                        <td>
                            <div class="fw-semibold">{{ row.route }}</div>
                            <div class="small text-body-secondary">
                                {{ date(row.departure_date) }}<template v-if="row.return_date"> – {{ date(row.return_date) }}</template>
                                · {{ row.airline }}
                            </div>
                        </td>
                        <td class="text-nowrap num">{{ row.travel_order_no }}</td>
                        <td class="passengers-cell">
                            <div>{{ row.passengers.slice(0, 3).join(', ') }}</div>
                            <div v-if="row.passengers.length > 3" class="small text-body-secondary">and {{ row.passengers.length - 3 }} more</div>
                        </td>
                        <td class="text-nowrap">
                            <span :class="`badge text-bg-${badge(row).variant}`">{{ badge(row).text }}</span>
                            <div v-if="row.booking_reference" class="small text-body-secondary mt-1 num">{{ row.booking_reference }}</div>
                            <div v-else-if="row.submitted_on" class="small text-body-secondary mt-1">since {{ date(row.submitted_on) }}</div>
                            <div v-if="row.missing.length" class="small text-warning-emphasis" :title="row.missing.join('\n')">
                                <i class="ri-file-warning-line"></i> {{ row.missing.length }} required item{{ row.missing.length === 1 ? '' : 's' }} blank
                            </div>
                        </td>
                        <td class="text-end text-nowrap">
                            <a :href="route('booking-requests.download', row.id)" class="btn btn-sm btn-outline-secondary"
                               :aria-label="`Download the booking request for TO ${row.travel_order_no}`" title="Download the form" @click.stop>
                                <i class="ri-download-2-line"></i>
                            </a>
                        </td>
                    </tr>
                    <tr v-if="!rows.length">
                        <td colspan="5" class="text-center text-body-secondary py-5">
                            <i class="ri-plane-line fs-3 d-block mb-2"></i>
                            {{ hasFilters ? 'No booking requests match these filters.' : 'No booking requests yet. Start one from a travel order.' }}
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
                        <Link v-if="link.url" :href="link.url" class="page-link" preserve-scroll preserve-state :only="['bookingRequests', 'filters']">
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
.passengers-cell { min-width: 14rem; }
</style>
