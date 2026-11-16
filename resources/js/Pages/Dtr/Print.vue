<script>
/** The month's blank DTR entries and why, on one page to print and sign. */
export default {
    layout: null,

    props: {
        person: { type: Object, required: true },
        month: { type: Object, required: true },
        record: { type: Object, required: true },
        slots: { type: Array, required: true },
        reasons: { type: Array, required: true },
        inCharge: { type: Object, required: true },
    },

    computed: {
        notesById() {
            return Object.fromEntries(this.record.notes.map(n => [n.id, n]));
        },
        // A row a day that has blanks or a note, a line per explanation.
        entries() {
            const formLabel = Object.fromEntries(this.slots.map(s => [s.value, s.form_label]));
            const reason = Object.fromEntries(this.reasons.map(r => [r.value, r.label]));

            return this.record.days.flatMap((day) => {
                const notes = day.notes.map(id => this.notesById[id]).filter(Boolean);
                if (day.status === 'off' || (!day.blanks.length && !notes.length)) return [];

                const lines = [
                    ...(day.blanks.length ? day.auto : []).map(a => ({ entry: 'Whole day', text: `${a.label} — ${a.detail}`, actual: '' })),
                    ...notes.map(n => ({
                        entry: n.form_slots_label,
                        text: reason[n.reason] + (n.details ? ` — ${n.details}` : ''),
                        actual: this.clock(n.actual_time),
                    })),
                ];
                if (day.unexplained.length) {
                    lines.push({ entry: day.unexplained.map(s => formLabel[s]).join(', '), text: 'No explanation yet', actual: '', missing: true });
                }
                return [{ day, lines }];
            });
        },
        missing() {
            return this.record.summary.unexplained;
        },
    },

    mounted() {
        document.title = `DTR explanations — ${this.person.name} — ${this.month.label}`;
    },

    methods: {
        print() {
            window.print();
        },

        // "07:05" → "7:05 AM"
        clock(time) {
            if (!time) return '';
            const [h, m] = time.split(':').map(Number);
            return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
        },

        dayLabel(day) {
            const [y, m, d] = day.date.split('-').map(Number);
            return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', weekday: 'short' });
        },
    },
};
</script>

<template>
    <div data-bs-theme="light" class="print-sheet">
        <div class="toolbar d-print-none">
            <button type="button" class="btn btn-primary btn-sm" @click="print"><i class="ri-printer-line me-1"></i>Print</button>
            <Link :href="route('dtr.index', { person: person.id, month: month.key })" class="btn btn-link btn-sm">Back to the DTR</Link>
            <span v-if="missing" class="small text-danger ms-auto">
                <i class="ri-error-warning-line"></i> {{ missing }} day{{ missing === 1 ? ' still has' : 's still have' }} blanks without a reason.
            </span>
        </div>

        <div class="page">
            <p class="small fst-italic mb-1">Attachment to the Daily Time Record (CSC Form No. 48)</p>
            <h1 class="title">EXPLANATION OF BLANK DTR ENTRIES</h1>
            <table class="meta">
                <tbody>
                    <tr><th>Name</th><td>{{ person.name }}</td></tr>
                    <tr><th>For the month of</th><td>{{ month.label }}</td></tr>
                </tbody>
            </table>

            <table v-if="entries.length" class="entries">
                <thead>
                    <tr>
                        <th class="col-date">Date</th>
                        <th class="col-entry">Entry</th>
                        <th>Explanation</th>
                        <th class="col-actual">Actual time</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="e in entries" :key="e.day.date">
                        <tr v-for="(line, i) in e.lines" :key="i" :class="{ missing: line.missing }">
                            <td v-if="i === 0" :rowspan="e.lines.length" class="col-date">{{ dayLabel(e.day) }}</td>
                            <td class="col-entry">{{ line.entry }}</td>
                            <td>{{ line.text }}</td>
                            <td class="col-actual">{{ line.actual }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <p v-else class="none">No blank entries this month.</p>

            <p class="certify">
                I certify that the above explains the blank entries in my Daily Time Record for the month.
            </p>

            <div class="signatures">
                <div>
                    <div class="small mb-4">Certified correct:</div>
                    <div class="line">{{ person.name }}</div>
                    <div class="role">Employee</div>
                </div>
                <div>
                    <div class="small mb-4">Noted:</div>
                    <div class="line">{{ inCharge.name }}</div>
                    <div class="role">{{ inCharge.title }}</div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.print-sheet {
    min-height: 100vh;
    background: #E9ECEF;
    color: #000;
    padding: 1rem;
}
.toolbar {
    display: flex;
    align-items: center;
    gap: .5rem;
    max-width: 210mm;
    margin: 0 auto .75rem;
}
.page {
    max-width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 18mm 16mm;
    background: #fff;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10pt;
    box-shadow: 0 2px 10px rgba(0, 0, 0, .15);
}
.title { font-size: 12pt; font-weight: 700; text-align: center; text-decoration: underline; margin: 0 0 1rem; }
.meta { margin-bottom: 1rem; }
.meta th { font-weight: 400; padding-right: 1rem; white-space: nowrap; }
.meta td { font-weight: 700; }
.entries { width: 100%; border-collapse: collapse; }
.entries th, .entries td { border: 1px solid #000; padding: 4px 6px; vertical-align: top; }
.entries th { font-size: 9pt; text-align: center; background: #F1F3F5; }
.col-date { width: 22mm; white-space: nowrap; }
.col-entry { width: 38mm; }
.col-actual { width: 22mm; text-align: center; }
.missing td:not(.col-date) { font-style: italic; color: #B02A37; }
.none { font-style: italic; }
.certify { margin: 1.5rem 0 2.5rem; text-indent: 2em; }
.signatures { display: flex; justify-content: space-between; gap: 2rem; }
.signatures > div { width: 45%; text-align: center; }
.signatures .small { text-align: left; }
.signatures .line { border-top: 1px solid #000; padding-top: 2px; font-weight: 700; margin-top: 2rem; }
.role { font-size: 9pt; }

@media print {
    .print-sheet { padding: 0; background: #fff; min-height: 0; }
    .page { box-shadow: none; padding: 0; min-height: 0; max-width: none; }
    .missing td:not(.col-date) { color: #000; }
    @page { size: A4; margin: 16mm; }
}
</style>
