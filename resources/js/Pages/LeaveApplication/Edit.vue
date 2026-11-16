<script>
import { router, useForm } from '@inertiajs/vue3';
import SignatoryFields from '@/Components/Travel/SignatoryFields.vue';
import { findTraveler, splitName } from '@/Components/BookingRequest/passengers.js';
import { daysApplied, inclusiveDates, workingDays } from '@/Components/LeaveApplication/leave.js';

const TEXT_FIELDS = [
    'office', 'last_name', 'first_name', 'middle_name', 'position', 'salary', 'filed_on',
    'leave_type', 'leave_type_other', 'detail', 'detail_text', 'working_days', 'start_date', 'end_date', 'inclusive_dates',
    'submitted_on', 'outcome',
];

function initial(leave, defaults) {
    const source = leave ?? defaults;
    const data = Object.fromEntries(TEXT_FIELDS.map(key => [key, source[key] ?? '']));
    data.traveler_id = leave?.traveler_id ?? null;
    data.commutation_requested = source.commutation_requested ?? false;
    data.signatories = { ...defaults.signatories, ...(leave?.signatories ?? {}) };
    return data;
}

const SIGNATORIES = [
    { key: 'certifier', label: '7.A Leave credits certified by', hint: 'Finance and administrative services' },
    { key: 'recommender', label: '7.B Recommended by', hint: 'The employee\'s ARD' },
    { key: 'approver', label: '7.C Approved by', hint: 'Regional Director' },
];

export default {
    components: { SignatoryFields },

    props: {
        leaveApplication: { type: Object, default: null },
        defaults: { type: Object, required: true },
        travelers: { type: Array, default: () => [] },
        options: { type: Object, required: true },
    },

    data() {
        return {
            form: useForm(initial(this.leaveApplication, this.defaults)),
            lookup: '',
            confirmingDelete: false,
            signatories: SIGNATORIES,
        };
    },

    computed: {
        isEdit() {
            return this.leaveApplication !== null;
        },
        heading() {
            if (!this.isEdit) return 'New leave application';
            return `${this.leaveApplication.full_name} · ${this.leaveApplication.type_label}`;
        },
        type() {
            return this.options.types.find(t => t.value === this.form.leave_type);
        },
        // The 6.B boxes that go with this leave.
        details() {
            const values = this.type?.details ?? [];
            return this.options.details.filter(d => values.includes(d.value));
        },
        detail() {
            return this.details.find(d => d.value === this.form.detail);
        },
        // What the line beside the ticked box (or Special Leave Benefits for Women) asks for.
        specifies() {
            return this.detail?.specifies ?? this.type?.specifies ?? null;
        },
        countedDays() {
            return workingDays(this.form.start_date, this.form.end_date);
        },
        // Watched with their form, so moving to another application isn't taken for an edit.
        lineAsks() {
            return { form: this.form, value: this.specifies };
        },
        dayCount() {
            return { form: this.form, value: this.countedDays };
        },
        wordedDates() {
            return inclusiveDates(this.form.start_date, this.form.end_date);
        },
        printedDays() {
            return daysApplied(this.form.working_days);
        },
        linked() {
            return this.form.traveler_id ? this.travelers.find(t => t.id === this.form.traveler_id) : null;
        },
        errorCount() {
            return Object.keys(this.form.errors).length;
        },
        status() {
            const f = this.form;
            const outcome = this.options.outcomes.find(o => o.value === f.outcome);
            if (outcome) return { variant: outcome.variant, text: outcome.label };
            if (f.submitted_on) return { variant: 'info', text: 'Submitted' };
            return { variant: 'secondary', text: 'Draft' };
        },
    },

    watch: {
        // Inertia reuses this component between applications, so start a fresh form.
        'leaveApplication.id'(id, previous) {
            if (id !== previous) {
                this.form = useForm(initial(this.leaveApplication, this.defaults));
                this.confirmingDelete = false;
            }
        },
        // A box from another leave doesn't carry over.
        'form.leave_type'() {
            if (!this.details.some(d => d.value === this.form.detail)) this.form.detail = '';
        },
        // A place isn't an illness: clear the line when it asks for something else.
        lineAsks(now, before) {
            if (now.form === before.form && now.value !== before.value) now.form.detail_text = '';
        },
        'form.start_date'(start) {
            if (start && (!this.form.end_date || this.form.end_date < start)) this.form.end_date = start;
        },
        // New dates recount the days, unless the count was typed in (e.g. half a day).
        dayCount(now, before) {
            const typed = now.form.working_days;
            const untouched = typed === '' || typed === null || Number(typed) === before.value;
            if (now.form === before.form && now.value !== null && untouched) now.form.working_days = now.value;
        },
    },

    methods: {
        // A saved name fills in the employee.
        pick() {
            const saved = findTraveler(this.travelers, this.lookup);
            if (!saved) return;
            const split = splitName(saved.name);
            Object.assign(this.form, {
                traveler_id: saved.id,
                first_name: saved.first_name || split.first,
                middle_name: saved.middle_name || split.middle,
                last_name: saved.last_name || split.last,
                position: saved.position || this.form.position,
            });
            this.lookup = '';
        },

        unlink() {
            this.form.traveler_id = null;
        },

        submit() {
            const options = {
                preserveScroll: true,
                onError: () => this.$nextTick(() => document.querySelector('.is-invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' })),
            };
            if (this.isEdit) {
                this.form.put(route('leave-applications.update', this.leaveApplication.id), options);
            } else {
                this.form.post(route('leave-applications.store'), options);
            }
        },

        destroy() {
            router.delete(route('leave-applications.destroy', this.leaveApplication.id));
        },
    },
};
</script>

