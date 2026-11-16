/**
 * Your DTR records: the days imported from DTR PDFs, and the notes that say
 * why a time is blank. Everything lives in one plain object, kept in the
 * browser and saved to (or opened from) a JSON file you can read in Notepad.
 *
 * The day statuses follow app/Services/Dtr/DtrService.php (without the
 * trips and leave, which live in OneDOST): change both.
 */
(function (root) {
    'use strict';

    const APP = 'OneDOST DTR';
    const VERSION = 1;
    const STORAGE_KEY = 'onedost-dtr';

    const SLOTS = [
        { value: 'am_arrival', label: 'Time in', form_label: 'A.M. arrival' },
        { value: 'am_departure', label: 'Lunch out', form_label: 'A.M. departure' },
        { value: 'pm_arrival', label: 'Lunch in', form_label: 'P.M. arrival' },
        { value: 'pm_departure', label: 'Time out', form_label: 'P.M. departure' },
    ];
    const SLOT_VALUES = SLOTS.map(s => s.value);

    /** Why a time is blank: the quick picks (app/Enums/DtrReason.php). */
    const REASONS = [
        { value: 'no_internet', label: 'No internet connection', short: 'No internet', icon: 'ri-wifi-off-line', variant: 'warning', hint: 'e.g. Office internet down until 10 AM' },
        { value: 'system_down', label: 'DTR system or biometric down', short: 'System down', icon: 'ri-fingerprint-line', variant: 'warning', hint: 'e.g. Biometric wouldn\'t read fingerprints' },
        { value: 'power_outage', label: 'Power outage', short: 'Power outage', icon: 'ri-flashlight-line', variant: 'warning', hint: 'e.g. Brownout in the morning' },
        { value: 'forgot', label: 'Forgot to log', short: 'Forgot to log', icon: 'ri-history-line', variant: 'danger', hint: 'e.g. Went straight to a meeting' },
        { value: 'official_travel', label: 'On official travel', short: 'Travel', icon: 'ri-suitcase-line', variant: 'info', hint: 'e.g. TO 2026-5667, Cebu' },
        { value: 'official_business', label: 'Official business outside the office', short: 'Official business', icon: 'ri-briefcase-line', variant: 'info', hint: 'e.g. Site visit at a client\'s plant' },
        { value: 'leave', label: 'On leave', short: 'Leave', icon: 'ri-calendar-check-line', variant: 'secondary', hint: 'e.g. Sick leave' },
        { value: 'work_from_home', label: 'Work from home', short: 'Work from home', icon: 'ri-home-wifi-line', variant: 'info', hint: 'e.g. Approved WFH arrangement' },
        { value: 'holiday', label: 'Holiday or work suspension', short: 'Holiday', icon: 'ri-flag-line', variant: 'secondary', hint: 'e.g. Proclamation No. 123, s. 2026' },
        { value: 'other', label: 'Other', short: 'Other', icon: 'ri-chat-1-line', variant: 'light', hint: 'Say what happened', needs_details: true },
    ];
    const REASON = Object.fromEntries(REASONS.map(r => [r.value, r]));

    /** A note covers two months at most. */
    const MAX_NOTE_DAYS = 62;

    class RecordError extends Error {}

    // ─── Dates, as 'YYYY-MM-DD' strings (compared as text) ──────────────────

    const pad = n => String(n).padStart(2, '0');

    function todayIso(now = new Date()) {
        return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    }

    function parseIso(iso) {
        const [y, m, d] = iso.split('-').map(Number);
        return new Date(Date.UTC(y, m - 1, d));
    }

    function toIso(date) {
        return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
    }

    function addDays(iso, days) {
        const date = parseIso(iso);
        date.setUTCDate(date.getUTCDate() + days);
        return toIso(date);
    }

    function isIsoDate(value) {
        return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && toIso(parseIso(value)) === value;
    }

    function addMonths(key, months) {
        const [y, m] = key.split('-').map(Number);
        const date = new Date(Date.UTC(y, m - 1 + months, 1));
        return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}`;
    }

    function monthLabel(key) {
        const [y, m] = key.split('-').map(Number);
        return new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    }

    function monthDates(key) {
        const dates = [];
        for (let iso = `${key}-01`; iso.startsWith(key); iso = addDays(iso, 1)) dates.push(iso);
        return dates;
    }

    function weekday(iso) {
        return parseIso(iso).toLocaleDateString('en-US', { weekday: 'short', timeZone: 'UTC' });
    }

    function longDate(iso) {
        return parseIso(iso).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
    }

    function shortDate(iso) {
        return parseIso(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric', weekday: 'short', timeZone: 'UTC' });
    }

    // ─── The record ─────────────────────────────────────────────────────────

    function blankData() {
        return {
            app: APP,
            version: VERSION,
            person: null,
            months: {},
            notes: [],
            settings: { in_charge_name: 'RICARDO J. APOLINARIO III', in_charge_title: 'In Charge' },
        };
    }

    function hasData(data) {
        return data.notes.length > 0 || Object.keys(data.months).length > 0;
    }

    /** Your saved records, or a fresh start if there are none (or the browser won't say). */
    function load(storage = root.localStorage) {
        try {
            const raw = storage && storage.getItem(STORAGE_KEY);
            return raw ? normalize(JSON.parse(raw)) : blankData();
        } catch {
            return blankData();
        }
    }

    /** @returns {boolean} Whether the browser kept it (a private window may not). */
    function persist(data, storage = root.localStorage) {
        try {
            storage.setItem(STORAGE_KEY, JSON.stringify(data));
            return true;
        } catch {
            return false;
        }
    }

    /** The file you save: pretty-printed, so Notepad shows it readably. */
    function toFile(data, now = new Date()) {
        return JSON.stringify({ ...data, saved_at: now.toISOString() }, null, 2);
    }

    /** A file's text as records; anything hand-edited out of shape is set right or left out. */
    function fromFile(text) {
        let raw;
        try {
            raw = JSON.parse(text);
        } catch {
            throw new RecordError('That file isn\'t a DTR records file (it isn\'t valid JSON).');
        }
        if (!raw || raw.app !== APP) {
            throw new RecordError('That file isn\'t a DTR records file saved from this page.');
        }
        return normalize(raw);
    }

    function normalize(raw) {
        const data = blankData();
        data.person = typeof raw.person === 'string' && raw.person.trim() ? raw.person.trim() : null;
        data.settings = { ...data.settings, ...(raw.settings || {}) };

        for (const [key, month] of Object.entries(raw.months || {})) {
            if (!/^\d{4}-\d{2}$/.test(key) || !month || typeof month.days !== 'object') continue;
            const days = {};
            for (const [day, entry] of Object.entries(month.days)) {
                if (!entry || Number(day) < 1 || Number(day) > 31) continue;
                days[Number(day)] = {
                    ...Object.fromEntries(SLOT_VALUES.map(s => [s, typeof entry[s] === 'string' && entry[s] ? entry[s] : null])),
                    undertime_hours: Number.isInteger(entry.undertime_hours) ? entry.undertime_hours : null,
                    undertime_minutes: Number.isInteger(entry.undertime_minutes) ? entry.undertime_minutes : null,
                    remark: typeof entry.remark === 'string' && entry.remark ? entry.remark : null,
                    remark_slots: Array.isArray(entry.remark_slots) ? entry.remark_slots.filter(s => SLOT_VALUES.includes(s)) : [],
                };
            }
            data.months[key] = { imported_at: month.imported_at || null, file: month.file || null, days };
        }

        for (const note of Array.isArray(raw.notes) ? raw.notes : []) {
            if (!note || typeof note !== 'object') continue;
            // A reason mistyped in Notepad becomes "Other", saying what was typed.
            const known = REASON[note.reason];
            const clean = cleanNote(known ? note : { ...note, reason: 'other', details: note.details || String(note.reason || 'No reason given') });
            if (clean) data.notes.push({ ...clean, id: String(note.id || newId()), created_at: note.created_at || null, updated_at: note.updated_at || null });
        }
        sortNotes(data);
        return data;
    }

    /**
     * Another device's file, added to yours: each note once (the newer edit
     * wins), and for each month the more recent import.
     */
    function merge(current, incoming) {
        const data = normalize(current);
        data.person ??= incoming.person;
        for (const [key, month] of Object.entries(incoming.months)) {
            const mine = data.months[key];
            if (!mine || (month.imported_at || '') > (mine.imported_at || '')) {
                data.months[key] = { ...month, days: { ...(mine?.days || {}), ...month.days } };
            }
        }
        const byId = new Map(data.notes.map(n => [n.id, n]));
        for (const note of incoming.notes) {
            const mine = byId.get(note.id);
            if (!mine || (note.updated_at || '') > (mine.updated_at || '')) byId.set(note.id, note);
        }
        data.notes = [...byId.values()];
        sortNotes(data);
        return data;
    }

    /**
     * A DTR read from its PDF, saved into the month it's for. Days it covers
     * replace what was there; days it doesn't (the other half of the month)
     * stay. The first DTR sets whose records these are.
     */
    function importDtr(data, dtr, fileName, now = new Date()) {
        const next = normalize(data);
        next.person ??= dtr.name;
        next.months[dtr.month] = {
            imported_at: now.toISOString(),
            file: fileName || null,
            days: { ...(next.months[dtr.month]?.days || {}), ...dtr.days },
        };
        return next;
    }

    /** Whether a DTR names someone other than whose records these are. */
    function isSomeoneElse(data, dtr) {
        return Boolean(data.person) && squash(data.person) !== squash(dtr.name);
    }

    // ─── Notes ──────────────────────────────────────────────────────────────

    /** What's wrong with a note before it's saved: { field: message }, empty if nothing. */
    function noteErrors(form) {
        const errors = {};
        if (!isIsoDate(form.starts_on)) errors.starts_on = 'Pick the day.';
        const ends = form.ends_on || form.starts_on;
        if (!errors.starts_on && form.ends_on && !isIsoDate(form.ends_on)) errors.ends_on = 'Pick the last day.';
        else if (!errors.starts_on && ends < form.starts_on) errors.ends_on = 'The last day can\'t be before the first.';
        else if (!errors.starts_on && ends > addDays(form.starts_on, MAX_NOTE_DAYS)) errors.ends_on = 'One note covers two months at most.';
        if (!REASON[form.reason]) errors.reason = 'Pick why.';
        else if (REASON[form.reason].needs_details && !String(form.details || '').trim()) errors.details = 'Say what happened.';
        if (form.actual_time && !/^\d{2}:\d{2}$/.test(form.actual_time)) errors.actual_time = 'Use a time like 07:05.';
        return errors;
    }

    /**
     * One day or a run of days; all four times are the whole day; the actual
     * time only means something for one time on one day.
     */
    function cleanNote(form) {
        if (!form || Object.keys(noteErrors(form)).length) return null;
        let slots = SLOT_VALUES.filter(s => (form.slots || []).includes(s));
        if (slots.length === SLOT_VALUES.length) slots = [];
        const starts = form.starts_on;
        const ends = form.ends_on || starts;
        const single = starts === ends && slots.length === 1;
        return {
            starts_on: starts,
            ends_on: ends,
            slots,
            reason: form.reason,
            details: String(form.details || '').trim() || null,
            actual_time: single && form.actual_time ? form.actual_time : null,
        };
    }

    /** Add a note, or change the one with this id. */
    function saveNote(data, form, id = null, now = new Date()) {
        const clean = cleanNote(form);
        if (!clean) throw new RecordError(Object.values(noteErrors(form))[0]);
        const next = normalize(data);
        const stamp = now.toISOString();
        const existing = id ? next.notes.find(n => n.id === id) : null;
        if (existing) Object.assign(existing, clean, { updated_at: stamp });
        else next.notes.push({ ...clean, id: newId(), created_at: stamp, updated_at: stamp });
        sortNotes(next);
        return next;
    }

    function deleteNote(data, id) {
        const next = normalize(data);
        next.notes = next.notes.filter(n => n.id !== id);
        return next;
    }

    function covers(note, iso, slot) {
        return iso >= note.starts_on && iso <= note.ends_on && (note.slots.length === 0 || note.slots.includes(slot));
    }

    /** "Time in, Time out", or "Whole day". */
    function slotsLabel(note, form = false) {
        if (!note.slots.length) return 'Whole day';
        return SLOTS.filter(s => note.slots.includes(s.value)).map(s => (form ? s.form_label : s.label)).join(', ');
    }

    /** "Sep 14", or "Sep 14 – 16" for a run of days. */
    function datesLabel(note) {
        const opts = { month: 'short', day: 'numeric', timeZone: 'UTC' };
        const from = parseIso(note.starts_on);
        const to = parseIso(note.ends_on);
        if (note.starts_on === note.ends_on) return from.toLocaleDateString('en-US', opts);
        if (note.starts_on.slice(0, 7) === note.ends_on.slice(0, 7)) return `${from.toLocaleDateString('en-US', opts)} – ${to.getUTCDate()}`;
        return `${from.toLocaleDateString('en-US', opts)} – ${to.toLocaleDateString('en-US', opts)}`;
    }

    // ─── A month, day by day ────────────────────────────────────────────────

    /** The times left empty that a remark doesn't account for. */
    function blankSlots(entry) {
        return SLOT_VALUES.filter(s => !entry[s] && !entry.remark_slots.includes(s));
    }

    function isOff(entry) {
        return entry.remark_slots.length === SLOT_VALUES.length;
    }

    function undertime(entry) {
        const parts = [entry.undertime_hours ? `${entry.undertime_hours}h` : null, entry.undertime_minutes ? `${entry.undertime_minutes}m` : null].filter(Boolean);
        return parts.length ? parts.join(' ') : null;
    }

    /**
     * A month of the DTR, a row a day, with what's blank and what explains
     * it. Only days up to today count: later ones haven't happened yet.
     */
    function month(data, key, today) {
        const imported = data.months[key]?.days || {};
        const monthNotes = data.notes.filter(n => n.starts_on <= `${key}-31` && n.ends_on >= `${key}-01`);

        const days = monthDates(key).map((iso) => {
            const entry = imported[Number(iso.slice(8))] || null;
            const notes = monthNotes.filter(n => iso >= n.starts_on && iso <= n.ends_on);
            const counts = entry !== null && iso <= today;
            const blanks = counts ? blankSlots(entry) : [];
            const unexplained = blanks.filter(slot => !notes.some(n => covers(n, iso, slot)));
            let status;
            if (!entry) status = iso > today ? 'upcoming' : 'not_imported';
            else if (isOff(entry)) status = 'off';
            else if (!counts) status = 'upcoming';
            else if (!blanks.length) status = 'complete';
            else if (!unexplained.length) status = 'explained';
            else status = 'unexplained';

            return {
                date: iso,
                day: Number(iso.slice(8)),
                weekday: weekday(iso),
                is_today: iso === today,
                imported: entry !== null,
                times: Object.fromEntries(SLOT_VALUES.map(s => [s, entry ? entry[s] : null])),
                remark: entry?.remark ?? null,
                remark_slots: entry?.remark_slots ?? [],
                undertime: entry ? undertime(entry) : null,
                blanks,
                unexplained,
                notes: notes.map(n => n.id),
                status,
            };
        });

        const importedDays = Object.keys(imported).map(Number).sort((a, b) => a - b);
        const count = status => days.filter(d => d.status === status).length;
        return {
            days,
            notes: monthNotes,
            summary: {
                imported_from: importedDays[0] ?? null,
                imported_to: importedDays[importedDays.length - 1] ?? null,
                imported_at: data.months[key]?.imported_at ?? null,
                workdays: days.filter(d => ['complete', 'explained', 'unexplained'].includes(d.status)).length,
                complete: count('complete'),
                explained: count('explained'),
                unexplained: count('unexplained'),
                first_unexplained: days.find(d => d.status === 'unexplained')?.date ?? null,
            },
        };
    }

    /** Other imported months with blanks still to explain, newest first. */
    function monthsToExplain(data, except, today) {
        return Object.keys(data.months)
            .filter(key => key !== except)
            .sort()
            .reverse()
            .map(key => ({ month: key, label: monthLabel(key), days: month(data, key, today).summary.unexplained }))
            .filter(m => m.days > 0);
    }

    /**
     * The month's blanks and their explanations, for the printed sheet: a
     * row a day that has blanks or a note, a line per explanation.
     */
    function printEntries(data, key, today) {
        const { days } = month(data, key, today);
        const byId = new Map(data.notes.map(n => [n.id, n]));
        const formLabel = Object.fromEntries(SLOTS.map(s => [s.value, s.form_label]));
        return days.flatMap((day) => {
            const notes = day.notes.map(id => byId.get(id)).filter(Boolean);
            if (day.status === 'off' || (!day.blanks.length && !notes.length)) return [];
            const lines = notes.map(n => ({
                entry: slotsLabel(n, true),
                text: REASON[n.reason].label + (n.details ? ` — ${n.details}` : ''),
                actual: clock(n.actual_time),
            }));
            if (day.unexplained.length) {
                const entry = day.unexplained.length === SLOTS.length ? 'Whole day' : day.unexplained.map(s => formLabel[s]).join(', ');
                lines.push({ entry, text: 'No explanation yet', actual: '', missing: true });
            }
            return [{ day, label: shortDate(day.date), lines }];
        });
    }

    // ─── Small helpers ──────────────────────────────────────────────────────

    /** "07:05" → "7:05 AM" */
    function clock(time) {
        if (!time) return '';
        const [h, m] = time.split(':').map(Number);
        return `${h % 12 || 12}:${pad(m)} ${h < 12 ? 'AM' : 'PM'}`;
    }

    /** "MULOC, VINCE BRADLEY C." → "VINCE BRADLEY C. MULOC", as a signature line reads. */
    function displayName(printed) {
        if (!printed) return '';
        const [last, rest] = printed.split(/,(.*)/s).map(s => (s || '').trim());
        return rest ? `${rest} ${last}` : last;
    }

    function squash(name) {
        return String(name).toUpperCase().replace(/[^A-Z]/g, '');
    }

    function newId() {
        return root.crypto?.randomUUID ? root.crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
    }

    function sortNotes(data) {
        data.notes.sort((a, b) => a.starts_on.localeCompare(b.starts_on) || (a.created_at || '').localeCompare(b.created_at || ''));
    }

    root.DtrRecord = {
        SLOTS, REASONS, REASON, RecordError,
        blankData, hasData, load, persist, toFile, fromFile, merge, importDtr, isSomeoneElse,
        noteErrors, saveNote, deleteNote, slotsLabel, datesLabel,
        month, monthsToExplain, printEntries,
        todayIso, addMonths, monthLabel, longDate, clock, displayName,
    };
})(globalThis);
