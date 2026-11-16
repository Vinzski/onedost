<script>
import { peso, date } from '@/Shared/format.js';
import SelectOrOther from '@/Components/ui/SelectOrOther.vue';
import { applyLunch, loadPlaces, perDiemRate } from './places.js';
import { legKind, OFFICE, startingFares, TERMINAL } from './fares.js';

const OFFICE_NAME = 'DOST IX Office';

/** Where the trip leaves Zamboanga from: the office by land, or a terminal (options.terminals). */
const LEAVING_FROM = [
    { key: 'office', label: 'DOST IX Office (by land)' },
    { key: 'airport', label: 'Airport (flight)', means: 'Airplane' },
    { key: 'port', label: 'Wharf (ferry)', means: 'Ferry' },
    { key: 'bus', label: 'Bus terminal (bus)', means: 'Public' },
];

/** 'YYYY-MM-DD' strings for every day from `from` to `to`. */
function daysBetween(from, to) {
    const days = [];
    const [y, m, d] = from.split('-').map(Number);
    const cursor = new Date(y, m - 1, d);
    const [ty, tm, td] = to.split('-').map(Number);
    const end = new Date(ty, tm - 1, td);
    while (cursor <= end && days.length < 60) {
        days.push(`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`);
        cursor.setDate(cursor.getDate() + 1);
    }
    return days;
}

function leg(date, place, means, extra = {}) {
    return {
        date, place, departure_time: '', arrival_time: '', transport_means: means,
        transport_cost: '', per_diem: '', other_cost: '', ...extra,
    };
}

