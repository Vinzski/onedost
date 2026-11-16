<script>
import { router } from '@inertiajs/vue3';
import { date } from '@/Shared/format.js';
import NoteForm from '@/Components/Dtr/NoteForm.vue';

const STATUS = {
    unexplained: { icon: 'ri-error-warning-fill', text: 'Blank — not yet explained', class: 'text-warning' },
    explained: { icon: 'ri-checkbox-circle-fill', text: 'Blanks explained', class: 'text-success' },
    complete: { icon: 'ri-check-line', text: 'Complete', class: 'text-body-tertiary' },
};

export default {
    components: { NoteForm },

    props: {
        person: { type: Object, default: null },
        people: { type: Array, default: () => [] },
        month: { type: Object, required: true },
        today: { type: String, required: true },
        // The month's days, notes and figures; null until there's a person.
        record: { type: Object, default: null },
        // Other imported months with blanks still to explain.
        elsewhere: { type: Array, default: () => [] },
        options: { type: Object, required: true },
    },

    data() {
        return {
            onlyToExplain: false,
            importing: false,
            importError: null,
            // The day dialog: which day, which times to start with, which note is being changed.
            openDay: null,
            startSlots: [],
            editing: null,
            dialog: false,
        };
    },

    computed: {
        days() {
            const days = this.record?.days ?? [];
            return this.onlyToExplain ? days.filter(d => d.status === 'unexplained') : days;
        },
        summary() {
            return this.record?.summary ?? {};
        },
        notesById() {
            return Object.fromEntries((this.record?.notes ?? []).map(n => [n.id, n]));
        },
        slotsByValue() {
            return Object.fromEntries(this.options.slots.map(s => [s.value, s]));
        },
        reasonsByValue() {
            return Object.fromEntries(this.options.reasons.map(r => [r.value, r]));
        },
        isThisMonth() {
            return this.today.startsWith(this.month.key);
        },
        day() {
            return this.record?.days.find(d => d.date === this.openDay) ?? null;
        },
        dayNotes() {
            return (this.day?.notes ?? []).map(id => this.notesById[id]).filter(Boolean);
        },
        dialogTitle() {
            if (!this.day) return '';
            const [y, m, d] = this.day.date.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
        },
        importedRange() {
            const { imported_from: from, imported_to: to } = this.summary;
            if (!from) return null;
            return from === to ? date(from) : `${Number(from.slice(8))}–${Number(to.slice(8))}`;
        },
    },

    watch: {
        // Inertia keeps this page between people and months: close what belonged to the old one.
        'person.id'() {
            this.dialog = false;
            this.onlyToExplain = false;
        },
        'month.key'() {
            this.dialog = false;
            this.onlyToExplain = false;
        },
        // Nothing left to explain: show the whole month again.
        'summary.unexplained'(count) {
            if (!count) this.onlyToExplain = false;
        },
    },

    methods: {
        date,

        visit(params) {
            router.get(route('dtr.index'), { person: this.person?.id, month: this.month.key, ...params });
        },

        choosePerson(event) {
            this.visit({ person: event.target.value || undefined });
        },

        pickFile() {
            this.importError = null;
            this.$refs.file.click();
        },

        importFile(event) {
            const file = event.target.files[0];
            event.target.value = '';
            if (!file) return;
            router.post(route('dtr.import'), { file }, {
                forceFormData: true,
                onStart: () => { this.importing = true; },
                onFinish: () => { this.importing = false; },
                onError: (errors) => { this.importError = errors.file ?? Object.values(errors)[0]; },
            });
        },

        // The cells of a day's four times: a time, a blank, or a remark spanning the times it covers.
        cells(day) {
            const cells = [];
            for (const slot of this.options.slots) {
                const value = slot.value;
                if (day.remark_slots.includes(value)) {
                    const last = cells[cells.length - 1];
                    if (last?.type === 'remark') last.span++;
                    else cells.push({ type: 'remark', key: value, span: 1, text: day.remark });
                } else if (day.times[value]) {
                    cells.push({ type: 'time', key: value, span: 1, text: day.times[value] });
                } else if (day.blanks.includes(value)) {
                    cells.push({ type: 'blank', key: value, span: 1, explained: !day.unexplained.includes(value), why: this.why(day, value) });
                } else {
                    cells.push({ type: 'none', key: value, span: 1 });
                }
            }
            return cells;
        },

        // What explains a blank: the first note covering it, else a trip or leave.
        why(day, slot) {
            const note = day.notes.map(id => this.notesById[id]).find(n => n && (!n.slots.length || n.slots.includes(slot)));
            if (note) {
                const reason = this.reasonsByValue[note.reason];
                return { icon: reason.icon, text: reason.label + (note.details ? ` — ${note.details}` : '') };
            }
            const auto = day.auto[0];
            return auto ? { icon: auto.icon, text: `${auto.label} — ${auto.detail}` } : null;
        },

        rowClass(day) {
            return {
                [`row-${day.status}`]: true,
                'row-today': day.is_today,
            };
        },

        open(day, slots = [], note = null) {
            this.openDay = day.date;
            this.startSlots = slots;
            this.editing = note;
            this.dialog = true;
        },

        explain(day) {
            // All four times blank: the whole day; otherwise just those.
            this.open(day, day.unexplained.length === this.options.slots.length ? [] : day.unexplained);
        },

        saved() {
            this.dialog = false;
            this.editing = null;
        },

        reasonOf(note) {
            return this.reasonsByValue[note.reason];
        },
    },
};
</script>

