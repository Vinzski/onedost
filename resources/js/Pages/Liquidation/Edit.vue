<script>
import { router, useForm } from '@inertiajs/vue3';
import { peso, date } from '@/Shared/format.js';
import ItineraryEditor from '@/Components/Travel/ItineraryEditor.vue';
import SignatoryFields from '@/Components/Travel/SignatoryFields.vue';
import TravelerFields from '@/Components/Travel/TravelerFields.vue';
import TripFields from '@/Components/Travel/TripFields.vue';
import TripTeam from '@/Components/Travel/TripTeam.vue';
import { fareFields } from '@/Components/Travel/fares.js';
import ActivityPhotos from '@/Components/Liquidation/ActivityPhotos.vue';
import ReceiptCertificates from '@/Components/Liquidation/ReceiptCertificates.vue';

const TEXT_FIELDS = [
    'traveler_name', 'position', 'employee_no', 'official_station', 'division',
    'travel_order_no', 'travel_order_date', 'departure_date', 'return_date', 'venue', 'destination_code', 'purpose', 'fund_cluster',
    'cash_advance_amount', 'dv_no', 'dv_date', 'or_no', 'or_date', 'supplies_amount', 'other_expenses_amount',
    'output_summary', 'follow_up', 'compliance', 'compliance_note', 'activity_title', 'activity_body', 'submitted_on',
];

function initial(liquidation, defaults, travelers, options) {
    const source = liquidation ?? defaults;
    const data = Object.fromEntries(TEXT_FIELDS.map(key => [key, source[key] ?? '']));
    // Fares from home live on the saved traveller, not the claim.
    const saved = travelers.find(t => t.name.toUpperCase() === String(data.traveler_name).toUpperCase());
    Object.assign(data, fareFields(saved, options));
    data.compliance ||= 'strict';
    data.items = (liquidation?.items ?? []).map(item => ({ ...item }));
    data.persons_contacted = (liquidation?.persons_contacted ?? []).map(p => ({ name: p.name ?? '', designation: p.designation ?? '' }));
    data.evidence = [...(source.evidence ?? [])];
    data.signatories = { ...defaults.signatories, ...(liquidation?.signatories ?? {}) };
    data.receipt_certificates = (liquidation?.receipt_certificates ?? [])
        .map(c => ({ lines: c.lines.map(l => ({ particulars: l.particulars, amount: l.amount })) }));
    data.lunch_provided = liquidation?.lunch_provided ?? false;
    data.has_travel_order = liquidation?.has_travel_order ?? false;
    data.has_certificate_of_appearance = liquidation?.has_certificate_of_appearance ?? false;
    return data;
}

const SIGNATORIES = [
    { key: 'supervisor', label: 'Immediate supervisor', hint: 'Recommends the itinerary, notes the reports' },
    { key: 'director', label: 'Approving official', hint: 'Approves the itinerary and certificate' },
    { key: 'accountant', label: 'Accountant', hint: 'Certifies the liquidation report' },
];

