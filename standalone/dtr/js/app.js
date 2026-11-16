/**
 * The DTR page: import your DTR's PDF, see every blank, note why. Records
 * stay in this browser (DtrRecord) and go to and from a JSON file — the
 * "database" you can open in Notepad, back up, and carry to another device.
 */
(function (root) {
    'use strict';

    const { SLOTS, REASONS, REASON } = root.DtrRecord;
    const Record = root.DtrRecord;
    const META_KEY = 'onedost-dtr-meta';

    // ─── One note: which times, why, and the details ────────────────────────

    const NoteForm = {
        props: {
            note: { type: Object, default: null },
            date: { type: String, default: '' },
            slots: { type: Array, default: () => [] },
            // Show the date picker (the quick "today" card); the day dialog already says which day.
            pickDate: { type: Boolean, default: false },
            today: { type: String, default: '' },
            idPrefix: { type: String, default: 'note' },
        },

        emits: ['save', 'remove', 'cancel'],

        data() {
            return {
                form: this.initial(),
                spanning: Boolean(this.note && this.note.ends_on !== this.note.starts_on),
                errors: {},
                confirmingDelete: false,
                slotOptions: SLOTS,
                reasons: REASONS,
            };
        },

        computed: {
            wholeDay() {
                return this.form.slots.length === 0;
            },
            reason() {
                return REASON[this.form.reason] || null;
            },
            // The actual time only means something for one time on one day.
            actualFor() {
                if (this.spanning || this.form.slots.length !== 1) return null;
                return SLOTS.find(s => s.value === this.form.slots[0]);
            },
            isToday() {
                return this.form.starts_on === this.today;
            },
            errorText() {
                return Object.values(this.errors)[0] || null;
            },
        },

        watch: {
            'form.starts_on'(start) {
                if (!this.spanning || this.form.ends_on < start) this.form.ends_on = start;
            },
        },

        methods: {
            initial() {
                const n = this.note;
                return {
                    starts_on: n?.starts_on ?? this.date,
                    ends_on: n?.ends_on ?? this.date,
                    slots: [...(n?.slots ?? this.slots)],
                    reason: n?.reason ?? '',
                    details: n?.details ?? '',
                    actual_time: n?.actual_time ?? '',
                };
            },

            toggle(slot) {
                const slots = this.form.slots;
                this.form.slots = slots.includes(slot) ? slots.filter(s => s !== slot) : [...slots, slot];
            },

            reasonClass(reason) {
                if (this.form.reason !== reason.value) return 'btn-outline-secondary';
                return `btn-${reason.variant === 'light' ? 'secondary' : reason.variant}`;
            },

            stopSpanning() {
                this.spanning = false;
                this.form.ends_on = this.form.starts_on;
            },

            submit() {
                const form = {
                    ...this.form,
                    ends_on: this.spanning ? this.form.ends_on : this.form.starts_on,
                    actual_time: this.actualFor ? this.form.actual_time : '',
                };
                this.errors = Record.noteErrors(form);
                if (this.errorText) return;
                this.$emit('save', form);
                if (!this.note) {
                    this.form = { ...this.initial(), starts_on: this.form.starts_on, ends_on: this.form.starts_on };
                    this.spanning = false;
                }
            },
        },

        template: `
<form class="note-form" novalidate @submit.prevent="submit">
    <div v-if="pickDate" class="d-flex flex-wrap align-items-end gap-2 mb-3">
        <div>
            <label :for="idPrefix + '-date'" class="form-label small mb-1">{{ spanning ? 'First day' : 'Day' }}</label>
            <input :id="idPrefix + '-date'" v-model="form.starts_on" type="date" class="form-control form-control-sm" :class="{ 'is-invalid': errors.starts_on }">
        </div>
        <div v-if="spanning">
            <label :for="idPrefix + '-until'" class="form-label small mb-1">Last day</label>
            <input :id="idPrefix + '-until'" v-model="form.ends_on" type="date" class="form-control form-control-sm" :min="form.starts_on" :class="{ 'is-invalid': errors.ends_on }">
        </div>
        <span v-if="isToday && !spanning" class="badge text-bg-primary mb-2">Today</span>
        <button v-if="today && !isToday" type="button" class="btn btn-link btn-sm px-0 mb-1" @click="form.starts_on = today">Today</button>
        <button v-if="!spanning" type="button" class="btn btn-link btn-sm px-0 mb-1" @click="spanning = true">Several days?</button>
        <button v-else type="button" class="btn btn-link btn-sm px-0 mb-1" @click="stopSpanning">Just one day</button>
    </div>

    <div class="mb-3">
        <div class="form-label small mb-1">What's blank</div>
        <div class="d-flex flex-wrap gap-1" role="group" aria-label="What's blank">
            <button type="button" class="btn btn-sm" :class="wholeDay ? 'btn-primary' : 'btn-outline-primary'" :aria-pressed="wholeDay" @click="form.slots = []">Whole day</button>
            <button v-for="s in slotOptions" :key="s.value" type="button" class="btn btn-sm" :title="s.form_label"
                    :class="form.slots.includes(s.value) ? 'btn-primary' : 'btn-outline-primary'" :aria-pressed="form.slots.includes(s.value)"
                    @click="toggle(s.value)">{{ s.label }}</button>
        </div>
    </div>

    <div class="mb-3">
        <div class="form-label small mb-1">Why</div>
        <div class="d-flex flex-wrap gap-1" role="radiogroup" aria-label="Why">
            <button v-for="r in reasons" :key="r.value" type="button" class="btn btn-sm text-nowrap" :class="reasonClass(r)"
                    role="radio" :aria-checked="form.reason === r.value" @click="form.reason = r.value">
                <i :class="r.icon" class="me-1"></i>{{ r.short }}
            </button>
        </div>
    </div>

    <div class="row g-2 align-items-end">
        <div :class="actualFor ? 'col-sm-8' : 'col-12'">
            <label :for="idPrefix + '-details'" class="form-label small mb-1">Details{{ reason && reason.needs_details ? ' *' : '' }}</label>
            <input :id="idPrefix + '-details'" v-model="form.details" type="text" class="form-control form-control-sm" maxlength="255"
                   :placeholder="reason ? reason.hint : 'Optional'" :class="{ 'is-invalid': errors.details }">
        </div>
        <div v-if="actualFor" class="col-sm-4">
            <label :for="idPrefix + '-actual'" class="form-label small mb-1">Actual {{ actualFor.label.toLowerCase() }}</label>
            <input :id="idPrefix + '-actual'" v-model="form.actual_time" type="time" class="form-control form-control-sm" :class="{ 'is-invalid': errors.actual_time }">
        </div>
        <div v-if="!pickDate && !spanning" class="col-12">
            <button type="button" class="btn btn-link btn-sm px-0" @click="spanning = true">The same for several days?</button>
        </div>
        <div v-if="!pickDate && spanning" class="col-sm-7">
            <label :for="idPrefix + '-until'" class="form-label small mb-1">Through</label>
            <div class="input-group input-group-sm">
                <input :id="idPrefix + '-until'" v-model="form.ends_on" type="date" class="form-control" :min="form.starts_on" :class="{ 'is-invalid': errors.ends_on }">
                <button type="button" class="btn btn-outline-secondary" @click="stopSpanning">Just this day</button>
            </div>
        </div>
    </div>

    <div v-if="errorText" class="small text-danger mt-2"><i class="ri-error-warning-line me-1"></i>{{ errorText }}</div>

    <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
        <template v-if="note">
            <template v-if="confirmingDelete">
                <span class="small text-danger">Delete this note?</span>
                <button type="button" class="btn btn-sm btn-danger" @click="$emit('remove')">Delete</button>
                <button type="button" class="btn btn-sm btn-link" @click="confirmingDelete = false">Keep</button>
            </template>
            <button v-else type="button" class="btn btn-sm btn-outline-danger" @click="confirmingDelete = true"><i class="ri-delete-bin-line me-1"></i>Delete</button>
            <button type="button" class="btn btn-sm btn-link ms-auto" @click="$emit('cancel')">Cancel</button>
        </template>
        <button type="submit" class="btn btn-sm btn-primary" :class="{ 'ms-auto': !note }" :disabled="!form.reason">{{ note ? 'Save changes' : 'Save note' }}</button>
    </div>
</form>`,
    };

    // ─── The page ───────────────────────────────────────────────────────────

    const App = {
        components: { NoteForm },

        data() {
            const today = Record.todayIso();
            return {
                records: Record.load(),
                meta: readMeta(),
                today,
                monthKey: today.slice(0, 7),
                onlyToExplain: false,
                busy: false,
                toasts: [],
                // The day dialog.
                openDay: null,
                startSlots: [],
                editing: null,
                // A file opened while records are already here: merge or replace?
                pendingFile: null,
                printing: false,
                slots: SLOTS,
                reasons: REASON,
            };
        },

        computed: {
            record() {
                return Record.month(this.records, this.monthKey, this.today);
            },
            days() {
                const days = this.record.days;
                return this.onlyToExplain ? days.filter(d => d.status === 'unexplained') : days;
            },
            summary() {
                return this.record.summary;
            },
            notesById() {
                return Object.fromEntries(this.records.notes.map(n => [n.id, n]));
            },
            monthLabel() {
                return Record.monthLabel(this.monthKey);
            },
            isThisMonth() {
                return this.monthKey === this.today.slice(0, 7);
            },
            elsewhere() {
                return Record.monthsToExplain(this.records, this.monthKey, this.today);
            },
            hasRecords() {
                return Record.hasData(this.records);
            },
            person() {
                return Record.displayName(this.records.person);
            },
            day() {
                return this.record.days.find(d => d.date === this.openDay) || null;
            },
            dayNotes() {
                return (this.day?.notes || []).map(id => this.notesById[id]).filter(Boolean);
            },
            dialogTitle() {
                return this.day ? Record.longDate(this.day.date) : '';
            },
            importedRange() {
                const { imported_from: from, imported_to: to } = this.summary;
                return from ? `${from}–${to}` : null;
            },
            // Changed since the last file save: the file is the backup, so say so.
            unsaved() {
                return Boolean(this.meta.changed_at) && (!this.meta.saved_at || this.meta.changed_at > this.meta.saved_at);
            },
            lastSaved() {
                return this.meta.saved_at ? new Date(this.meta.saved_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : null;
            },
            printEntries() {
                return Record.printEntries(this.records, this.monthKey, this.today);
            },
        },

        watch: {
            monthKey() {
                this.onlyToExplain = false;
            },
            'summary.unexplained'(count) {
                if (!count) this.onlyToExplain = false;
            },
        },

        mounted() {
            document.addEventListener('keydown', this.onKeydown);
            // Back on the page another day: "today" moves on.
            document.addEventListener('visibilitychange', this.refreshToday);
        },

        beforeUnmount() {
            document.removeEventListener('keydown', this.onKeydown);
            document.removeEventListener('visibilitychange', this.refreshToday);
        },

        methods: {
            // ─── Keeping the records ────────────────────────────────────────

            commit(next) {
                this.records = next;
                this.meta = { ...this.meta, changed_at: new Date().toISOString() };
                writeMeta(this.meta);
                if (!Record.persist(next)) {
                    this.toast('This browser isn\'t keeping your records (a private window?). Save to a file before you close it.', 'warning');
                }
            },

            refreshToday() {
                this.today = Record.todayIso();
            },

            // ─── Files ──────────────────────────────────────────────────────

            pickPdf() {
                this.$refs.pdf.click();
            },

            async importPdf(event) {
                const file = event.target.files[0];
                event.target.value = '';
                if (!file) return;
                this.busy = true;
                try {
                    const dtr = await root.DtrReader.read(await file.arrayBuffer());
                    if (Record.isSomeoneElse(this.records, dtr)
                        && !root.confirm(`This DTR is for ${Record.displayName(dtr.name)}, but these records are ${this.person}'s. Import it anyway?`)) {
                        return;
                    }
                    this.commit(Record.importDtr(this.records, dtr, file.name));
                    this.monthKey = dtr.month;
                    const days = Object.keys(dtr.days).map(Number);
                    const left = Record.month(this.records, dtr.month, this.today).summary.unexplained;
                    this.toast(`${Record.monthLabel(dtr.month)} imported (days ${Math.min(...days)}–${Math.max(...days)}). `
                        + (left ? `${left} day${left === 1 ? ' has' : 's have'} blanks to explain.` : 'Every blank is explained.'), 'success');
                } catch (e) {
                    this.toast(e instanceof root.DtrReader.DtrReadError ? e.message : `That PDF couldn't be read: ${e.message}`, 'error');
                } finally {
                    this.busy = false;
                }
            },

            pickFile() {
                this.$refs.file.click();
            },

            async openFile(event) {
                const file = event.target.files[0];
                event.target.value = '';
                if (!file) return;
                try {
                    const incoming = Record.fromFile(await file.text());
                    if (this.hasRecords) {
                        this.pendingFile = { name: file.name, records: incoming };
                    } else {
                        this.useFile(incoming, 'replace');
                    }
                } catch (e) {
                    this.toast(e instanceof Record.RecordError ? e.message : `That file couldn't be opened: ${e.message}`, 'error');
                }
            },

            useFile(incoming, how) {
                this.commit(how === 'merge' ? Record.merge(this.records, incoming) : incoming);
                this.pendingFile = null;
                // Nothing new since that file: it's as good as saved.
                if (how === 'replace') {
                    this.meta = { ...this.meta, saved_at: this.meta.changed_at };
                    writeMeta(this.meta);
                }
                const latest = Object.keys(this.records.months).sort().pop();
                if (latest && !this.records.months[this.monthKey]) this.monthKey = latest;
                this.toast(how === 'merge' ? 'File added to your records.' : 'Records opened from the file.', 'success');
            },

            saveFile() {
                const blob = new Blob([Record.toFile(this.records)], { type: 'application/json' });
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `dtr-records-${this.today}.json`;
                document.body.appendChild(link);
                link.click();
                link.remove();
                setTimeout(() => URL.revokeObjectURL(link.href), 1000);
                this.meta = { ...this.meta, saved_at: new Date().toISOString() };
                writeMeta(this.meta);
                this.toast('Saved. Keep the file somewhere safe — it\'s your backup.', 'success');
            },

            print() {
                this.commit(this.records);
                this.printing = false;
                this.$nextTick(() => setTimeout(() => root.print(), 50));
            },

            // ─── Notes ──────────────────────────────────────────────────────

            saveNote(form, id = null) {
                try {
                    this.commit(Record.saveNote(this.records, form, id));
                    this.toast(id ? 'Note updated.' : `Noted: ${REASON[form.reason].label}.`, 'success');
                    this.closeDay();
                } catch (e) {
                    this.toast(e.message, 'error');
                }
            },

            removeNote(id) {
                this.commit(Record.deleteNote(this.records, id));
                this.toast('Note deleted.', 'success');
                this.closeDay();
            },

            // ─── The month ──────────────────────────────────────────────────

            go(months) {
                this.monthKey = Record.addMonths(this.monthKey, months);
            },

            // The cells of a day's four times: a time, a blank, or a remark spanning the times it covers.
            cells(day) {
                const cells = [];
                for (const slot of SLOTS) {
                    const value = slot.value;
                    if (day.remark_slots.includes(value)) {
                        const last = cells[cells.length - 1];
                        if (last && last.type === 'remark') last.span++;
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

            // What explains a blank: the first note covering it.
            why(day, slot) {
                const note = day.notes.map(id => this.notesById[id]).find(n => n && (!n.slots.length || n.slots.includes(slot)));
                if (!note) return null;
                const reason = REASON[note.reason];
                return { icon: reason.icon, text: reason.label + (note.details ? ` — ${note.details}` : '') };
            },

            slotLabel(value) {
                return SLOTS.find(s => s.value === value).label;
            },

            noteLabels(note) {
                return { slots: Record.slotsLabel(note), dates: Record.datesLabel(note) };
            },

            open(day, slots = [], note = null) {
                this.openDay = day.date;
                this.startSlots = slots;
                this.editing = note;
            },

            explain(day) {
                this.open(day, day.unexplained.length === SLOTS.length ? [] : day.unexplained);
            },

            closeDay() {
                this.openDay = null;
                this.editing = null;
            },

            onKeydown(event) {
                if (event.key !== 'Escape') return;
                if (this.openDay) this.closeDay();
                this.pendingFile = null;
                this.printing = false;
            },

            toast(text, type = 'success') {
                const id = Date.now() + Math.random();
                this.toasts.push({ id, text, type });
                setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, type === 'error' ? 9000 : 5000);
            },
        },

        template: `
<div>
<div class="page d-print-none">
    <header class="d-flex flex-wrap align-items-start gap-3 mb-4">
        <div class="me-auto">
            <h1 class="h4 mb-1"><i class="ri-time-line text-primary me-1"></i>Daily Time Record</h1>
            <p class="text-body-secondary mb-0">
                <template v-if="person">{{ person }} · </template>Import your DTR, see every blank, note why.
            </p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <input ref="pdf" type="file" accept="application/pdf,.pdf" hidden @change="importPdf">
            <input ref="file" type="file" accept="application/json,.json,.txt" hidden @change="openFile">
            <button type="button" class="btn btn-primary" :disabled="busy" @click="pickPdf">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ri-file-pdf-2-line me-1"></i>Import DTR
            </button>
            <button type="button" class="btn btn-outline-secondary" @click="pickFile"><i class="ri-folder-open-line me-1"></i>Open file</button>
            <button type="button" class="btn" :class="unsaved ? 'btn-warning' : 'btn-outline-secondary'" :disabled="!hasRecords" @click="saveFile">
                <i class="ri-save-3-line me-1"></i>Save file
            </button>
        </div>
    </header>

    <div v-if="hasRecords" class="small text-body-secondary mb-3 save-state">
        <i class="ri-hard-drive-2-line me-1"></i>Kept in this browser.
        <span v-if="unsaved" class="text-warning-emphasis"> Changes since your last saved file{{ lastSaved ? ' (' + lastSaved + ')' : '' }} — save one to back them up.</span>
        <span v-else-if="lastSaved"> Saved to a file {{ lastSaved }}.</span>
    </div>

    <section v-if="!hasRecords" class="card mb-3">
        <div class="card-body text-center py-5">
            <i class="ri-time-line fs-1 text-body-tertiary d-block mb-2"></i>
            <h2 class="h6">Start with your DTR</h2>
            <p class="small text-body-secondary mb-3 mx-auto intro">
                Import the PDF from the DTR system — the blanks stand out right away. Or open a records file you saved before.
                You can also note things below before your DTR comes out.
            </p>
            <div class="d-flex justify-content-center flex-wrap gap-2">
                <button type="button" class="btn btn-primary" :disabled="busy" @click="pickPdf"><i class="ri-file-pdf-2-line me-1"></i>Import DTR</button>
                <button type="button" class="btn btn-outline-secondary" @click="pickFile"><i class="ri-folder-open-line me-1"></i>Open file</button>
            </div>
        </div>
    </section>

    <section class="card mb-3 quick-note">
        <div class="card-body">
            <h2 class="h6 mb-1"><i class="ri-edit-2-line me-1"></i>Something off today?</h2>
            <p class="small text-body-secondary mb-3">
                No internet, biometric down, out on official business? Note it now, while you remember — it's matched to your DTR when you import it.
            </p>
            <NoteForm :date="today" :today="today" pick-date id-prefix="quick" @save="form => saveNote(form)" />
        </div>
    </section>

    <div v-if="elsewhere.length" class="alert alert-warning d-flex flex-wrap align-items-center gap-2 small py-2">
        <i class="ri-error-warning-line"></i><span>Still to explain:</span>
        <button v-for="m in elsewhere" :key="m.month" type="button" class="btn btn-link btn-sm p-0 alert-link" @click="monthKey = m.month">
            {{ m.label }} ({{ m.days }} day{{ m.days === 1 ? '' : 's' }})
        </button>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Previous month" @click="go(-1)"><i class="ri-arrow-left-s-line"></i></button>
        <h2 class="h5 mb-0 month-title">{{ monthLabel }}</h2>
        <button type="button" class="btn btn-sm btn-outline-secondary" aria-label="Next month" @click="go(1)"><i class="ri-arrow-right-s-line"></i></button>
        <button v-if="!isThisMonth" type="button" class="btn btn-sm btn-link" @click="monthKey = today.slice(0, 7)">This month</button>
        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="printing = true"><i class="ri-printer-line me-1"></i>Print explanations</button>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <button type="button" class="stat card h-100 w-100 text-start" :class="{ 'stat-active': onlyToExplain, 'stat-warn': summary.unexplained }"
                    :disabled="!summary.unexplained" @click="onlyToExplain = !onlyToExplain">
                <div class="card-body">
                    <div class="stat-label">To explain</div>
                    <div class="stat-value">{{ summary.unexplained }}</div>
                    <div class="small text-body-secondary">{{ summary.unexplained ? (onlyToExplain ? 'showing only these — tap for all' : 'days with blanks — tap to list') : 'nothing left' }}</div>
                </div>
            </button>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat card h-100"><div class="card-body">
                <div class="stat-label">Explained</div>
                <div class="stat-value">{{ summary.explained }}</div>
                <div class="small text-body-secondary">days with blanks, each with a reason</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat card h-100"><div class="card-body">
                <div class="stat-label">Complete</div>
                <div class="stat-value">{{ summary.complete }}</div>
                <div class="small text-body-secondary">of {{ summary.workdays }} working day{{ summary.workdays === 1 ? '' : 's' }} so far</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat card h-100"><div class="card-body">
                <div class="stat-label">DTR imported</div>
                <div class="stat-value stat-text">{{ importedRange ? 'Days ' + importedRange : 'Not yet' }}</div>
                <div class="small text-body-secondary">{{ importedRange ? 'of ' + monthLabel : 'import it when it comes out — notes wait for it' }}</div>
            </div></div>
        </div>
    </div>

    <section class="card mb-4">
        <div class="table-responsive">
            <table class="table align-middle mb-0 dtr-table">
                <thead>
                    <tr>
                        <th scope="col" class="col-day">Day</th>
                        <th v-for="s in slots" :key="s.value" scope="col" class="col-time" :title="s.form_label">{{ s.label }}</th>
                        <th scope="col" class="col-undertime">Undertime</th>
                        <th scope="col">Why</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in days" :key="d.date" :class="['row-' + d.status, { 'row-today': d.is_today }]">
                        <td class="col-day">
                            <div class="d-flex align-items-center gap-2">
                                <span class="day-number">{{ d.day }}</span>
                                <span class="small text-body-secondary">{{ d.weekday }}</span>
                                <span v-if="d.is_today" class="badge text-bg-primary">Today</span>
                            </div>
                        </td>
                        <template v-for="c in cells(d)" :key="c.key">
                            <td v-if="c.type === 'remark'" :colspan="c.span" class="remark" :class="{ 'remark-off': c.span === slots.length }">{{ c.text }}</td>
                            <td v-else-if="c.type === 'time'" class="col-time num">{{ c.text }}</td>
                            <td v-else-if="c.type === 'blank'" class="col-time">
                                <button v-if="c.explained" type="button" class="blank blank-explained" :title="c.why && c.why.text" @click="open(d, [c.key])">
                                    <i :class="c.why ? c.why.icon : 'ri-check-line'"></i>
                                </button>
                                <button v-else type="button" class="blank blank-open" :title="slotLabel(c.key) + ' is blank — why?'" @click="open(d, [c.key])">
                                    <i class="ri-question-mark"></i>
                                </button>
                            </td>
                            <td v-else class="col-time text-body-tertiary">·</td>
                        </template>
                        <td class="col-undertime num small">{{ d.undertime }}</td>
                        <td class="why">
                            <div class="d-flex flex-wrap align-items-center gap-1">
                                <button v-for="id in d.notes" :key="id" type="button" class="badge note-chip"
                                        :class="'text-bg-' + reasons[notesById[id].reason].variant" @click="open(d, [], notesById[id])">
                                    <i :class="reasons[notesById[id].reason].icon" class="me-1"></i>{{ reasons[notesById[id].reason].short }}
                                    <span v-if="notesById[id].slots.length" class="fw-normal"> · {{ noteLabels(notesById[id]).slots }}</span>
                                    <span v-if="notesById[id].details" class="fw-normal"> — {{ notesById[id].details }}</span>
                                </button>
                                <button v-if="d.status === 'unexplained'" type="button" class="btn btn-sm btn-warning py-0" @click="explain(d)">Why blank?</button>
                                <button v-else-if="d.status !== 'off'" type="button" class="btn btn-sm btn-link py-0 add-note" :aria-label="'Add a note for ' + d.date" @click="open(d)">
                                    <i class="ri-add-line"></i>
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
        </div>
    </section>

    <p class="small text-body-secondary text-center">
        Your records never leave this device. <strong>Save file</strong> backs them up as a text file you can open in Notepad;
        <strong>Open file</strong> brings them back, here or on another phone or computer.
    </p>
</div>

<!-- One day -->
<template v-if="day">
    <div class="modal d-block d-print-none" tabindex="-1" role="dialog" aria-modal="true" :aria-label="dialogTitle" @click.self="closeDay">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5">{{ dialogTitle }}</h2>
                    <button type="button" class="btn-close" aria-label="Close" @click="closeDay"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <span v-for="s in slots" :key="s.value" class="time-pill" :class="{ 'time-pill-blank': day.blanks.includes(s.value) }">
                            <span class="text-body-secondary">{{ s.label }}</span>
                            <strong class="num">{{ day.times[s.value] || (day.remark_slots.includes(s.value) ? day.remark : (day.imported ? 'blank' : '—')) }}</strong>
                        </span>
                    </div>
                    <p v-if="!day.imported" class="small text-body-secondary">This day isn't on an imported DTR yet. Note it anyway — it's matched when you import.</p>

                    <div v-if="dayNotes.length" class="list-group mb-3">
                        <div v-for="n in dayNotes" :key="n.id" class="list-group-item">
                            <NoteForm v-if="editing && editing.id === n.id" :key="'edit-' + n.id" :note="n" :today="today" :id-prefix="'edit-' + n.id"
                                      @save="form => saveNote(form, n.id)" @remove="removeNote(n.id)" @cancel="editing = null" />
                            <div v-else class="d-flex align-items-start gap-2">
                                <i :class="[reasons[n.reason].icon, 'text-' + (reasons[n.reason].variant === 'light' ? 'body-secondary' : reasons[n.reason].variant)]" class="fs-5"></i>
                                <div class="small me-auto">
                                    <div class="fw-semibold">{{ reasons[n.reason].label }}</div>
                                    <div class="text-body-secondary">
                                        {{ noteLabels(n).slots }}<template v-if="n.starts_on !== n.ends_on"> · {{ noteLabels(n).dates }}</template>
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
                        <NoteForm :key="'new-' + day.date + '-' + startSlots.join()" :date="day.date" :slots="startSlots" :today="today" id-prefix="day"
                                  @save="form => saveNote(form)" />
                    </template>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop show d-print-none"></div>
</template>

<!-- Opening a file while records are already here -->
<template v-if="pendingFile">
    <div class="modal d-block d-print-none" tabindex="-1" role="dialog" aria-modal="true" aria-label="Open file" @click.self="pendingFile = null">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5">Open {{ pendingFile.name }}</h2>
                    <button type="button" class="btn-close" aria-label="Close" @click="pendingFile = null"></button>
                </div>
                <div class="modal-body small">
                    <p>This browser already has records. What should happen to them?</p>
                    <p class="mb-0"><strong>Add the file to them</strong> keeps both — each note once, and the latest import of each month.
                        <strong>Replace them</strong> uses only the file.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link" @click="pendingFile = null">Cancel</button>
                    <button type="button" class="btn btn-outline-danger" @click="useFile(pendingFile.records, 'replace')">Replace them</button>
                    <button type="button" class="btn btn-primary" @click="useFile(pendingFile.records, 'merge')">Add the file to them</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop show d-print-none"></div>
</template>

<!-- Before printing: who signs -->
<template v-if="printing">
    <div class="modal d-block d-print-none" tabindex="-1" role="dialog" aria-modal="true" aria-label="Print explanations" @click.self="printing = false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5">Print explanations — {{ monthLabel }}</h2>
                    <button type="button" class="btn-close" aria-label="Close" @click="printing = false"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-body-secondary">One page listing the month's blanks and why, signed by you and noted by the In-Charge.
                        To get a PDF, choose <strong>Save as PDF</strong> as the printer.</p>
                    <p v-if="summary.unexplained" class="small text-danger"><i class="ri-error-warning-line"></i>
                        {{ summary.unexplained }} day{{ summary.unexplained === 1 ? ' still has' : 's still have' }} blanks without a reason.</p>
                    <label for="in-charge-name" class="form-label small mb-1">Noted by</label>
                    <input id="in-charge-name" v-model="records.settings.in_charge_name" type="text" class="form-control form-control-sm mb-2">
                    <label for="in-charge-title" class="form-label small mb-1">Title</label>
                    <input id="in-charge-title" v-model="records.settings.in_charge_title" type="text" class="form-control form-control-sm">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link" @click="printing = false">Cancel</button>
                    <button type="button" class="btn btn-primary" @click="print"><i class="ri-printer-line me-1"></i>Print</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop show d-print-none"></div>
</template>

<!-- The printed sheet -->
<div class="print-sheet d-none d-print-block" data-bs-theme="light">
    <p class="small fst-italic mb-1">Attachment to the Daily Time Record (CSC Form No. 48)</p>
    <h1 class="sheet-title">EXPLANATION OF BLANK DTR ENTRIES</h1>
    <table class="sheet-meta">
        <tbody>
            <tr><th>Name</th><td>{{ person || '—' }}</td></tr>
            <tr><th>For the month of</th><td>{{ monthLabel }}</td></tr>
        </tbody>
    </table>
    <table v-if="printEntries.length" class="sheet-entries">
        <thead><tr><th class="col-date">Date</th><th class="col-entry">Entry</th><th>Explanation</th><th class="col-actual">Actual time</th></tr></thead>
        <tbody>
            <template v-for="e in printEntries" :key="e.day.date">
                <tr v-for="(line, i) in e.lines" :key="i" :class="{ missing: line.missing }">
                    <td v-if="i === 0" :rowspan="e.lines.length" class="col-date">{{ e.label }}</td>
                    <td class="col-entry">{{ line.entry }}</td>
                    <td>{{ line.text }}</td>
                    <td class="col-actual">{{ line.actual }}</td>
                </tr>
            </template>
        </tbody>
    </table>
    <p v-else class="fst-italic">No blank entries this month.</p>
    <p class="certify">I certify that the above explains the blank entries in my Daily Time Record for the month.</p>
    <div class="signatures">
        <div><div class="small mb-4 text-start">Certified correct:</div><div class="line">{{ person }}</div><div class="role">Employee</div></div>
        <div><div class="small mb-4 text-start">Noted:</div><div class="line">{{ records.settings.in_charge_name }}</div><div class="role">{{ records.settings.in_charge_title }}</div></div>
    </div>
</div>

<div class="toasts d-print-none" aria-live="polite">
    <div v-for="t in toasts" :key="t.id" class="toast-item" :class="'toast-' + t.type" role="status">{{ t.text }}</div>
</div>
</div>`,
    };

    function readMeta() {
        try {
            return JSON.parse(root.localStorage.getItem(META_KEY)) || {};
        } catch {
            return {};
        }
    }

    function writeMeta(meta) {
        try {
            root.localStorage.setItem(META_KEY, JSON.stringify(meta));
        } catch {
            // A browser that keeps nothing: commit() already says so.
        }
    }

    root.DtrApp = { App, NoteForm };
})(globalThis);
