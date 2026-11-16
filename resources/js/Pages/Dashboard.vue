<script>
import { peso } from '@/Shared/format.js';

export default {
    props: {
        bookingRequests: { type: Object, required: true },
        cashAdvances: { type: Object, required: true },
        liquidations: { type: Object, required: true },
        reimbursements: { type: Object, required: true },
        leaveApplications: { type: Object, required: true },
        dtr: { type: Object, required: true },
    },

    computed: {
        dtrLink() {
            return this.dtr.month
                ? route('dtr.index', { person: this.dtr.person_id, month: this.dtr.month })
                : route('dtr.index');
        },
    },

    methods: { peso },
};
</script>

<template>
    <Head title="Dashboard" />

    <h1 class="h4 mb-1">OneDOST</h1>
    <p class="text-body-secondary mb-4">DOST Region IX.</p>

    <div class="row g-3">
        <div class="col-12 col-md-6 col-xl-4">
            <Link :href="dtrLink" class="module-card card h-100 text-decoration-none">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="ri-time-line module-icon"></i>
                        <h2 class="h6 mb-0">Daily Time Record</h2>
                    </div>
                    <p class="small text-body-secondary mb-3">
                        Every blank on your DTR, and why — noted on the day, or explained by a trip or leave already here.
                    </p>
                    <div v-if="dtr.month" class="d-flex flex-wrap gap-3 small">
                        <span :class="{ 'text-warning-emphasis': dtr.unexplained }">
                            <strong class="num">{{ dtr.unexplained }}</strong> day{{ dtr.unexplained === 1 ? '' : 's' }} to explain
                        </span>
                        <span><strong class="num">{{ dtr.explained }}</strong> explained</span>
                        <span class="text-body-secondary">{{ dtr.month_label }}</span>
                    </div>
                    <div v-else class="small text-body-secondary">Import your DTR to see its blanks.</div>
                </div>
            </Link>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <Link :href="route('booking-requests.index')" class="module-card card h-100 text-decoration-none">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="ri-plane-line module-icon"></i>
                        <h2 class="h6 mb-0">Booking Request</h2>
                    </div>
                    <p class="small text-body-secondary mb-3">
                        Request for Airline Booking for a whole travel order — passengers' details remembered for next time.
                    </p>
                    <div class="d-flex flex-wrap gap-3 small">
                        <span><strong class="num">{{ bookingRequests.draft }}</strong> draft</span>
                        <span><strong class="num">{{ bookingRequests.submitted }}</strong> with the Supply Unit</span>
                        <span><strong class="num">{{ bookingRequests.booked }}</strong> booked</span>
                    </div>
                </div>
            </Link>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <Link :href="route('cash-advances.index')" class="module-card card h-100 text-decoration-none">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="ri-hand-coin-line module-icon"></i>
                        <h2 class="h6 mb-0">Cash Advance</h2>
                    </div>
                    <p class="small text-body-secondary mb-3">
                        Before the trip: Itinerary of Travel and No Pending Cash Advance — then start the liquidation from it.
                    </p>
                    <div class="d-flex flex-wrap gap-3 small">
                        <span><strong class="num">{{ cashAdvances.pending }}</strong> not yet liquidated</span>
                        <span v-if="cashAdvances.overdue" class="text-danger">
                            <strong class="num">{{ cashAdvances.overdue }}</strong> overdue
                        </span>
                    </div>
                </div>
            </Link>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <Link :href="route('liquidations.index')" class="module-card card h-100 text-decoration-none">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="ri-suitcase-line module-icon"></i>
                        <h2 class="h6 mb-0">Travel Liquidation</h2>
                    </div>
                    <p class="small text-body-secondary mb-3">
                        Enter a trip once; download the itinerary, reports, certificates and liquidation report filled in.
                    </p>
                    <div class="d-flex flex-wrap gap-3 small">
                        <span><strong class="num">{{ liquidations.pending }}</strong> not yet submitted</span>
                        <span v-if="liquidations.overdue" class="text-danger">
                            <strong class="num">{{ liquidations.overdue }}</strong> overdue
                        </span>
                    </div>
                </div>
            </Link>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <Link :href="route('reimbursements.index')" class="module-card card h-100 text-decoration-none">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="ri-refund-2-line module-icon"></i>
                        <h2 class="h6 mb-0">Reimbursement</h2>
                    </div>
                    <p class="small text-body-secondary mb-3">
                        Travelled without a cash advance? Claim it back with the same forms, minus the liquidation report.
                    </p>
                    <div class="d-flex flex-wrap gap-3 small">
                        <span><strong class="num">{{ reimbursements.pending }}</strong> not yet submitted</span>
                        <span v-if="reimbursements.amount_pending"><strong class="num">{{ peso(reimbursements.amount_pending) }}</strong> to be reimbursed</span>
                    </div>
                </div>
            </Link>
        </div>
        <div class="col-12 col-md-6 col-xl-4">
            <Link :href="route('leave-applications.index')" class="module-card card h-100 text-decoration-none">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="ri-calendar-check-line module-icon"></i>
                        <h2 class="h6 mb-0">Leave Application</h2>
                    </div>
                    <p class="small text-body-secondary mb-3">
                        The Application for Leave (CSC Form No. 6) filled in and ready to print — names remembered for next time.
                    </p>
                    <div class="d-flex flex-wrap gap-3 small">
                        <span><strong class="num">{{ leaveApplications.draft }}</strong> draft</span>
                        <span><strong class="num">{{ leaveApplications.submitted }}</strong> awaiting a decision</span>
                        <span><strong class="num">{{ leaveApplications.approved }}</strong> approved</span>
                    </div>
                </div>
            </Link>
        </div>
    </div>
</template>

<style scoped>
.module-card {
    color: var(--ink);
    transition: border-color .12s ease;
}
.module-card:hover { border-color: var(--brand); }
.module-icon { font-size: 1.25rem; color: var(--brand); }
</style>
