<script>
import { router, useForm } from '@inertiajs/vue3';
import { peso, date } from '@/Shared/format.js';
import ItineraryEditor from '@/Components/Travel/ItineraryEditor.vue';
import SignatoryFields from '@/Components/Travel/SignatoryFields.vue';
import TravelerFields from '@/Components/Travel/TravelerFields.vue';
import TripFields from '@/Components/Travel/TripFields.vue';
import TripTeam from '@/Components/Travel/TripTeam.vue';
import { fareFields } from '@/Components/Travel/fares.js';

const TEXT_FIELDS = [
    'traveler_name', 'position', 'employee_no', 'official_station', 'division',
    'travel_order_no', 'travel_order_date', 'departure_date', 'return_date', 'venue', 'destination_code', 'purpose', 'fund_cluster',
    'requested_on',
];

function initial(cashAdvance, defaults, travelers, options) {
    const source = cashAdvance ?? defaults;
    const data = Object.fromEntries(TEXT_FIELDS.map(key => [key, source[key] ?? '']));
    // Fares from home live on the saved traveller, not the cash advance.
    const saved = travelers.find(t => t.name.toUpperCase() === String(data.traveler_name).toUpperCase());
    Object.assign(data, fareFields(saved, options));
    data.items = (cashAdvance?.items ?? []).map(item => ({ ...item }));
    data.signatories = { ...defaults.signatories, ...(cashAdvance?.signatories ?? {}) };
    data.lunch_provided = cashAdvance?.lunch_provided ?? false;
    data.has_travel_order = cashAdvance?.has_travel_order ?? false;
    return data;
}

const SIGNATORIES = [
    { key: 'supervisor', label: 'Immediate supervisor', hint: 'Recommends approval of the itinerary' },
    { key: 'director', label: 'Approving official', hint: 'Approves the itinerary' },
    { key: 'accountant', label: 'Accountant', hint: 'Certifies no pending cash advance' },
];

export default {
    components: { ItineraryEditor, SignatoryFields, TravelerFields, TripFields, TripTeam },

    props: {
        cashAdvance: { type: Object, default: null },
        defaults: { type: Object, required: true },
        travelers: { type: Array, default: () => [] },
        options: { type: Object, required: true },
        // Everyone's cash advance on this travel order, this one included.
        team: { type: Array, default: () => [] },
    },

    data() {
        return {
            form: useForm(initial(this.cashAdvance, this.defaults, this.travelers, this.options)),
            confirmingDelete: false,
            signatories: SIGNATORIES,
        };
    },

    watch: {
        // Inertia reuses this component between records, so start a fresh form.
        'cashAdvance.id'(id, previous) {
            if (id !== previous) {
                this.form = useForm(initial(this.cashAdvance, this.defaults, this.travelers, this.options));
                this.confirmingDelete = false;
            }
        },
    },

    computed: {
        isEdit() {
            return this.cashAdvance !== null;
        },
        heading() {
            if (!this.isEdit) return 'New cash advance';
            return [this.cashAdvance.traveler_name, this.cashAdvance.travel_order_no && `TO ${this.cashAdvance.travel_order_no}`]
                .filter(Boolean).join(' · ');
        },
        itineraryTotal() {
            return this.form.items.reduce((sum, i) => sum + Number(i.transport_cost || 0) + Number(i.per_diem || 0) + Number(i.other_cost || 0), 0);
        },
        deadline() {
            if (!this.form.return_date) return null;
            const [y, m, d] = this.form.return_date.split('-').map(Number);
            const due = new Date(y, m - 1, d + this.options.deadlineDays);
            return `${due.getFullYear()}-${String(due.getMonth() + 1).padStart(2, '0')}-${String(due.getDate()).padStart(2, '0')}`;
        },
        /** Same rules as CashAdvance::checklist() on the server. */
        checklist() {
            return [
                { key: 'travel_order', label: 'Travel Order', external: true, done: this.form.has_travel_order },
                { key: 'itinerary', label: 'Itinerary of Travel', done: this.form.items.length > 0, todo: 'Add the planned legs', section: 'itinerary' },
                { key: 'no_pending', label: 'No Pending Cash Advance certification', done: this.itineraryTotal > 0, todo: 'Add amounts to the itinerary', section: 'itinerary' },
            ];
        },
        ready() {
            return this.checklist.filter(p => p.done).length;
        },
        errorCount() {
            return Object.keys(this.form.errors).length;
        },
        downloads() {
            if (!this.isEdit) return [];
            const link = type => route('cash-advances.download', [this.cashAdvance.id, type]);
            return [
                { label: 'Itinerary of Travel (Excel)', href: link('itinerary'), icon: 'ri-file-excel-2-line' },
                { label: 'No Pending Cash Advance (Word)', href: link('no-pending'), icon: 'ri-file-word-2-line' },
            ];
        },
    },

    methods: {
        peso,
        date,

        submit() {
            const options = {
                preserveScroll: true,
                onError: () => this.$nextTick(() => document.querySelector('.is-invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' })),
            };
            if (this.isEdit) {
                this.form.put(route('cash-advances.update', this.cashAdvance.id), options);
            } else {
                this.form.post(route('cash-advances.store'), options);
            }
        },

        liquidate() {
            router.post(route('cash-advances.liquidate', this.cashAdvance.id));
        },

        destroy() {
            router.delete(route('cash-advances.destroy', this.cashAdvance.id));
        },
    },
};
</script>

