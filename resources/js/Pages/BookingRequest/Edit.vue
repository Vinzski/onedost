<script>
import { router, useForm } from '@inertiajs/vue3';
import { date } from '@/Shared/format.js';
import SelectOrOther from '@/Components/ui/SelectOrOther.vue';
import SignatoryFields from '@/Components/Travel/SignatoryFields.vue';
import PassengerCard from '@/Components/BookingRequest/PassengerCard.vue';
import { blankPassenger, findTraveler, fromTraveler, missingFor } from '@/Components/BookingRequest/passengers.js';

const TEXT_FIELDS = [
    'travel_order_no', 'purpose', 'fund_source', 'project', 'trip', 'airline', 'origin', 'destination',
    'departure_date', 'departure_etd', 'return_date', 'return_etd', 'baggage_justification',
    'requested_by', 'requested_on', 'submitted_on', 'booking_reference',
];

function initial(booking, defaults) {
    const source = booking ?? defaults;
    const data = Object.fromEntries(TEXT_FIELDS.map(key => [key, source[key] ?? '']));
    data.boarding_passes_submitted = booking?.boarding_passes_submitted ?? null;
    data.signatories = { ...defaults.signatories, ...(booking?.signatories ?? {}) };
    data.passengers = (booking?.passengers ?? [{}]).map(p => blankPassenger(
        Object.fromEntries(Object.entries(p).map(([key, value]) => [key, value ?? (key === 'traveler_id' ? null : '')])),
    ));
    return data;
}

const SIGNATORIES = [
    { key: 'noted', label: 'Noted by', hint: 'Finance and administrative services' },
    { key: 'approved', label: 'Approved by', hint: 'Approves the booking' },
];

export default {
    components: { PassengerCard, SelectOrOther, SignatoryFields },

    props: {
        bookingRequest: { type: Object, default: null },
        defaults: { type: Object, required: true },
        travelers: { type: Array, default: () => [] },
        // Travel orders to start from (new requests only), everyone on each.
        travelOrders: { type: Array, default: () => [] },
        options: { type: Object, required: true },
    },

    data() {
        return {
            form: useForm(initial(this.bookingRequest, this.defaults)),
            confirmingDelete: false,
            signatories: SIGNATORIES,
        };
    },

    watch: {
        // Inertia reuses this component between requests, so start a fresh form.
        'bookingRequest.id'(id, previous) {
            if (id !== previous) {
                this.form = useForm(initial(this.bookingRequest, this.defaults));
                this.confirmingDelete = false;
            }
        },
    },

    computed: {
        isEdit() {
            return this.bookingRequest !== null;
        },
        heading() {
            if (!this.isEdit) return 'New booking request';
            const b = this.bookingRequest;
            return `TO ${b.travel_order_no} · ${b.origin} ${b.trip === 'one_way' ? '→' : '⇄'} ${b.destination}`;
        },
        hasReturn() {
            return this.form.trip !== 'one_way';
        },
        airline() {
            return this.options.airlines.find(a => a.value === this.form.airline);
        },
        missing() {
            return missingFor(this.form, this.hasReturn);
        },
        pages() {
            return Math.ceil(this.form.passengers.length / this.options.passengersPerForm);
        },
        errorCount() {
            return Object.keys(this.form.errors).length;
        },
        // Who can sign as requester: saved travellers and this request's passengers.
        requesterNames() {
            const names = [...this.travelers.map(t => t.name), ...this.form.passengers.map(p => `${p.first_name} ${p.last_name}`.trim().toUpperCase())];
            return [...new Set(names.filter(Boolean))];
        },
        status() {
            const f = this.form;
            if ((f.booking_reference || '').trim()) return { variant: 'success', text: 'Booked' };
            if (f.submitted_on) return { variant: 'info', text: 'With the Supply Unit' };
            return { variant: 'secondary', text: 'Draft' };
        },
    },

    methods: {
        date,

        // Everyone on a travel order, with the trip it is for.
        startFrom(order) {
            const f = this.form;
            Object.assign(f, {
                travel_order_no: order.travel_order_no,
                purpose: order.purpose,
                fund_source: order.fund_source || f.fund_source,
                destination: order.destination,
                departure_date: order.departure_date,
                return_date: order.return_date,
                trip: order.departure_date === order.return_date ? f.trip : 'return',
            });
            f.passengers = order.travelers.map((name) => {
                const saved = findTraveler(this.travelers, name);
                return saved ? fromTraveler(saved) : fromTraveler({ id: null, name });
            });
            f.requested_by = order.travelers[0] ?? f.requested_by;
            f.clearErrors();
            this.$toast.add(`Filled in from TO ${order.travel_order_no} — ${order.travelers.length} passenger${order.travelers.length === 1 ? '' : 's'}. Check the flight times.`, 'info');
        },

        addPassenger() {
            this.form.passengers.push(blankPassenger());
        },

        removePassenger(index) {
            this.form.passengers.splice(index, 1);
            this.form.clearErrors();
        },

        movePassenger(index, step) {
            const target = index + step;
            const list = this.form.passengers;
            if (target < 0 || target >= list.length) return;
            const [passenger] = list.splice(index, 1);
            list.splice(target, 0, passenger);
        },

        submit() {
            const options = {
                preserveScroll: true,
                onError: () => this.$nextTick(() => document.querySelector('.is-invalid')?.scrollIntoView({ behavior: 'smooth', block: 'center' })),
            };
            // A one-way flight sends no return details.
            this.form.transform(data => (data.trip === 'one_way' ? { ...data, return_date: null, return_etd: null } : data));
            if (this.isEdit) {
                this.form.put(route('booking-requests.update', this.bookingRequest.id), options);
            } else {
                this.form.post(route('booking-requests.store'), options);
            }
        },

        destroy() {
            router.delete(route('booking-requests.destroy', this.bookingRequest.id));
        },
    },
};
</script>