export default {
    components: { ItineraryEditor, ActivityPhotos, ReceiptCertificates, SignatoryFields, TravelerFields, TripFields, TripTeam },

    props: {
        liquidation: { type: Object, default: null },
        defaults: { type: Object, required: true },
        travelers: { type: Array, default: () => [] },
        options: { type: Object, required: true },
        // Cash advances with no liquidation yet (new liquidations only).
        cashAdvances: { type: Array, default: () => [] },
        // 'liquidation' or 'reimbursement' — the same claim; a reimbursement has
        // no cash advance and no Liquidation Report.
        kind: { type: String, default: 'liquidation' },
        // Route names this page links with: 'liquidations' / 'reimbursements'.
        routePrefix: { type: String, default: 'liquidations' },
        // Everyone's claim of this kind on this travel order, this one included.
        team: { type: Array, default: () => [] },
    },

    data() {
        return {
            form: useForm(initial(this.liquidation, this.defaults, this.travelers, this.options)),
            confirmingDelete: false,
            // Photos picked on a new liquidation; they go up with the first save.
            pendingPhotos: [],
            signatories: SIGNATORIES,
        };
    },

    watch: {
        // Inertia reuses this component when moving between liquidations
        // (e.g. to another traveller on the same travel order), so start a fresh form.
        'liquidation.id'(id, previous) {
            if (id !== previous) {
                this.form = useForm(initial(this.liquidation, this.defaults, this.travelers, this.options));
                this.confirmingDelete = false;
                this.pendingPhotos = [];
            }
        },
    },

    computed: {
        isEdit() {
            return this.liquidation !== null;
        },
        isReimbursement() {
            return this.kind === 'reimbursement';
        },
        // "liquidation" / "reimbursement", for wording.
        noun() {
            return this.isReimbursement ? 'reimbursement' : 'liquidation';
        },
        heading() {
            if (!this.isEdit) return `New ${this.noun}`;
            return [this.liquidation.traveler_name || 'Untitled', this.liquidation.travel_order_no && `TO ${this.liquidation.travel_order_no}`]
                .filter(Boolean).join(' · ');
        },
        travelExpenses() {
            return this.form.items.reduce((sum, i) => sum + Number(i.transport_cost || 0) + Number(i.per_diem || 0) + Number(i.other_cost || 0), 0);
        },
        totalSpent() {
            return this.travelExpenses + Number(this.form.supplies_amount || 0) + Number(this.form.other_expenses_amount || 0);
        },
        balance() {
            return Number(this.form.cash_advance_amount || 0) - this.totalSpent;
        },
        /**
         * Papers submitted with the liquidation, live from the form.
         * Same rules as Liquidation::checklist() on the server.
         */
        checklist() {
            const f = this.form;
            const certificates = f.receipt_certificates.filter(c => c.lines.some(l => (l.particulars || '').trim())).length;
            const filled = value => value !== null && value !== undefined && String(value).trim() !== '';

            return [
                { key: 'travel_order', label: 'Travel Order', external: true, field: 'has_travel_order', done: f.has_travel_order,
                  hint: 'Download it from VRAMS.', link: this.options.vramsUrl },
                { key: 'itinerary', label: 'Itinerary of Travel', done: f.items.length > 0,
                  todo: 'Add the trip legs', section: 'itinerary' },
                { key: 'accomplishment', label: 'Travel Accomplishment Report', done: filled(f.purpose) && filled(f.venue),
                  todo: 'Fill in the purpose and venue', section: 'trip' },
                { key: 'completion', label: 'Certificate of Travel Completed', done: f.compliance === 'strict' || filled(f.compliance_note),
                  todo: 'Explain how the trip differed', section: 'completion' },
                { key: 'cenrr', label: 'Certification of Expenses Not Requiring Receipts', done: certificates > 0, notNeeded: certificates === 0,
                  doneText: `${certificates} certificate${certificates === 1 ? '' : 's'}`, section: 'cenrr' },
                { key: 'liquidation', label: 'Liquidation Report', done: filled(f.cash_advance_amount),
                  todo: 'Enter the cash advance received', section: 'money' },
                { key: 'activity', label: 'Activity Report', done: filled(f.activity_body),
                  todo: 'Write what happened', section: 'activity' },
                { key: 'appearance', label: 'Certificate of Appearance', external: true, field: 'has_certificate_of_appearance',
                  done: f.has_certificate_of_appearance, hint: 'Ask the host or organiser for it after the event.' },
            ].filter(paper => !(this.isReimbursement && paper.key === 'liquidation'));
        },
        checklistProgress() {
            const needed = this.checklist.filter(p => !p.notNeeded);
            return { done: needed.filter(p => p.done).length, total: needed.length };
        },
        deadline() {
            if (!this.form.return_date) return null;
            const [y, m, d] = this.form.return_date.split('-').map(Number);
            const due = new Date(y, m - 1, d + this.options.deadlineDays);
            return `${due.getFullYear()}-${String(due.getMonth() + 1).padStart(2, '0')}-${String(due.getDate()).padStart(2, '0')}`;
        },
        errorCount() {
            return Object.keys(this.form.errors).length;
        },
        downloads() {
            if (!this.isEdit) return [];
            const link = type => this.to('download', [this.liquidation.id, type]);
            const sheets = 'IOT, Accomplishment Report, Certificate of Travel Completed, CENRR'
                + (this.isReimbursement ? '' : ', Liquidation Report');
            return [
                { label: `${this.isReimbursement ? 'Reimbursement' : 'Liquidation'} forms (Excel)`, hint: sheets, href: link('forms'), icon: 'ri-file-excel-2-line' },
                { label: 'Activity report (Word)', href: link('activity-report'), icon: 'ri-file-word-2-line' },
            ];
        },
    },

    methods: {
        peso,
        date,

        /** This kind's route: to('edit', id) → route('reimbursements.edit', id). */
        to(name, params) {
            return route(`${this.routePrefix}.${name}`, params);
        },

        addPerson() {
            this.form.persons_contacted.push({ name: '', designation: '' });
        },

        addEvidence() {
            this.form.evidence.push('');
        },

        submit() {
            const options = {
                preserveScroll: true,
                onError: () => this.$nextTick(() => document.querySelector('.is-invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' })),
            };
            if (this.isEdit) {
                this.form.put(this.to('update', this.liquidation.id), options);
            } else {
                this.form
                    .transform(data => (this.pendingPhotos.length ? { ...data, photos: this.pendingPhotos } : data))
                    .post(this.to('store'), options);
            }
        },

        startFrom(cashAdvance) {
            router.post(route('cash-advances.liquidate', cashAdvance.id));
        },

        destroy() {
            router.delete(this.to('destroy', this.liquidation.id));
        },
    },
};
</script>