<template>
    <Head :title="isEdit ? 'Edit cash advance' : 'New cash advance'" />

    <Link :href="route('cash-advances.index')" class="small text-decoration-none d-inline-block mb-2">
        <i class="ri-arrow-left-line"></i> All cash advances
    </Link>

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h1 class="h4 mb-1">{{ heading }}</h1>
            <p class="text-body-secondary mb-0">Before the trip: the Itinerary of Travel and No Pending Cash Advance certification.</p>
        </div>
        <template v-if="isEdit">
            <Link v-if="cashAdvance.liquidation" :href="route('liquidations.edit', cashAdvance.liquidation.id)" class="btn btn-outline-success">
                <i class="ri-suitcase-line me-1"></i>Open liquidation
            </Link>
            <button v-else type="button" class="btn btn-outline-success" :disabled="form.isDirty" @click="liquidate"
                    :title="form.isDirty ? 'Save first — the liquidation copies the saved version' : 'Copy this trip into a new liquidation'">
                <i class="ri-file-copy-2-line me-1"></i>Start liquidation
            </button>
            <a :href="route('cash-advances.download', [cashAdvance.id, 'all'])" class="btn btn-primary">
                <i class="ri-download-2-line me-1"></i>Download all
            </a>
            <BDropdown text="One document" variant="outline-primary" end>
                <BDropdownItem v-for="d in downloads" :key="d.label" :href="d.href">
                    <i :class="[d.icon, 'me-1']"></i>{{ d.label }}
                </BDropdownItem>
            </BDropdown>
        </template>
    </div>

    <div v-if="isEdit && form.isDirty" class="alert alert-warning d-flex gap-2 small py-2">
        <i class="ri-error-warning-line"></i>
        <span>You have unsaved changes. Downloads and "Start liquidation" use the last saved version — save first.</span>
    </div>

    <TripTeam v-if="isEdit" :team="team" :current-id="cashAdvance.id" :travel-order-no="cashAdvance.travel_order_no"
              :travelers="travelers" :options="options" edit-route="cash-advances.edit"
              :add-url="route('cash-advances.travelers.store', cashAdvance.id)"
              :download-url="route('cash-advances.download', [cashAdvance.id, 'team-itineraries'])"
              copies="travel order, trip and itinerary" :disabled="form.isDirty" />

    <!-- ─── Requirements checklist ─────────────────────────────────────── -->
    <section class="card mb-3 checklist" aria-labelledby="checklist-title">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h2 id="checklist-title" class="h6 mb-0"><i class="ri-list-check-3 me-1"></i>Cash advance requirements</h2>
                <span class="small num ms-auto" :class="ready === checklist.length ? 'text-success fw-semibold' : 'text-body-secondary'">
                    {{ ready }} of {{ checklist.length }} ready
                </span>
            </div>
            <ul class="row g-2 list-unstyled mb-0">
                <li v-for="paper in checklist" :key="paper.key" class="col-md-4 d-flex gap-2">
                    <i v-if="paper.done" class="ri-checkbox-circle-fill text-success paper-icon" aria-hidden="true"></i>
                    <i v-else class="ri-checkbox-blank-circle-line text-body-tertiary paper-icon" aria-hidden="true"></i>
                    <div class="small">
                        <div class="fw-semibold">
                            {{ paper.label }}
                            <span class="visually-hidden">— {{ paper.done ? 'ready' : 'missing' }}</span>
                        </div>
                        <template v-if="paper.external">
                            <div class="form-check form-check-inline mb-0">
                                <input id="has_travel_order" v-model="form.has_travel_order" class="form-check-input" type="checkbox">
                                <label class="form-check-label" for="has_travel_order">I have it</label>
                            </div>
                            <a :href="options.vramsUrl" target="_blank" rel="noopener">Download it from VRAMS <i class="ri-external-link-line"></i></a>
                        </template>
                        <div v-else-if="paper.done" class="text-body-secondary">{{ isEdit ? 'In Download all' : 'Generated when you save' }}</div>
                        <a v-else :href="`#section-${paper.section}`">{{ paper.todo }}</a>
                    </div>
                </li>
            </ul>
        </div>
    </section>

    <form id="cash-advance-form" novalidate @submit.prevent="submit">
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>1</span>Traveller</h2>
                <TravelerFields :form="form" :travelers="travelers" :options="options" />
            </div>
        </section>

        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>2</span>Travel order</h2>
                <TripFields :form="form" :options="options" />
            </div>
        </section>

        <section id="section-itinerary" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>3</span>Planned itinerary of travel</h2>
                <ItineraryEditor :form="form" :options="options" />
            </div>
        </section>

        <section id="section-amount" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>4</span>Cash advance</h2>
                <div class="row g-3 align-items-start">
                    <div class="col-md-5">
                        <div class="advance-total">
                            <div class="small text-body-secondary">Cash advance — the Itinerary of Travel total</div>
                            <div class="fs-4 fw-bold num">{{ peso(itineraryTotal) }}</div>
                            <div v-if="deadline" class="small text-body-secondary">
                                Liquidate by <strong>{{ date(deadline) }}</strong> ({{ options.deadlineDays }} days after the trip)
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="requested_on" class="form-label">Date requested</label>
                        <input id="requested_on" v-model="form.requested_on" type="date" class="form-control" :class="{ 'is-invalid': form.errors.requested_on }">
                        <div class="invalid-feedback">{{ form.errors.requested_on }}</div>
                        <div class="form-text">The certification is dated this day.</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>5</span>Signatories</h2>
                <SignatoryFields :form="form" :roles="signatories" />
            </div>
        </section>
    </form>

    <!-- ─── Save bar ─────────────────────────────────────────────────── -->
    <div class="save-bar card">
        <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
            <span v-if="errorCount" class="small text-danger">
                <i class="ri-error-warning-line me-1"></i>{{ errorCount }} field{{ errorCount === 1 ? ' needs' : 's need' }} fixing — highlighted above.
            </span>
            <span v-else-if="form.isDirty" class="small text-body-secondary">Unsaved changes</span>
            <span v-else-if="isEdit" class="small text-body-secondary"><i class="ri-check-line me-1"></i>Saved</span>

            <template v-if="isEdit">
                <template v-if="confirmingDelete">
                    <span class="small text-danger ms-md-3">
                        Delete this cash advance?{{ cashAdvance.liquidation ? ' Its liquidation stays.' : '' }}
                    </span>
                    <button type="button" class="btn btn-sm btn-danger" @click="destroy">Delete</button>
                    <button type="button" class="btn btn-sm btn-link" @click="confirmingDelete = false">Keep</button>
                </template>
                <button v-else type="button" class="btn btn-sm btn-outline-danger ms-md-3" @click="confirmingDelete = true">
                    <i class="ri-delete-bin-line me-1"></i>Delete
                </button>
            </template>

            <button type="submit" form="cash-advance-form" class="btn btn-primary ms-auto" :disabled="form.processing">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ isEdit ? 'Save changes' : 'Save cash advance' }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.section-title {
    display: flex;
    align-items: center;
    gap: .6rem;
    font-size: 1rem;
    margin-bottom: 1rem;
}
.section-title span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    background: var(--brand-subtle);
    color: var(--brand-strong);
    font-size: .75rem;
}
[data-bs-theme="dark"] .section-title span { color: #9EC5FE; }

.checklist { border-left: 3px solid var(--brand); }
.paper-icon { font-size: 1.15rem; line-height: 1.2; flex-shrink: 0; }

.advance-total {
    border: 1px solid var(--hairline);
    border-radius: var(--r-lg);
    background: var(--surface-sunken);
    padding: .75rem 1rem;
}

.save-bar {
    position: sticky;
    bottom: .75rem;
    z-index: 20;
    box-shadow: var(--shadow-pop);
}

section[id^="section-"] { scroll-margin-top: calc(var(--topbar-height) + 1rem); }
</style>