<template>
    <Head :title="isEdit ? 'Edit booking request' : 'New booking request'" />

    <Link :href="route('booking-requests.index')" class="small text-decoration-none d-inline-block mb-2">
        <i class="ri-arrow-left-line"></i> All booking requests
    </Link>

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h1 class="h4 mb-1">
                {{ heading }}
                <span v-if="isEdit" :class="`badge text-bg-${status.variant} align-middle ms-1 fs-6`">{{ status.text }}</span>
            </h1>
            <p class="text-body-secondary mb-0">Request for Airline Booking — fills the Supply Unit's form, three passengers to a page.</p>
        </div>
        <a v-if="isEdit" :href="route('booking-requests.download', bookingRequest.id)" class="btn btn-primary">
            <i class="ri-download-2-line me-1"></i>Download form
        </a>
    </div>

    <div v-if="isEdit && form.isDirty" class="alert alert-warning d-flex gap-2 small py-2">
        <i class="ri-error-warning-line"></i>
        <span>You have unsaved changes. The download uses the last saved version — save first.</span>
    </div>

    <!-- ─── Start from a travel order (new requests) ──────────────────────── -->
    <section v-if="!isEdit && travelOrders.length" class="card mb-3 from-order">
        <div class="card-body">
            <h2 class="h6 mb-1"><i class="ri-file-copy-2-line me-1"></i>Start from a travel order</h2>
            <p class="small text-body-secondary mb-2">
                Fills in the trip and everyone on it as passengers, with their saved details — then add the flight times.
            </p>
            <ul class="list-unstyled mb-0">
                <li v-for="order in travelOrders" :key="order.travel_order_no" class="d-flex flex-wrap align-items-center gap-2 py-2 border-top">
                    <div class="small me-auto">
                        <span class="fw-semibold">TO {{ order.travel_order_no }}</span>
                        · {{ order.destination }} · {{ order.travel_dates }}
                        <span class="text-body-secondary">· {{ order.travelers.join(', ') }}</span>
                        <span v-if="order.has_booking" class="badge text-bg-light border ms-1">has a booking request</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="startFrom(order)">Use this</button>
                </li>
            </ul>
        </div>
    </section>

    <!-- ─── What the form still needs ──────────────────────────────────────── -->
    <section class="card mb-3 checklist" aria-labelledby="checklist-title">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h2 id="checklist-title" class="h6 mb-0"><i class="ri-list-check-3 me-1"></i>Required on the form</h2>
                <span v-if="!missing.length" class="small text-success fw-semibold ms-auto"><i class="ri-checkbox-circle-fill"></i> All filled in</span>
                <span v-else class="small text-warning-emphasis ms-auto">{{ missing.length }} still blank</span>
            </div>
            <ul v-if="missing.length" class="small mb-0 mt-1 ps-3">
                <li v-for="item in missing" :key="item">{{ item }}</li>
            </ul>
        </div>
    </section>

    <form id="booking-form" novalidate @submit.prevent="submit">
        <datalist id="booking-travelers">
            <option v-for="t in travelers" :key="t.id" :value="t.name">{{ t.position }}</option>
        </datalist>

        <!-- ─── 1. Flight ─────────────────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>1</span>Flight</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="form-label">Trip</div>
                        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Trip">
                            <template v-for="t in options.trips" :key="t.value">
                                <input :id="`trip-${t.value}`" v-model="form.trip" type="radio" class="btn-check" name="trip" :value="t.value">
                                <label class="btn btn-outline-primary" :for="`trip-${t.value}`">{{ t.label }}</label>
                            </template>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-label">Preferred airline</div>
                        <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Preferred airline">
                            <template v-for="a in options.airlines" :key="a.value">
                                <input :id="`airline-${a.value}`" v-model="form.airline" type="radio" class="btn-check" name="airline" :value="a.value">
                                <label class="btn btn-outline-primary" :for="`airline-${a.value}`">{{ a.label }}</label>
                            </template>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="origin" class="form-label">From *</label>
                        <input id="origin" v-model="form.origin" type="text" class="form-control" :class="{ 'is-invalid': form.errors.origin }">
                        <div class="invalid-feedback">{{ form.errors.origin }}</div>
                    </div>
                    <div class="col-md-6">
                        <label for="destination" class="form-label">To *</label>
                        <input id="destination" v-model="form.destination" type="text" class="form-control" list="booking-destinations"
                               placeholder="e.g. Manila" :class="{ 'is-invalid': form.errors.destination }">
                        <datalist id="booking-destinations">
                            <option v-for="d in options.destinations" :key="d" :value="d" />
                        </datalist>
                        <div class="invalid-feedback">{{ form.errors.destination }}</div>
                    </div>

                    <div class="col-md-3">
                        <label for="departure_date" class="form-label">Departure *</label>
                        <input id="departure_date" v-model="form.departure_date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.departure_date }">
                        <div class="invalid-feedback">{{ form.errors.departure_date }}</div>
                    </div>
                    <div class="col-md-3">
                        <label for="departure_etd" class="form-label">Preferred ETD</label>
                        <input id="departure_etd" v-model="form.departure_etd" type="text" class="form-control" placeholder="12:50 pm - 2:30 pm"
                               maxlength="60" :class="{ 'is-invalid': form.errors.departure_etd }">
                        <div class="invalid-feedback">{{ form.errors.departure_etd }}</div>
                    </div>
                    <template v-if="hasReturn">
                        <div class="col-md-3">
                            <label for="return_date" class="form-label">Return</label>
                            <input id="return_date" v-model="form.return_date" type="date" class="form-control" :min="form.departure_date"
                                   :class="{ 'is-invalid': form.errors.return_date }">
                            <div class="invalid-feedback">{{ form.errors.return_date }}</div>
                        </div>
                        <div class="col-md-3">
                            <label for="return_etd" class="form-label">Preferred ETD</label>
                            <input id="return_etd" v-model="form.return_etd" type="text" class="form-control" placeholder="6:40 am - 8:20 am"
                                   maxlength="60" :class="{ 'is-invalid': form.errors.return_etd }">
                            <div class="invalid-feedback">{{ form.errors.return_etd }}</div>
                        </div>
                    </template>
                    <div class="col-12">
                        <div class="form-text mt-0 mb-1">
                            Free check-in baggage: Cebu Pacific 20 kg, PAL Express 10 kg<template v-if="airline?.baggage"> — this request: {{ airline.baggage }}</template>.
                            Tick each passenger's baggage below.
                        </div>
                        <label for="baggage_justification" class="form-label small mb-1">More than that? Justify</label>
                        <input id="baggage_justification" v-model="form.baggage_justification" type="text" class="form-control form-control-sm"
                               maxlength="255" placeholder="e.g. Carrying training equipment" :class="{ 'is-invalid': form.errors.baggage_justification }">
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── 2. Passengers ─────────────────────────────────────────────── -->
        <section id="section-passengers" class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>2</span>Passengers</h2>
                <div v-if="form.errors.passengers" class="alert alert-danger py-2 small">{{ form.errors.passengers }}</div>
                <div class="row g-3">
                    <template v-for="(passenger, i) in form.passengers" :key="i">
                        <div v-if="i > 0 && i % options.passengersPerForm === 0" class="col-12">
                            <div class="page-break small text-body-secondary">Form page {{ i / options.passengersPerForm + 1 }}</div>
                        </div>
                        <div class="col-md-6 col-xl-4">
                            <PassengerCard :passenger="passenger" :index="i" :count="form.passengers.length" :errors="form.errors"
                                           :travelers="travelers" :has-return="hasReturn"
                                           @remove="removePassenger(i)" @move="step => movePassenger(i, step)" />
                        </div>
                    </template>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mt-3" @click="addPassenger">
                    <i class="ri-user-add-line me-1"></i>Add passenger
                </button>
                <span class="small text-body-secondary ms-2">
                    {{ form.passengers.length }} passenger{{ form.passengers.length === 1 ? '' : 's' }} · {{ pages }} form page{{ pages === 1 ? '' : 's' }}
                </span>
            </div>
        </section>

        <!-- ─── 3. Travel order and funds ─────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>3</span>Travel order and funds</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="travel_order_no" class="form-label">Approved travel order no. *</label>
                        <input id="travel_order_no" v-model="form.travel_order_no" type="text" class="form-control" placeholder="2026-5667"
                               :class="{ 'is-invalid': form.errors.travel_order_no }">
                        <div class="invalid-feedback">{{ form.errors.travel_order_no }}</div>
                    </div>
                    <div class="col-md-4">
                        <label for="fund_source" class="form-label">Source of funds *</label>
                        <SelectOrOther id="fund_source" v-model="form.fund_source" :options="options.fundClusters" :maxlength="60"
                                       :error="form.errors.fund_source" />
                    </div>
                    <div class="col-md-4">
                        <label for="project" class="form-label">Specific project</label>
                        <input id="project" v-model="form.project" type="text" class="form-control" :class="{ 'is-invalid': form.errors.project }">
                        <div class="invalid-feedback">{{ form.errors.project }}</div>
                    </div>
                    <div class="col-12">
                        <label for="purpose" class="form-label">Purpose of travel *</label>
                        <textarea id="purpose" v-model="form.purpose" rows="2" class="form-control" :class="{ 'is-invalid': form.errors.purpose }"></textarea>
                        <div class="invalid-feedback">{{ form.errors.purpose }}</div>
                    </div>
                    <div class="col-12">
                        <span class="form-label me-3">Boarding passes from previous travel submitted?</span>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Boarding passes submitted">
                            <template v-for="choice in [{ v: true, l: 'Yes' }, { v: false, l: 'No' }, { v: null, l: 'Leave blank' }]" :key="choice.l">
                                <input :id="`passes-${choice.l}`" v-model="form.boarding_passes_submitted" type="radio" class="btn-check" name="passes" :value="choice.v">
                                <label class="btn btn-outline-secondary" :for="`passes-${choice.l}`">{{ choice.l }}</label>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── 4. Signatories ────────────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>4</span>Signatories</h2>
                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <label for="requested_by" class="form-label">Requested by *</label>
                        <input id="requested_by" v-model="form.requested_by" type="text" class="form-control" list="booking-requesters"
                               :class="{ 'is-invalid': form.errors.requested_by }">
                        <datalist id="booking-requesters">
                            <option v-for="name in requesterNames" :key="name" :value="name" />
                        </datalist>
                        <div class="invalid-feedback">{{ form.errors.requested_by }}</div>
                    </div>
                    <div class="col-md-3">
                        <label for="requested_on" class="form-label">Date *</label>
                        <input id="requested_on" v-model="form.requested_on" type="date" class="form-control" :class="{ 'is-invalid': form.errors.requested_on }">
                        <div class="invalid-feedback">{{ form.errors.requested_on }}</div>
                    </div>
                </div>
                <SignatoryFields :form="form" :roles="signatories" />
            </div>
        </section>

        <!-- ─── 5. Tracking ───────────────────────────────────────────────── -->
        <section class="card mb-3">
            <div class="card-body">
                <h2 class="section-title"><span>5</span>After sending it</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="submitted_on" class="form-label">Given to the Supply Unit on</label>
                        <input id="submitted_on" v-model="form.submitted_on" type="date" class="form-control" :class="{ 'is-invalid': form.errors.submitted_on }">
                        <div class="invalid-feedback">{{ form.errors.submitted_on }}</div>
                    </div>
                    <div class="col-md-5">
                        <label for="booking_reference" class="form-label">Booking reference</label>
                        <input id="booking_reference" v-model="form.booking_reference" type="text" class="form-control" maxlength="100"
                               placeholder="From the Supply Unit, e.g. PAL-ABC123" :class="{ 'is-invalid': form.errors.booking_reference }">
                        <div class="invalid-feedback">{{ form.errors.booking_reference }}</div>
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
                    <span class="small text-danger ms-md-3">Delete this booking request?</span>
                    <button type="button" class="btn btn-sm btn-danger" @click="destroy">Delete</button>
                    <button type="button" class="btn btn-sm btn-link" @click="confirmingDelete = false">Keep</button>
                </template>
                <button v-else type="button" class="btn btn-sm btn-outline-danger ms-md-3" @click="confirmingDelete = true">
                    <i class="ri-delete-bin-line me-1"></i>Delete
                </button>
            </template>

            <button type="submit" form="booking-form" class="btn btn-primary ms-auto" :disabled="form.processing">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ isEdit ? 'Save changes' : 'Save booking request' }}
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

.checklist, .from-order { border-left: 3px solid var(--brand); }
.page-break {
    display: flex;
    align-items: center;
    gap: .75rem;
}
.page-break::before, .page-break::after { content: ''; flex: 1; border-top: 1px dashed var(--hairline); }

.save-bar {
    position: sticky;
    bottom: .75rem;
    z-index: 20;
    box-shadow: var(--shadow-pop);
}
</style>