<template>
    <Head :title="`${isEdit ? 'Edit' : 'New'} ${noun}`" />

    <Link :href="to('index')" class="small text-decoration-none d-inline-block mb-2">
        <i class="ri-arrow-left-line"></i> All {{ noun }}s
    </Link>

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h1 class="h4 mb-1">{{ heading }}</h1>
            <p class="text-body-secondary mb-0">
                Fill this in once — every form below is generated from it.
                <Link v-if="liquidation?.cash_advance" :href="route('cash-advances.edit', liquidation.cash_advance.id)" class="ms-1">
                    <i class="ri-links-line"></i> From cash advance TO {{ liquidation.cash_advance.travel_order_no }}
                </Link>
            </p>
        </div>
        <template v-if="isEdit">
            <a :href="to('download', [liquidation.id, 'all'])" class="btn btn-primary">
                <i class="ri-download-2-line me-1"></i>Download all
            </a>
            <BDropdown text="One document" variant="outline-primary" end>
                <BDropdownItem v-for="d in downloads" :key="d.label" :href="d.href">
                    <i :class="[d.icon, 'me-1']"></i>{{ d.label }}
                    <div v-if="d.hint" class="small text-body-secondary text-wrap dropdown-hint">{{ d.hint }}</div>
                </BDropdownItem>
            </BDropdown>
        </template>
    </div>

    <div v-if="isEdit && form.isDirty" class="alert alert-warning d-flex gap-2 small py-2">
        <i class="ri-error-warning-line"></i>
        <span>You have unsaved changes. Downloads use the last saved version — save first.</span>
    </div>

    <TripTeam v-if="isEdit" :team="team" :current-id="liquidation.id" :travel-order-no="liquidation.travel_order_no"
              :travelers="travelers" :options="options" :edit-route="`${routePrefix}.edit`"
              :add-url="to('travelers.store', liquidation.id)"
              copies="the itinerary, reports and photos" :disabled="form.isDirty" />

    <!-- ─── Start from a cash advance (new liquidations) ───────────────── -->
    <section v-if="!isEdit && cashAdvances.length" class="card mb-3 from-cash-advance">
        <div class="card-body">
            <h2 class="h6 mb-1"><i class="ri-file-copy-2-line me-1"></i>Start from a cash advance</h2>
            <p class="small text-body-secondary mb-2">
                Copies the traveller, travel order, trip and itinerary — then update anything that changed on the trip.
            </p>
            <ul class="list-unstyled mb-0">
                <li v-for="ca in cashAdvances" :key="ca.id" class="d-flex flex-wrap align-items-center gap-2 py-2 border-top">
                    <div class="small me-auto">
                        <span class="fw-semibold">{{ ca.traveler_name }}</span>
                        · TO {{ ca.travel_order_no }} · {{ ca.travel_dates }}
                        <span class="text-body-secondary">· {{ ca.venue }} · {{ peso(ca.amount) }}</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="startFrom(ca)">Use this</button>
                </li>
            </ul>
        </div>
    </section>

    <!-- ─── Requirements checklist ─────────────────────────────────────── -->
    <section class="card mb-3 checklist" aria-labelledby="checklist-title">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <h2 id="checklist-title" class="h6 mb-0"><i class="ri-list-check-3 me-1"></i>{{ isReimbursement ? 'Reimbursement' : 'Liquidation' }} requirements</h2>
                <span class="small num ms-auto" :class="checklistProgress.done === checklistProgress.total ? 'text-success fw-semibold' : 'text-body-secondary'">
                    {{ checklistProgress.done }} of {{ checklistProgress.total }} ready
                </span>
            </div>
            <div class="meter mb-3" role="progressbar" :aria-valuenow="checklistProgress.done" aria-valuemin="0" :aria-valuemax="checklistProgress.total"
                 aria-label="Requirements ready">
                <div class="meter-fill" :style="{ width: `${(checklistProgress.done / checklistProgress.total) * 100}%` }"></div>
            </div>
            <ul class="row g-2 list-unstyled mb-0">
                <li v-for="paper in checklist" :key="paper.key" class="col-md-6 d-flex gap-2">
                    <i v-if="paper.notNeeded" class="ri-indeterminate-circle-line text-body-tertiary paper-icon" aria-hidden="true"></i>
                    <i v-else-if="paper.done" class="ri-checkbox-circle-fill text-success paper-icon" aria-hidden="true"></i>
                    <i v-else class="ri-checkbox-blank-circle-line text-body-tertiary paper-icon" aria-hidden="true"></i>
                    <div class="small">
                        <div :class="{ 'fw-semibold': !paper.notNeeded, 'text-body-secondary': paper.notNeeded }">
                            {{ paper.label }}
                            <span class="visually-hidden">— {{ paper.notNeeded ? 'not needed' : paper.done ? 'ready' : 'missing' }}</span>
                        </div>
                        <template v-if="paper.external">
                            <div class="form-check form-check-inline mb-0">
                                <input :id="paper.field" v-model="form[paper.field]" class="form-check-input" type="checkbox">
                                <label class="form-check-label" :for="paper.field">I have it</label>
                            </div>
                            <a v-if="paper.link" :href="paper.link" target="_blank" rel="noopener">{{ paper.hint }} <i class="ri-external-link-line"></i></a>
                            <span v-else class="text-body-secondary">{{ paper.hint }}</span>
                        </template>
                        <div v-else-if="paper.notNeeded" class="text-body-secondary">Not needed — every expense has a receipt.</div>
                        <div v-else-if="paper.done" class="text-body-secondary">
                            {{ [paper.doneText, isEdit ? 'In Download all' : 'Generated when you save'].filter(Boolean).join(' · ') }}
                        </div>
                        <a v-else :href="`#section-${paper.section}`">{{ paper.todo }}</a>
                    </div>
                </li>
            </ul>
        </div>
    </section>

    <form id="liquidation-form" novalidate @submit.prevent="submit">
        <!-- ─── 1. Traveller ──────────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>1</span>Traveller</h2>
                <TravelerFields :form="form" :travelers="travelers" :options="options" />
            </div>
        </section>

        <!-- ─── 2. Trip ───────────────────────────────────────────────── -->
        <section id="section-trip" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>2</span>Travel order</h2>
                <TripFields :form="form" :options="options" />
            </div>
        </section>

        <!-- ─── 3. Itinerary ──────────────────────────────────────────── -->
        <section id="section-itinerary" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>3</span>Itinerary of travel</h2>
                <ItineraryEditor :form="form" :options="options" />
            </div>
        </section>

        <!-- ─── 4. Expenses without receipts ──────────────────────────── -->
        <section id="section-cenrr" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>4</span>Expenses not requiring receipts (CENRR)</h2>
                <ReceiptCertificates :form="form" :options="options" />
            </div>
        </section>

        <!-- ─── 5. Money ──────────────────────────────────────────────── -->
        <section id="section-money" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>5</span>{{ isReimbursement ? 'Amount to reimburse' : 'Cash advance & liquidation' }}</h2>
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="row g-3">
                            <template v-if="!isReimbursement">
                                <div class="col-sm-4">
                                    <label for="cash_advance_amount" class="form-label">Cash advance received (₱)</label>
                                    <input id="cash_advance_amount" v-model="form.cash_advance_amount" type="number" min="0" step="0.01" class="form-control num"
                                           :class="{ 'is-invalid': form.errors.cash_advance_amount }">
                                    <div class="invalid-feedback">{{ form.errors.cash_advance_amount }}</div>
                                </div>
                                <div class="col-sm-4">
                                    <label for="dv_no" class="form-label">DV no.</label>
                                    <input id="dv_no" v-model="form.dv_no" type="text" class="form-control" placeholder="Blank to fill by hand">
                                </div>
                                <div class="col-sm-4">
                                    <label for="dv_date" class="form-label">DV date</label>
                                    <input id="dv_date" v-model="form.dv_date" type="date" class="form-control">
                                </div>
                            </template>
                            <p v-else class="small text-body-secondary mb-0">
                                Spent from your own pocket — no cash advance. The itinerary plus anything below is claimed back.
                            </p>
                            <div class="col-sm-6">
                                <label for="supplies_amount" class="form-label">Supplies &amp; materials (₱)</label>
                                <input id="supplies_amount" v-model="form.supplies_amount" type="number" min="0" step="0.01" class="form-control num"
                                       :class="{ 'is-invalid': form.errors.supplies_amount }">
                                <div class="invalid-feedback">{{ form.errors.supplies_amount }}</div>
                            </div>
                            <div class="col-sm-6">
                                <label for="other_expenses_amount" class="form-label">Other expenses (₱)</label>
                                <input id="other_expenses_amount" v-model="form.other_expenses_amount" type="number" min="0" step="0.01" class="form-control num"
                                       :class="{ 'is-invalid': form.errors.other_expenses_amount }">
                                <div class="invalid-feedback">{{ form.errors.other_expenses_amount }}</div>
                            </div>
                            <template v-if="!isReimbursement && balance > 0">
                                <div class="col-sm-6">
                                    <label for="or_no" class="form-label">Refund OR no.</label>
                                    <input id="or_no" v-model="form.or_no" type="text" class="form-control">
                                </div>
                                <div class="col-sm-6">
                                    <label for="or_date" class="form-label">Refund OR date</label>
                                    <input id="or_date" v-model="form.or_date" type="date" class="form-control">
                                </div>
                            </template>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <dl class="ledger mb-0">
                            <div><dt>Travel expenses (itinerary)</dt><dd class="num">{{ peso(travelExpenses) }}</dd></div>
                            <div><dt>Supplies &amp; materials</dt><dd class="num">{{ peso(Number(form.supplies_amount || 0)) }}</dd></div>
                            <div><dt>Other expenses</dt><dd class="num">{{ peso(Number(form.other_expenses_amount || 0)) }}</dd></div>
                            <template v-if="isReimbursement">
                                <div class="ledger-result is-reimburse"><dt>To be reimbursed</dt><dd class="num">{{ peso(totalSpent) }}</dd></div>
                            </template>
                            <template v-else>
                                <div class="ledger-total"><dt>Total amount spent</dt><dd class="num">{{ peso(totalSpent) }}</dd></div>
                                <div><dt>Cash advance</dt><dd class="num">{{ peso(Number(form.cash_advance_amount || 0)) }}</dd></div>
                                <div class="ledger-result" :class="balance > 0 ? 'is-refund' : balance < 0 ? 'is-reimburse' : ''">
                                    <dt>{{ balance > 0 ? 'To refund' : balance < 0 ? 'To be reimbursed' : 'Fully liquidated' }}</dt>
                                    <dd class="num">{{ peso(Math.abs(balance)) }}</dd>
                                </div>
                            </template>
                        </dl>
                        <p v-if="deadline && !isReimbursement" class="small text-body-secondary mt-3 mb-0">
                            <i class="ri-calendar-check-line me-1"></i>Liquidate by <strong>{{ date(deadline) }}</strong>
                            ({{ options.deadlineDays }} days after the trip, COA Circular 2012-001).
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── 6. Accomplishment ─────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>6</span>Travel accomplishment report</h2>
                <label class="form-label">Persons contacted</label>
                <div v-for="(person, i) in form.persons_contacted" :key="i" class="row g-2 mb-2">
                    <div class="col-md-5">
                        <input v-model="person.name" type="text" class="form-control form-control-sm" placeholder="Name"
                               :class="{ 'is-invalid': form.errors[`persons_contacted.${i}.name`] }" :aria-label="`Person ${i + 1} name`">
                        <div class="invalid-feedback">{{ form.errors[`persons_contacted.${i}.name`] }}</div>
                    </div>
                    <div class="col">
                        <input v-model="person.designation" type="text" class="form-control form-control-sm" placeholder="Designation, office / agency"
                               :aria-label="`Person ${i + 1} designation`">
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-link text-danger" :aria-label="`Remove person ${i + 1}`" @click="form.persons_contacted.splice(i, 1)">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-3" @click="addPerson">
                    <i class="ri-user-add-line me-1"></i>Add person
                </button>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="output_summary" class="form-label">Output / impact / result</label>
                        <textarea id="output_summary" v-model="form.output_summary" rows="2" class="form-control"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="follow_up" class="form-label">Proposed follow-up activities</label>
                        <textarea id="follow_up" v-model="form.follow_up" rows="2" class="form-control"></textarea>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── 7. Certificate of travel completed ────────────────────── -->
        <section id="section-completion" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>7</span>Certificate of travel completed</h2>
                <div class="mb-3">
                    <div v-for="c in options.compliance" :key="c.value" class="form-check">
                        <input :id="`compliance-${c.value}`" v-model="form.compliance" class="form-check-input" type="radio" :value="c.value">
                        <label class="form-check-label" :for="`compliance-${c.value}`">{{ c.label }}</label>
                    </div>
                </div>
                <div v-if="form.compliance !== 'strict'" class="mb-3">
                    <label for="compliance_note" class="form-label">Explanation or justification</label>
                    <textarea id="compliance_note" v-model="form.compliance_note" rows="2" class="form-control"
                              :class="{ 'is-invalid': form.errors.compliance_note }"></textarea>
                    <div class="invalid-feedback">{{ form.errors.compliance_note }}</div>
                </div>

                <label class="form-label">Evidence of travel</label>
                <div v-for="(item, i) in form.evidence" :key="i" class="input-group input-group-sm mb-2">
                    <span class="input-group-text num">{{ i + 1 }}</span>
                    <input v-model="form.evidence[i]" type="text" class="form-control" :aria-label="`Evidence ${i + 1}`">
                    <button type="button" class="btn btn-outline-secondary" :aria-label="`Remove evidence ${i + 1}`" @click="form.evidence.splice(i, 1)">
                        <i class="ri-close-line"></i>
                    </button>
                </div>
                <button v-if="form.evidence.length < 5" type="button" class="btn btn-sm btn-outline-secondary" @click="addEvidence">
                    <i class="ri-add-line me-1"></i>Add evidence
                </button>
            </div>
        </section>

        <!-- ─── 8. Activity report ────────────────────────────────────── -->
        <section id="section-activity" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>8</span>Activity report</h2>
                <div class="mb-3">
                    <label for="activity_title" class="form-label">Title</label>
                    <input id="activity_title" v-model="form.activity_title" type="text" class="form-control"
                           placeholder="Activity Report of the RDC Week 2026 Deployment" :class="{ 'is-invalid': form.errors.activity_title }">
                    <div class="invalid-feedback">{{ form.errors.activity_title }}</div>
                </div>
                <div class="mb-3">
                    <label for="activity_body" class="form-label">What happened</label>
                    <textarea id="activity_body" v-model="form.activity_body" rows="8" class="form-control"
                              :class="{ 'is-invalid': form.errors.activity_body }"
                              placeholder="Leave a blank line between paragraphs."></textarea>
                    <div class="invalid-feedback">{{ form.errors.activity_body }}</div>
                </div>
                <label class="form-label">Photos</label>
                <ActivityPhotos v-model:pending="pendingPhotos" :liquidation-id="liquidation?.id ?? null" :route-prefix="routePrefix"
                                :photos="liquidation?.photos ?? []" :max="options.maxPhotos" />
            </div>
        </section>

        <!-- ─── 9. Signatories & status ───────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>9</span>Signatories &amp; submission</h2>
                <SignatoryFields :form="form" :roles="signatories" class="mb-3" />
                <div class="row">
                    <div class="col-md-4">
                        <label for="submitted_on" class="form-label">Submitted to Accounting on</label>
                        <input id="submitted_on" v-model="form.submitted_on" type="date" class="form-control">
                        <div class="form-text">
                            Set this once handed in{{ isReimbursement ? '.' : '; it clears the deadline reminder.' }}
                        </div>
                    </div>
                </div>
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
                    <span class="small text-danger ms-md-3">Delete this {{ noun }} and its photos?</span>
                    <button type="button" class="btn btn-sm btn-danger" @click="destroy">Delete</button>
                    <button type="button" class="btn btn-sm btn-link" @click="confirmingDelete = false">Keep</button>
                </template>
                <button v-else type="button" class="btn btn-sm btn-outline-danger ms-md-3" @click="confirmingDelete = true">
                    <i class="ri-delete-bin-line me-1"></i>Delete
                </button>
            </template>

            <button type="submit" form="liquidation-form" class="btn btn-primary ms-auto" :disabled="form.processing">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <template v-if="form.processing && form.progress">Uploading photos… {{ form.progress.percentage }}%</template>
                <template v-else>{{ isEdit ? 'Save changes' : `Save ${noun}` }}</template>
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