<template>
    <Head title="Daily Time Record" />

    <div class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h1 class="h4 mb-1">Daily Time Record</h1>
            <p class="text-body-secondary mb-0">
                Import your DTR, see every blank at a glance, and note why — so you never have to wonder later.
            </p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <select v-if="people.length" class="form-select form-select-sm person-select" :value="person?.id ?? ''" aria-label="Whose DTR"
                    @change="choosePerson">
                <option value="" disabled>Whose DTR?</option>
                <option v-for="p in people" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <input ref="file" type="file" accept="application/pdf,.pdf" hidden @change="importFile">
            <button type="button" class="btn btn-primary" :disabled="importing" @click="pickFile">
                <span v-if="importing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ri-upload-2-line me-1"></i>Import DTR
            </button>
        </div>
    </div>

    <div v-if="importError" class="alert alert-danger d-flex gap-2 small py-2" role="alert">
        <i class="ri-error-warning-line"></i><span>{{ importError }}</span>
    </div>

    <!-- ─── No one yet ────────────────────────────────────────────────── -->
    <section v-if="!person" class="card">
        <div class="card-body text-center py-5">
            <i class="ri-time-line fs-1 text-body-tertiary d-block mb-2"></i>
            <h2 class="h6">Start with your DTR</h2>
            <p class="small text-body-secondary mb-3 mx-auto intro">
                Import the PDF from the DTR system: your name and month are read from it, and every blank stands out.
                Or pick your name above to start noting things before your DTR comes out.
            </p>
            <button type="button" class="btn btn-primary" :disabled="importing" @click="pickFile">
                <i class="ri-upload-2-line me-1"></i>Import DTR
            </button>
        </div>
    </section>

    <template v-else>
        <!-- ─── Note today ────────────────────────────────────────────── -->
        <section class="card mb-3 quick-note">
            <div class="card-body">
                <h2 class="h6 mb-1"><i class="ri-edit-2-line me-1"></i>Something off today?</h2>
                <p class="small text-body-secondary mb-3">
                    No internet, biometric down, out on official business? Note it now, while you remember —
                    it's matched to your DTR when you import it.
                </p>
                <NoteForm :key="`quick-${person.id}`" :person-id="person.id" :options="options" :date="today" :today="today" pick-date id-prefix="quick" />
            </div>
        </section>

        <!-- ─── Other months still waiting ──────────────────────────────── -->
        <div v-if="elsewhere.length" class="alert alert-warning d-flex flex-wrap align-items-center gap-2 small py-2">
            <i class="ri-error-warning-line"></i>
            <span>Still to explain:</span>
            <Link v-for="m in elsewhere" :key="m.month" :href="route('dtr.index', { person: person.id, month: m.month })" class="alert-link">
                {{ m.label }} ({{ m.days }} day{{ m.days === 1 ? '' : 's' }})
            </Link>
        </div>

        <!-- ─── Month ─────────────────────────────────────────────────── -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <Link :href="route('dtr.index', { person: person.id, month: month.previous })" class="btn btn-sm btn-outline-secondary" aria-label="Previous month">
                <i class="ri-arrow-left-s-line"></i>
            </Link>
            <h2 class="h5 mb-0 month-title">{{ month.label }}</h2>
            <Link :href="route('dtr.index', { person: person.id, month: month.next })" class="btn btn-sm btn-outline-secondary" aria-label="Next month">
                <i class="ri-arrow-right-s-line"></i>
            </Link>
            <Link v-if="!isThisMonth" :href="route('dtr.index', { person: person.id })" class="btn btn-sm btn-link">This month</Link>
            <a :href="route('dtr.print', { person: person.id, month: month.key })" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary ms-auto">
                <i class="ri-printer-line me-1"></i>Print explanations
            </a>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-6 col-lg-3">
                <button type="button" class="stat card h-100 w-100 text-start" :class="{ 'stat-active': onlyToExplain, 'stat-warn': summary.unexplained }"
                        :disabled="!summary.unexplained" @click="onlyToExplain = !onlyToExplain">
                    <div class="card-body">
                        <div class="stat-label">To explain</div>
                        <div class="stat-value num">{{ summary.unexplained }}</div>
                        <div class="small text-body-secondary">
                            {{ summary.unexplained ? (onlyToExplain ? 'showing only these — click for all' : 'days with blanks — click to list') : 'nothing left' }}
                        </div>
                    </div>
                </button>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat card h-100">
                    <div class="card-body">
                        <div class="stat-label">Explained</div>
                        <div class="stat-value num">{{ summary.explained }}</div>
                        <div class="small text-body-secondary">days with blanks, each with a reason</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat card h-100">
                    <div class="card-body">
                        <div class="stat-label">Complete</div>
                        <div class="stat-value num">{{ summary.complete }}</div>
                        <div class="small text-body-secondary">of {{ summary.workdays }} working day{{ summary.workdays === 1 ? '' : 's' }} so far</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat card h-100">
                    <div class="card-body">
                        <div class="stat-label">DTR imported</div>
                        <div class="stat-value stat-text">{{ importedRange ?? 'Not yet' }}</div>
                        <div class="small text-body-secondary">
                            {{ importedRange ? 'days of ' + month.label : 'import it when it comes out — notes wait for it' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── The DTR, day by day ────────────────────────────────────── -->
        <section class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0 dtr-table">
                    <thead>
                        <tr>
                            <th scope="col" class="col-day">Day</th>
                            <th v-for="s in options.slots" :key="s.value" scope="col" class="col-time" :title="s.form_label">{{ s.label }}</th>
                            <th scope="col" class="col-undertime">Undertime</th>
                            <th scope="col">Why</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in days" :key="d.date" :class="rowClass(d)">
                            <td class="col-day">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="day-number num">{{ d.day }}</span>
                                    <span class="small text-body-secondary">{{ d.weekday }}</span>
                                    <span v-if="d.is_today" class="badge text-bg-primary">Today</span>
                                </div>
                            </td>

                            <template v-for="c in cells(d)" :key="c.key">
                                <td v-if="c.type === 'remark'" :colspan="c.span" class="remark" :class="{ 'remark-off': c.span === options.slots.length }">
                                    {{ c.text }}
                                </td>
                                <td v-else-if="c.type === 'time'" class="col-time num">{{ c.text }}</td>
                                <td v-else-if="c.type === 'blank'" class="col-time">
                                    <button v-if="c.explained" type="button" class="blank blank-explained" :title="c.why?.text" @click="open(d, [c.key])">
                                        <i :class="c.why?.icon ?? 'ri-check-line'"></i>
                                    </button>
                                    <button v-else type="button" class="blank blank-open" :title="`${slotsByValue[c.key].label} is blank — why?`" @click="open(d, [c.key])">
                                        <i class="ri-question-mark"></i>
                                    </button>
                                </td>
                                <td v-else class="col-time text-body-tertiary">·</td>
                            </template>

                            <td class="col-undertime num small">{{ d.undertime }}</td>

                            <td class="why">
                                <div class="d-flex flex-wrap align-items-center gap-1">
                                    <button v-for="id in d.notes" :key="id" type="button" class="badge note-chip"
                                            :class="`text-bg-${reasonOf(notesById[id]).variant}`" @click="open(d, [], notesById[id])">
                                        <i :class="reasonOf(notesById[id]).icon" class="me-1"></i>{{ reasonOf(notesById[id]).short }}
                                        <span v-if="notesById[id].slots.length" class="fw-normal"> · {{ notesById[id].slots_label }}</span>
                                        <span v-if="notesById[id].details" class="fw-normal note-details"> — {{ notesById[id].details }}</span>
                                    </button>
                                    <a v-for="a in d.auto" :key="a.url" :href="a.url" class="badge text-bg-light border auto-chip text-decoration-none"
                                       :title="`Already in OneDOST: ${a.label} — ${a.detail}`">
                                        <i :class="a.icon" class="me-1"></i>{{ a.label }}<span class="fw-normal"> · {{ a.detail }}</span>
                                    </a>
                                    <button v-if="d.status === 'unexplained'" type="button" class="btn btn-sm btn-warning py-0" @click="explain(d)">
                                        Why blank?
                                    </button>
                                    <button v-else-if="d.status !== 'off'" type="button" class="btn btn-sm btn-link py-0 add-note"
                                            :aria-label="`Add a note for ${d.date}`" @click="open(d)">
                                        <i class="ri-add-line"></i><span class="visually-hidden">Note</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!days.length">
                            <td colspan="7" class="text-center text-body-secondary py-5">Every blank this month is explained.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-body-secondary d-flex flex-wrap gap-3">
                <span><span class="blank blank-open legend"><i class="ri-question-mark"></i></span> blank, not yet explained</span>
                <span><span class="blank blank-explained legend"><i class="ri-check-line"></i></span> blank, explained</span>
                <span><span class="text-body-tertiary">·</span> not on an imported DTR yet</span>
                <span class="ms-md-auto">Trips and leave already in OneDOST explain their days on their own.</span>
            </div>
        </section>

        <!-- ─── One day ───────────────────────────────────────────────── -->
        <BModal v-model="dialog" :title="dialogTitle" size="lg" no-footer @hidden="editing = null">
            <template v-if="day">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span v-for="s in options.slots" :key="s.value" class="time-pill" :class="{ 'time-pill-blank': day.blanks.includes(s.value) }">
                        <span class="text-body-secondary">{{ s.label }}</span>
                        <strong class="num">{{ day.times[s.value] || (day.remark_slots.includes(s.value) ? day.remark : (day.imported ? 'blank' : '—')) }}</strong>
                    </span>
                </div>
                <p v-if="!day.imported" class="small text-body-secondary">
                    This day isn't on an imported DTR yet. Note it anyway — it's matched when you import.
                </p>

                <div v-for="a in day.auto" :key="a.url" class="alert alert-light border d-flex align-items-center gap-2 small py-2">
                    <i :class="a.icon" class="fs-5"></i>
                    <span><strong>{{ a.label }}</strong> · {{ a.detail }} — already explains this day.</span>
                    <a :href="a.url" class="ms-auto">Open</a>
                </div>

                <div v-if="dayNotes.length" class="list-group mb-3">
                    <div v-for="n in dayNotes" :key="n.id" class="list-group-item">
                        <NoteForm v-if="editing?.id === n.id" :key="`edit-${n.id}`" :person-id="person.id" :options="options" :note="n" :today="today"
                                  :id-prefix="`edit-${n.id}`" @saved="saved" @cancel="editing = null" />
                        <div v-else class="d-flex align-items-start gap-2">
                            <i :class="[reasonOf(n).icon, `text-${reasonOf(n).variant === 'light' ? 'body-secondary' : reasonOf(n).variant}`]" class="fs-5"></i>
                            <div class="small me-auto">
                                <div class="fw-semibold">{{ reasonOf(n).label }}</div>
                                <div class="text-body-secondary">
                                    {{ n.slots_label }}<template v-if="n.starts_on !== n.ends_on"> · {{ n.dates_label }}</template>
                                    <template v-if="n.actual_time"> · actually {{ n.actual_time }}</template>
                                </div>
                                <div v-if="n.details">{{ n.details }}</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="editing = n">Edit</button>
                        </div>
                    </div>
                </div>

                <template v-if="!editing">
                    <h3 class="h6 mb-2">{{ dayNotes.length ? 'Add another note' : 'Why is it blank?' }}</h3>
                    <NoteForm :key="`new-${day.date}-${startSlots.join()}`" :person-id="person.id" :options="options" :date="day.date" :slots="startSlots"
                              :today="today" id-prefix="day" @saved="saved" />
                </template>
            </template>
        </BModal>
    </template>
</template>

<style scoped>
.person-select { width: auto; max-width: 16rem; }
.intro { max-width: 34rem; }
.quick-note { border-left: 3px solid var(--brand); }
.month-title { min-width: 10rem; text-align: center; }

.stat { color: var(--ink); text-align: left; transition: border-color .12s ease; }
button.stat:not(:disabled) { cursor: pointer; }
button.stat:not(:disabled):hover, .stat-active { border-color: var(--brand); }
.stat-warn .stat-value { color: var(--bs-warning-text-emphasis); }
.stat-label {
    font-size: .6875rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--ink-soft);
}
.stat-value { font-size: 1.75rem; font-weight: 700; line-height: 1.2; }
.stat-text { font-size: 1.25rem; padding: .2rem 0; }

.dtr-table th {
    font-size: .6875rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--ink-soft);
    background: var(--surface-sunken);
    white-space: nowrap;
}
.dtr-table td { padding-top: .35rem; padding-bottom: .35rem; }
.col-day { width: 7.5rem; white-space: nowrap; }
.col-time { width: 6.5rem; text-align: center; }
.col-undertime { width: 6rem; text-align: center; }
.day-number { display: inline-block; min-width: 1.4rem; font-weight: 700; }