export default {
    components: { SelectOrOther },

    props: {
        // The page's useForm(); this editor works on form.items.
        form: { type: Object, required: true },
        options: { type: Object, required: true },
    },

    data() {
        return {
            confirmingStarter: false,
            // The picked destination's name, for the starter's legs.
            placeName: '',
            leavingFrom: 'office',
            leavingOptions: LEAVING_FROM,
            showGuide: false,
            // Set when the starter was clicked before the trip details were in.
            askedToStart: false,
        };
    },

    watch: {
        'form.destination_code': {
            immediate: true,
            handler(code) {
                if (!code) {
                    this.placeName = '';
                    return;
                }
                loadPlaces().then(({ byCode }) => {
                    if (this.form.destination_code === code) this.placeName = byCode.get(code)?.name ?? '';
                }).catch(() => {});
            },
        },
    },

    computed: {
        items() {
            return this.form.items;
        },
        // What the starter still needs from section 2, first one first: [{ label, field }].
        missingForStart() {
            const f = this.form;
            const missing = [];
            if (!f.departure_date) missing.push({ label: 'departure date', field: 'departure_date' });
            if (!f.return_date) missing.push({ label: 'return date', field: 'return_date' });
            else if (f.departure_date && f.return_date < f.departure_date) missing.push({ label: 'a return date on or after departure', field: 'return_date' });
            if (!f.destination_code && !(f.venue || '').trim()) missing.push({ label: 'destination', field: 'destination_code' });
            return missing;
        },
        // The picked destination, else the venue's first part: "Pagadian City, Zamboanga del Sur" → "Pagadian City".
        destination() {
            return this.placeName || (this.form.venue || '').split(',')[0].trim() || 'destination';
        },
        // EO 77 rate for the destination's region.
        rate() {
            return perDiemRate(this.form.destination_code, this.options);
        },
        // Less per diem on each day there when lunch is provided at the activity.
        lunch() {
            return Number(this.options.lunchDeduction || 0);
        },
        // This traveller's fares from home (fares memo), or the defaults until set.
        fares() {
            return startingFares(this.form, this.options);
        },
        // The memo's tables, for the guide: [{ label, office: [[distance, fare]], terminal: [...] }].
        guide() {
            return Object.values(this.options.homeFares ?? {}).map(area => ({
                label: area.label,
                office: Object.entries(area.office),
                terminal: Object.entries(area.terminal),
            }));
        },
        totals() {
            const sum = key => this.items.reduce((total, item) => total + Number(item[key] || 0), 0);
            const t = { transport: sum('transport_cost'), perDiem: sum('per_diem'), others: sum('other_cost') };
            return { ...t, all: t.transport + t.perDiem + t.others };
        },
    },

    methods: {
        peso,
        date,

        error(index, field) {
            return this.form.errors[`items.${index}.${field}`];
        },

        rowErrors(index) {
            return Object.entries(this.form.errors)
                .filter(([key]) => key.startsWith(`items.${index}.`))
                .map(([, message]) => message);
        },

        // The first leg starts on the departure date; later ones continue the day above until given a date.
        addLeg() {
            const last = this.items[this.items.length - 1];
            this.items.push(leg(last ? '' : (this.form.departure_date || ''), '', last?.transport_means || ''));
        },

        // A residence leg typed in with no fare yet gets the traveller's own.
        fillFare(item) {
            const kind = legKind(item.place);
            if (kind && (item.transport_cost === '' || item.transport_cost === null)) {
                item.transport_cost = this.fares[kind];
            }
        },

        // Lunch ticked or unticked: per diem still at the old figure on the days there follows.
        onLunch(provided) {
            const changed = applyLunch(this.form, this.rate, this.lunch, provided);
            if (!changed) return;
            const days = changed === 1 ? 'the day' : `${changed} days`;
            this.$toast.add(provided
                ? `Per diem changed to ${peso(this.rate - this.lunch)} on ${days} with lunch provided.`
                : `Per diem changed back to ${peso(this.rate)} on ${days}.`, 'info');
        },

        removeLeg(index) {
            this.items.splice(index, 1);
            this.form.clearErrors();
        },

        move(index, step) {
            const target = index + step;
            if (target < 0 || target >= this.items.length) return;
            const [item] = this.items.splice(index, 1);
            this.items.splice(target, 0, item);
        },

        startTrip() {
            // Not ready: say what's missing and take them to it.
            if (this.missingForStart.length) {
                this.askedToStart = true;
                const field = document.getElementById(this.missingForStart[0].field);
                field?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                field?.focus({ preventScroll: true });
                return;
            }
            this.askedToStart = false;

            if (this.items.length && !this.confirmingStarter) {
                this.confirmingStarter = true;
                return;
            }
            this.confirmingStarter = false;

            const days = daysBetween(this.form.departure_date, this.form.return_date);
            const rate = this.rate;
            // A day there, between the days going and coming back.
            const dayThere = rate - (this.form.lunch_provided ? this.lunch : 0);
            const dest = this.destination;
            const single = days.length === 1;

            // By land from the office (the usual deployment), or out through a terminal.
            const from = LEAVING_FROM.find(o => o.key === this.leavingFrom) ?? LEAVING_FROM[0];
            const byLand = from.key === 'office';
            const hub = byLand ? OFFICE_NAME : this.options.terminals[from.key];
            const home = { transport_cost: this.fares[byLand ? OFFICE : TERMINAL] };
            const homeMeans = byLand ? 'Public' : 'Tricycle';
            const tripMeans = byLand ? 'Official Vehicle' : from.means;
            const stayMeans = byLand ? 'Official Vehicle' : '';
            // The times most IOTs use; flights and sailings vary, so those are left to fill in.
            const at = (departure, arrival) => ({ departure_time: departure, arrival_time: arrival });
            const landAt = (departure, arrival) => (byLand && !single ? at(departure, arrival) : {});
            const legs = [];

            days.forEach((day, i) => {
                const first = i === 0;
                const last = i === days.length - 1;
                if (first) {
                    legs.push(leg(day, `Residence to ${hub}`, homeMeans, { ...home, per_diem: rate, ...(byLand ? at('07:00', '08:00') : {}) }));
                    legs.push(leg(day, `${hub} to ${dest}`, tripMeans, landAt('10:00', '14:00')));
                    if (!single) legs.push(leg(day, `Still in ${dest}`, stayMeans, landAt('14:00', '')));
                } else if (!last) {
                    legs.push(leg(day, `Still in ${dest}`, stayMeans, { per_diem: dayThere, ...at('08:00', '') }));
                }
                if (last) {
                    legs.push(leg(day, `${dest} to ${hub}`, tripMeans, { ...(first ? {} : { per_diem: rate / 2 }), ...landAt('08:00', '14:00') }));
                    legs.push(leg(day, `${hub} to Residence`, homeMeans, { ...home, ...landAt('14:00', '15:00') }));
                }
            });

            this.items.splice(0, this.items.length, ...legs);
            this.form.clearErrors();
        },
    },
};
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div class="input-group input-group-sm w-auto">
                <button type="button" class="btn btn-outline-primary" @click="startTrip">
                    <i class="ri-magic-line me-1"></i>Start from a typical trip
                </button>
                <label class="input-group-text" for="leaving_from">leaving from</label>
                <select id="leaving_from" v-model="leavingFrom" class="form-select leaving-from">
                    <option v-for="o in leavingOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
                </select>
            </div>
            <template v-if="confirmingStarter">
                <span class="small text-danger">This replaces the {{ items.length }} legs below.</span>
                <button type="button" class="btn btn-sm btn-danger" @click="startTrip">Replace</button>
                <button type="button" class="btn btn-sm btn-link" @click="confirmingStarter = false">Keep mine</button>
            </template>
            <span v-else-if="askedToStart && missingForStart.length" class="small text-danger" role="status">
                <i class="ri-error-warning-line me-1"></i>First fill in the
                {{ missingForStart.map(m => m.label).join(', ').replace(/, ([^,]*)$/, ' and $1') }} under Travel order above.
            </span>
            <span v-else class="small text-body-secondary">
                Fills in the usual legs for the trip's dates and destination. Per diem {{ peso(rate) }}/day
                {{ form.destination_code ? '' : '(pick a destination for its EO 77 rate) ' }}— half on the day home{{
                    form.lunch_provided && lunch ? `, ${peso(rate - lunch)} on days with lunch provided` : '' }}; fares from home
                {{ peso(fares.office) }} to the office, {{ peso(fares.terminal) }} to a terminal. Adjust any figure after.
            </span>
        </div>

        <div v-if="lunch" class="form-check mb-3">
            <input id="lunch_provided" v-model="form.lunch_provided" class="form-check-input" type="checkbox"
                   @change="onLunch($event.target.checked)">
            <label class="form-check-label" for="lunch_provided">Lunch provided at the activity</label>
            <span class="small text-body-secondary ms-1">
                — {{ peso(lunch) }} less per diem each day there; not on the days going and coming back.
            </span>
        </div>

        <div v-if="form.errors.items" class="alert alert-danger py-2 small">{{ form.errors.items }}</div>

        <div class="table-responsive">
            <table class="table table-sm align-middle itinerary mb-2">
                <thead>
                    <tr>
                        <th scope="col" class="w-date">Date</th>
                        <th scope="col" class="w-place">Place(s) visited</th>
                        <th scope="col" class="w-time">Depart</th>
                        <th scope="col" class="w-time">Arrive</th>
                        <th scope="col" class="w-means">Means</th>
                        <th scope="col" class="text-end w-money">Transp'n</th>
                        <th scope="col" class="text-end w-money">Per diem</th>
                        <th scope="col" class="text-end w-money">Others</th>
                        <th scope="col" class="w-actions"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="(item, i) in items" :key="i">
                        <tr>
                            <td>
                                <input v-model="item.date" type="date" class="form-control form-control-sm" :class="{ 'is-invalid': error(i, 'date') }"
                                       :min="form.departure_date" :max="form.return_date" :aria-label="`Leg ${i + 1} date (optional)`"
                                       title="Optional — leave blank to continue the day above">
                                <div v-if="!item.date && i > 0" class="small text-body-tertiary continues">same day</div>
                            </td>
                            <td>
                                <input v-model="item.place" type="text" class="form-control form-control-sm" :class="{ 'is-invalid': error(i, 'place') }"
                                       placeholder="e.g. Residence to DOST IX Office" :aria-label="`Leg ${i + 1} place`" @change="fillFare(item)">
                            </td>
                            <td>
                                <input v-model="item.departure_time" type="time" class="form-control form-control-sm"
                                       :class="{ 'is-invalid': error(i, 'departure_time') }" :aria-label="`Leg ${i + 1} departure time`">
                            </td>
                            <td>
                                <input v-model="item.arrival_time" type="time" class="form-control form-control-sm"
                                       :class="{ 'is-invalid': error(i, 'arrival_time') }" title="Leave blank for “Onwards”"
                                       :aria-label="`Leg ${i + 1} arrival time`">
                            </td>
                            <td>
                                <SelectOrOther v-model="item.transport_means" :options="options.transportMeans" size="sm" :maxlength="40" clearable
                                               :invalid="!!error(i, 'transport_means')" :aria-label="`Leg ${i + 1} means of transport`" />
                            </td>
                            <td>
                                <input v-model="item.transport_cost" type="number" min="0" step="0.01" class="form-control form-control-sm text-end num"
                                       :class="{ 'is-invalid': error(i, 'transport_cost') }" :aria-label="`Leg ${i + 1} transportation`">
                            </td>
                            <td>
                                <input v-model="item.per_diem" type="number" min="0" step="0.01" class="form-control form-control-sm text-end num"
                                       :class="{ 'is-invalid': error(i, 'per_diem') }" :aria-label="`Leg ${i + 1} per diem`">
                            </td>
                            <td>
                                <input v-model="item.other_cost" type="number" min="0" step="0.01" class="form-control form-control-sm text-end num"
                                       :class="{ 'is-invalid': error(i, 'other_cost') }" :aria-label="`Leg ${i + 1} other expenses`">
                            </td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-link px-1" :disabled="i === 0" :aria-label="`Move leg ${i + 1} up`" @click="move(i, -1)">
                                    <i class="ri-arrow-up-line"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-link px-1" :disabled="i === items.length - 1" :aria-label="`Move leg ${i + 1} down`" @click="move(i, 1)">
                                    <i class="ri-arrow-down-line"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-link px-1 text-danger" :aria-label="`Remove leg ${i + 1}`" @click="removeLeg(i)">
                                    <i class="ri-close-line"></i>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="rowErrors(i).length" class="error-row">
                            <td colspan="9" class="small text-danger pt-0">{{ rowErrors(i).join(' ') }}</td>
                        </tr>
                    </template>
                    <tr v-if="!items.length">
                        <td colspan="9" class="text-center text-body-secondary py-4">
                            No legs yet — start from a typical trip, or add the first leg.
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="items.length">
                    <tr class="fw-semibold">
                        <td colspan="5" class="text-end">Total</td>
                        <td class="text-end num">{{ peso(totals.transport) }}</td>
                        <td class="text-end num">{{ peso(totals.perDiem) }}</td>
                        <td class="text-end num">{{ peso(totals.others) }}</td>
                        <td></td>
                    </tr>
                    <tr class="fw-bold">
                        <td colspan="7" class="text-end border-0">Itinerary total</td>
                        <td colspan="2" class="text-end num border-0">{{ peso(totals.all) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <button type="button" class="btn btn-sm btn-outline-secondary" @click="addLeg">
            <i class="ri-add-line me-1"></i>Add leg
        </button>
        <span class="small text-body-secondary ms-2">Dates are optional — a leg without one continues the day above, as on the paper IOT.</span>

        <!-- The fares memo, for rides the form can't fill in itself (once at the destination). -->
        <div class="mt-3">
            <button type="button" class="btn btn-sm btn-link px-0" :aria-expanded="showGuide" aria-controls="fare-guide" @click="showGuide = !showGuide">
                <i :class="showGuide ? 'ri-arrow-down-s-line' : 'ri-arrow-right-s-line'"></i> Fares memo — most you can claim per ride
            </button>
            <div v-show="showGuide" id="fare-guide" class="fare-guide small mt-2">
                <p class="text-body-secondary mb-2">
                    Residence legs use the traveller's own fare (section 1). Once at the destination, enter the actual fare up to these.
                    No receipt: up to {{ peso(options.cenrrMaxAmount) }} on a CENRR; more needs a Reimbursement Expense Receipt.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="fw-semibold mb-1">Tricycle to the airport, wharf or bus terminal</div>
                        <table class="table table-sm mb-0">
                            <tbody>
                                <template v-for="area in guide" :key="area.label">
                                    <tr v-for="([distance, fare], j) in area.terminal" :key="distance">
                                        <th v-if="j === 0" :rowspan="area.terminal.length" scope="row" class="fw-normal">{{ area.label }}</th>
                                        <td>{{ distance }}</td>
                                        <td class="text-end num">{{ peso(fare) }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="fw-semibold mb-1">Residence to office and back</div>
                        <table class="table table-sm mb-2">
                            <tbody>
                                <template v-for="area in guide" :key="area.label">
                                    <tr v-for="([distance, fare], j) in area.office" :key="distance">
                                        <th v-if="j === 0" :rowspan="area.office.length" scope="row" class="fw-normal">{{ area.label }}</th>
                                        <td>{{ distance }}</td>
                                        <td class="text-end num">{{ peso(fare) }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div class="fw-semibold mb-1">Taxi or TNVS outside Zamboanga Peninsula</div>
                        <div>Actual cost with the receipt; without one, up to {{ peso(options.taxiWithoutReceipt) }} on a CENRR.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.itinerary th {
    font-size: .6875rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: var(--ink-soft);
    white-space: nowrap;
}
/* Wide enough that the place name stays readable; narrower screens scroll sideways. */
.itinerary { min-width: 68rem; table-layout: fixed; }
.itinerary td { padding: .15rem; }
.error-row td { border-top: 0; }
.continues { font-size: .6875rem; line-height: 1; padding: .15rem 0 0 .4rem; }
.w-date { width: 8.5rem; }
.w-time { width: 6.5rem; }
.w-means { width: 9.25rem; }
.w-money { width: 5.25rem; }
.w-actions { width: 4.9rem; }
.leaving-from { max-width: 13.5rem; }
.fare-guide {
    border: 1px solid var(--hairline);
    border-radius: var(--r-lg);
    padding: .75rem 1rem;
}
.fare-guide th, .fare-guide td { padding: .15rem .4rem; }

/* Room for "12:45 PM" without widening the column. */
.itinerary input[type="time"] { padding-left: .35rem; padding-right: .25rem; }
.itinerary input[type="time"]::-webkit-calendar-picker-indicator { margin-left: 0; padding: 0; }

/* Spinner arrows eat the space amounts need. */
.itinerary input[type="number"] { appearance: textfield; -moz-appearance: textfield; }
.itinerary input[type="number"]::-webkit-outer-spin-button,
.itinerary input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
</style>