.ledger > div {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: .35rem 0;
    border-bottom: 1px solid var(--hairline);
}
.ledger dt { font-weight: 400; color: var(--ink-soft); }
.ledger dd { margin: 0; }
.ledger-total dt, .ledger-total dd { font-weight: 700; color: var(--ink); }
.ledger-result { border-bottom: 0 !important; font-size: 1.05rem; }
.ledger-result dt, .ledger-result dd { font-weight: 700; color: var(--ink); }
.ledger-result.is-refund dd { color: var(--bs-danger); }
.ledger-result.is-reimburse dd { color: var(--bs-success); }

.dropdown-hint { max-width: 18rem; }

.checklist { border-left: 3px solid var(--brand); }
.from-cash-advance { border-left: 3px solid var(--bs-info); }
.paper-icon { font-size: 1.15rem; line-height: 1.2; flex-shrink: 0; }
.meter { height: 4px; border-radius: 2px; background: var(--hairline); overflow: hidden; }
.meter-fill { height: 100%; background: var(--bs-success); transition: width .2s ease; }

/* "Jump to section" links land below the sticky top bar. */
section[id^="section-"] { scroll-margin-top: calc(var(--topbar-height) + 1rem); }

.save-bar {
    position: sticky;
    bottom: .75rem;
    z-index: 20;
    box-shadow: var(--shadow-pop);
}
</style>