.row-unexplained td:first-child { box-shadow: inset 3px 0 0 var(--bs-warning); }
.row-explained td:first-child { box-shadow: inset 3px 0 0 var(--bs-success); }
.row-off td, .row-upcoming td { color: var(--ink-faint); }
.row-today td { background: var(--brand-subtle); }
[data-bs-theme="dark"] .row-today td { background: rgba(13, 110, 253, .15); }

.remark {
    text-align: center;
    font-size: .6875rem;
    letter-spacing: .3em;
    color: var(--bs-danger);
    font-weight: 700;
}
.remark-off { color: var(--ink-faint); font-weight: 400; background: var(--surface-sunken); }

.blank {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 50%;
    border: 1px dashed;
    background: transparent;
    padding: 0;
    line-height: 1;
}
.blank-open { border-color: var(--bs-warning); color: var(--bs-warning-text-emphasis); background: var(--bs-warning-bg-subtle); }
.blank-open:hover { background: var(--bs-warning); color: #000; }
.blank-explained { border-style: solid; border-color: var(--bs-success-border-subtle); color: var(--bs-success-text-emphasis); background: var(--bs-success-bg-subtle); }
.blank.legend { width: 1.25rem; height: 1.25rem; font-size: .7rem; vertical-align: middle; }

.why { min-width: 14rem; }
.note-chip, .auto-chip { border: 0; font-size: .75rem; max-width: 22rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.add-note { opacity: .4; }
tr:hover .add-note, .add-note:focus { opacity: 1; }

.time-pill {
    display: inline-flex;
    flex-direction: column;
    padding: .35rem .75rem;
    border: 1px solid var(--hairline);
    border-radius: var(--r-lg);
    font-size: .8125rem;
    min-width: 6.5rem;
}
.time-pill-blank { border-color: var(--bs-warning); border-style: dashed; }
</style>