<template>
    <Head :title="isEdit ? 'Edit leave application' : 'New leave application'" />

    <Link :href="route('leave-applications.index')" class="small text-decoration-none d-inline-block mb-2">
        <i class="ri-arrow-left-line"></i> All leave applications
    </Link>

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h1 class="h4 mb-1">
                {{ heading }}
                <span v-if="isEdit" :class="`badge text-bg-${status.variant} align-middle ms-1 fs-6`">{{ status.text }}</span>
            </h1>
            <p class="text-body-secondary mb-0">Application for Leave (CSC Form No. 6) — printed on the region's form, ready to sign.</p>
        </div>
        <a v-if="isEdit" :href="route('leave-applications.download', leaveApplication.id)" target="_blank" rel="noopener" class="btn btn-primary">
            <i class="ri-printer-line me-1"></i>Open form
        </a>
    </div>

    <div v-if="isEdit && form.isDirty" class="alert alert-warning d-flex gap-2 small py-2">
        <i class="ri-error-warning-line"></i>
        <span>You have unsaved changes. The form uses the last saved version — save first.</span>
    </div>

    <form id="leave-form" novalidate @submit.prevent="submit">
        <!-- ─── 1. Employee ───────────────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>1</span>Employee</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="lookup" class="form-label">Saved person</label>
                        <input id="lookup" v-model="lookup" type="text" class="form-control" list="leave-travelers"
                               placeholder="Pick a name to fill this in" autocomplete="off" @change="pick">
                        <datalist id="leave-travelers">
                            <option v-for="t in travelers" :key="t.id" :value="t.name">{{ t.position }}</option>
                        </datalist>
                        <div v-if="linked" class="form-text">
                            <i class="ri-user-follow-line"></i> {{ linked.name }} — saved for next time
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="unlink">Not them</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="office" class="form-label">Office/department *</label>
                        <input id="office" v-model="form.office" type="text" class="form-control" maxlength="100" :class="{ 'is-invalid': form.errors.office }">
                        <div class="invalid-feedback">{{ form.errors.office }}</div>
                    </div>

                    <div class="col-md-4">
                        <label for="last_name" class="form-label">Last name *</label>
                        <input id="last_name" v-model="form.last_name" type="text" class="form-control" maxlength="100" :class="{ 'is-invalid': form.errors.last_name }">
                        <div class="invalid-feedback">{{ form.errors.last_name }}</div>
                    </div>
                    <div class="col-md-5">
                        <label for="first_name" class="form-label">First name *</label>
                        <input id="first_name" v-model="form.first_name" type="text" class="form-control" maxlength="100" :class="{ 'is-invalid': form.errors.first_name }">
                        <div class="invalid-feedback">{{ form.errors.first_name }}</div>
                    </div>
                    <div class="col-md-3">
                        <label for="middle_name" class="form-label">Middle name</label>
                        <input id="middle_name" v-model="form.middle_name" type="text" class="form-control" maxlength="100" placeholder="e.g. W."
                               :class="{ 'is-invalid': form.errors.middle_name }">
                        <div class="invalid-feedback">{{ form.errors.middle_name }}</div>
                    </div>

                    <div class="col-md-4">
                        <label for="position" class="form-label">Position *</label>
                        <input id="position" v-model="form.position" type="text" class="form-control" maxlength="100" placeholder="e.g. PTA III"
                               :class="{ 'is-invalid': form.errors.position }">
                        <div class="invalid-feedback">{{ form.errors.position }}</div>
                    </div>
                    <div class="col-md-4">
                        <label for="salary" class="form-label">Salary</label>
                        <div class="input-group has-validation">
                            <span class="input-group-text">₱</span>
                            <input id="salary" v-model="form.salary" type="number" min="0" step="0.01" class="form-control" placeholder="Leave blank if not stated"
                                   :class="{ 'is-invalid': form.errors.salary }">
                            <div class="invalid-feedback">{{ form.errors.salary }}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="filed_on" class="form-label">Date of filing *</label>
                        <input id="filed_on" v-model="form.filed_on" type="date" class="form-control" :class="{ 'is-invalid': form.errors.filed_on }">
                        <div class="invalid-feedback">{{ form.errors.filed_on }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── 2. Leave (6.A and 6.B) ────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>2</span>Leave</h2>
                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="form-label">Type of leave to be availed of *</div>
                        <div class="row row-cols-1 row-cols-md-2 g-1 leave-types" role="radiogroup" aria-label="Type of leave">
                            <div v-for="t in options.types" :key="t.value" class="col">
                                <div class="form-check">
                                    <input :id="`type-${t.value}`" v-model="form.leave_type" class="form-check-input" type="radio" name="leave_type" :value="t.value">
                                    <label class="form-check-label" :for="`type-${t.value}`">
                                        {{ t.label }}
                                        <span v-if="t.basis" class="d-block basis text-body-secondary">{{ t.basis }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div v-if="form.errors.leave_type" class="small text-danger mt-1">{{ form.errors.leave_type }}</div>
                        <div v-if="form.leave_type === 'others'" class="mt-2">
                            <label for="leave_type_other" class="form-label small mb-1">Others — specify *</label>
                            <input id="leave_type_other" v-model="form.leave_type_other" type="text" class="form-control form-control-sm" maxlength="100"
                                   placeholder="e.g. Wellness Leave" :class="{ 'is-invalid': form.errors.leave_type_other }">
                            <div class="invalid-feedback">{{ form.errors.leave_type_other }}</div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="details-panel h-100">
                            <div class="form-label">Details of leave</div>
                            <template v-if="details.length">
                                <div class="small text-body-secondary mb-2">
                                    {{ form.leave_type === 'others' ? 'Other purpose (optional):' : `In case of ${type.label}:` }}
                                </div>
                                <div class="d-flex flex-column gap-1" role="radiogroup" aria-label="Details of leave">
                                    <div v-for="d in details" :key="d.value" class="form-check">
                                        <input :id="`detail-${d.value}`" v-model="form.detail" class="form-check-input" type="radio" name="detail" :value="d.value"
                                               :class="{ 'is-invalid': form.errors.detail }">
                                        <label class="form-check-label" :for="`detail-${d.value}`">{{ d.label }}</label>
                                    </div>
                                    <div v-if="form.leave_type === 'others' && form.detail" class="form-check">
                                        <button type="button" class="btn btn-link btn-sm p-0" @click="form.detail = ''">Untick</button>
                                    </div>
                                </div>
                                <div v-if="form.errors.detail" class="small text-danger mt-1">{{ form.errors.detail }}</div>
                            </template>
                            <p v-else-if="!specifies" class="small text-body-secondary mb-0">Nothing to add for this leave.</p>

                            <div v-if="specifies" class="mt-3">
                                <label for="detail_text" class="form-label small mb-1">
                                    {{ specifies }}{{ detail?.needs_text ? ' *' : '' }}
                                </label>
                                <input id="detail_text" v-model="form.detail_text" type="text" class="form-control form-control-sm" maxlength="200"
                                       :placeholder="specifies === 'Illness' ? 'Optional — e.g. Acute gastroenteritis' : (specifies === 'Country' ? 'e.g. Singapore' : 'Optional — e.g. Zamboanga City')"
                                       :class="{ 'is-invalid': form.errors.detail_text }">
                                <div class="invalid-feedback">{{ form.errors.detail_text }}</div>
                                <div v-if="specifies !== 'Illness'" class="form-text">One short line on the form.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── 3. Days and dates (6.C and 6.D) ───────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>3</span>Days and dates</h2>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="start_date" class="form-label">First day *</label>
                        <input id="start_date" v-model="form.start_date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.start_date }">
                        <div class="invalid-feedback">{{ form.errors.start_date }}</div>
                    </div>
                    <div class="col-md-3">
                        <label for="end_date" class="form-label">Last day *</label>
                        <input id="end_date" v-model="form.end_date" type="date" class="form-control" :min="form.start_date"
                               :class="{ 'is-invalid': form.errors.end_date }">
                        <div class="invalid-feedback">{{ form.errors.end_date }}</div>
                    </div>
                    <div class="col-md-3">
                        <label for="working_days" class="form-label">Working days applied for *</label>
                        <input id="working_days" v-model="form.working_days" type="number" min="0.5" step="0.5" class="form-control"
                               :class="{ 'is-invalid': form.errors.working_days }">
                        <div class="invalid-feedback">{{ form.errors.working_days }}</div>
                        <div v-if="countedDays !== null" class="form-text">
                            {{ countedDays }} weekday{{ countedDays === 1 ? '' : 's' }} — take out holidays; half a day is 0.5.
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-label">Commutation</div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Commutation">
                            <template v-for="choice in [{ v: false, l: 'Not requested' }, { v: true, l: 'Requested' }]" :key="choice.l">
                                <input :id="`commutation-${choice.v}`" v-model="form.commutation_requested" type="radio" class="btn-check" name="commutation" :value="choice.v">
                                <label class="btn btn-outline-secondary" :for="`commutation-${choice.v}`">{{ choice.l }}</label>
                            </template>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="inclusive_dates" class="form-label">Inclusive dates, as printed</label>
                        <input id="inclusive_dates" v-model="form.inclusive_dates" type="text" class="form-control" maxlength="100"
                               :placeholder="wordedDates || 'From the dates above'" :class="{ 'is-invalid': form.errors.inclusive_dates }">
                        <div class="invalid-feedback">{{ form.errors.inclusive_dates }}</div>
                        <div class="form-text">Leave blank to print the dates above. Type your own for days not in a row, e.g. “October 7, 9 and 12, 2026”.</div>
                    </div>
                    <div v-if="printedDays" class="col-md-6 align-self-center">
                        <div class="small text-body-secondary">On the form</div>
                        <div class="fw-semibold">{{ printedDays }} · {{ form.inclusive_dates || wordedDates }}</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── 4. Signatories ────────────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>4</span>Signatories</h2>
                <SignatoryFields :form="form" :roles="signatories" />
            </div>
        </section>

        <!-- ─── 5. Tracking ───────────────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>5</span>After filing it</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="submitted_on" class="form-label">Submitted on</label>
                        <input id="submitted_on" v-model="form.submitted_on" type="date" class="form-control" :class="{ 'is-invalid': form.errors.submitted_on }">
                        <div class="invalid-feedback">{{ form.errors.submitted_on }}</div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-label">Decision</div>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Decision">
                            <template v-for="choice in [{ value: '', label: 'Pending' }, ...options.outcomes]" :key="choice.value">
                                <input :id="`outcome-${choice.value || 'pending'}`" v-model="form.outcome" type="radio" class="btn-check" name="outcome" :value="choice.value">
                                <label class="btn btn-outline-secondary" :for="`outcome-${choice.value || 'pending'}`">{{ choice.label }}</label>
                            </template>
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
                    <span class="small text-danger ms-md-3">Delete this leave application?</span>
                    <button type="button" class="btn btn-sm btn-danger" @click="destroy">Delete</button>
                    <button type="button" class="btn btn-sm btn-link" @click="confirmingDelete = false">Keep</button>
                </template>
                <button v-else type="button" class="btn btn-sm btn-outline-danger ms-md-3" @click="confirmingDelete = true">
                    <i class="ri-delete-bin-line me-1"></i>Delete
                </button>
            </template>

            <button type="submit" form="leave-form" class="btn btn-primary ms-auto" :disabled="form.processing">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ isEdit ? 'Save changes' : 'Save leave application' }}
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

.leave-types .form-check-label { line-height: 1.25; }
.basis { font-size: .6875rem; }
.details-panel {
    border-left: 3px solid var(--brand);
    padding-left: 1rem;
}

.save-bar {
    position: sticky;
    bottom: .75rem;
    z-index: 20;
    box-shadow: var(--shadow-pop);
}
</style>
