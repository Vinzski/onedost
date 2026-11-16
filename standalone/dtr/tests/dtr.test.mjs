// The DTR page, tested in Node: `npm run test:dtr`.
// Mirrors tests/Feature/Dtr/DtrTest.php, which tests the same rules in OneDOST.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deflateSync } from 'node:zlib';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const here = (path) => fileURLToPath(new URL(path, import.meta.url));

// The page's scripts expect a browser's storage: a Map stands in.
const store = new Map();
globalThis.localStorage = {
    getItem: key => (store.has(key) ? store.get(key) : null),
    setItem: (key, value) => store.set(key, String(value)),
    removeItem: key => store.delete(key),
};

await import('../js/reader.js');
await import('../js/record.js');
const { read, DtrReadError } = globalThis.DtrReader;
const R = globalThis.DtrRecord;

/**
 * A DTR as the DTR system exports it (dompdf), cut down to what the reader
 * looks at: two copies side by side for days 1–15 and 16–30, each shading the
 * other half's rows dark grey. Days: [four times] (null for blank; 'HALF DAY'
 * as the third covers the afternoon), or a remark across the day; left out = blank.
 */
function dtrPdf(days, name = 'DELA CRUZ, JUAN A.', month = 'September-2026') {
    const f = n => n.toFixed(3);
    const lines = [];
    for (const [dx, from, to] of [[0, 1, 15], [297.151, 16, 30]]) {
        const at = x => f(x + dx);
        lines.push(`BT ${at(102.813)} 786.607 Td /F4 9.0 Tf  [(DAILY TIME RECORD)] TJ ET`);
        lines.push(`BT ${at(93.596)} 772.601 Td /F2 8.2 Tf  [(${name})] TJ ET`);
        lines.push(`BT ${at(30)} 758.760 Td /F2 7.5 Tf  [(For the month of)] TJ ET`);
        lines.push(`BT ${at(156.564)} 758.760 Td /F2 7.5 Tf  [(${month})] TJ ET`);
        lines.push(`BT ${at(34.725)} 716.518 Td /F2 6.8 Tf  [(Days)] TJ ET`);
        for (const [x, label] of [[62.927, 'Arrival'], [94.876, 'Departure'], [137.712, 'Arrival'], [169.66, 'Departure'], [210.72, 'Hours'], [239.772, 'Minutes']]) {
            lines.push(`BT ${at(x)} 703.551 Td /F2 6.8 Tf  [(${label})] TJ ET`);
        }
        for (let day = 1; day <= 30; day++) {
            const y = 690.584 - (day - 1) * 12.967;
            lines.push(`BT ${at(day < 10 ? 40.537 : 38.646)} ${f(y)} Td /F2 6.8 Tf  [(${day})] TJ ET`);
            const entry = days[day] ?? [];
            if (day < from || day > to) {
                lines.push('0.502 0.502 0.502 rg', `${at(54.826)} ${f(y - 3.424)} 35.324 11.992 re f`);
            } else if (typeof entry === 'string') {
                lines.push('0.839 0.839 0.839 rg', `${at(54.826)} ${f(y - 3.424)} 212.327 11.992 re f`, '0.000 0.000 0.000 rg');
                lines.push(`BT ${at(86.656)} ${f(y)} Td 6.000 Tc /F2 6.0 Tf  [(${entry})] TJ 0.000 Tc ET`);
            } else {
                for (const [i, x] of [64.044, 101.437, 138.829, 176.221].entries()) {
                    if (entry[i] === 'HALF DAY') {
                        lines.push('0.800 0.000 0.000 rg', `BT ${at(148.351)} ${f(y + 0.275)} Td 0.750 Tc /F4 6.0 Tf  [(HALF DAY)] TJ 0.000 Tc ET`);
                        break;
                    }
                    if (entry[i]) lines.push(`BT ${at(x)} ${f(y)} Td /F2 6.8 Tf  [(${entry[i]})] TJ ET`);
                }
            }
            lines.push('0.000 0.000 0.000 rg');
        }
    }
    const stream = deflateSync(Buffer.from(lines.join('\n'), 'latin1'));
    return Buffer.concat([
        Buffer.from(`%PDF-1.7\n1 0 obj\n<< /Length ${stream.length} /Filter /FlateDecode >>\nstream\n`, 'latin1'),
        stream,
        Buffer.from('\nendstream\nendobj\ntrailer\n<< >>\n%%EOF\n', 'latin1'),
    ]);
}

/** September 2026 on a Monday–Thursday week: Fridays to Sundays off, every other day in full, except as given. */
function september(overrides = {}) {
    const days = {};
    for (let day = 1; day <= 30; day++) {
        const weekday = new Date(Date.UTC(2026, 8, day)).getUTCDay();
        days[day] = weekday === 5 || weekday === 6 || weekday === 0 ? 'NON-WORKING DAY' : ['07:10', '12:00', '12:01', '06:05'];
    }
    return { ...days, ...overrides };
}

const sampleBlanks = () => september({ 2: [], 3: [null, null, null, '06:17'], 7: [null, '12:06', '12:06', '06:07'], 14: [], 15: [], 16: [], 28: ['06:26', '12:11', 'HALF DAY'] });

const TODAY = '2026-11-16';

async function imported(days = sampleBlanks()) {
    return R.importDtr(R.blankData(), await read(dtrPdf(days)), 'September-2026.pdf');
}

const day = (data, n, today = TODAY) => R.month(data, '2026-09', today).days[n - 1];

test('reads both halves of the DTR, its blanks and remarks, and the rows each copy leaves out', async () => {
    const dtr = await read(dtrPdf(sampleBlanks()));

    assert.equal(dtr.name, 'DELA CRUZ, JUAN A.');
    assert.equal(dtr.month, '2026-09');
    assert.equal(Object.keys(dtr.days).length, 30);
    assert.deepEqual([dtr.days[1].am_arrival, dtr.days[1].pm_departure], ['07:10', '06:05']);
    // Day 16 is blank on the second copy, not shaded out of the first.
    assert.deepEqual([dtr.days[16].am_arrival, dtr.days[16].remark], [null, null]);
    assert.equal(dtr.days[3].pm_departure, '06:17');
    assert.deepEqual(dtr.days[4].remark_slots, ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure']);
    assert.deepEqual([dtr.days[28].remark, dtr.days[28].remark_slots], ['HALF DAY', ['pm_arrival', 'pm_departure']]);
});

const sample = here('../../../storage/app/imports/files/dtr/September-2026 (3).pdf');
test('reads the example DTR from the DTR system', { skip: !existsSync(sample) && 'The example DTR isn\'t in storage/app/imports.' }, async () => {
    const dtr = await read(readFileSync(sample));

    assert.equal(dtr.month, '2026-09');
    assert.equal(Object.keys(dtr.days).length, 30);
    assert.equal(dtr.days[3].pm_departure, '06:17');
    assert.equal(dtr.days[7].am_arrival, null);
    assert.deepEqual(dtr.days[28].remark_slots, ['pm_arrival', 'pm_departure']);
});

test('says why a file isn\'t a DTR', async () => {
    await assert.rejects(read(Buffer.from('%PDF-1.4\n%%EOF\n')), DtrReadError);
});

test('shows what needs explaining once imported', async () => {
    const data = await imported();
    const month = R.month(data, '2026-09', TODAY);

    assert.equal(data.person, 'DELA CRUZ, JUAN A.');
    assert.equal(day(data, 1).status, 'complete');
    assert.deepEqual([day(data, 2).status, day(data, 2).blanks.length], ['unexplained', 4]);
    assert.equal(day(data, 4).status, 'off');
    assert.deepEqual(day(data, 7).unexplained, ['am_arrival']);
    assert.equal(day(data, 28).status, 'complete');
    assert.deepEqual([month.summary.unexplained, month.summary.imported_from, month.summary.imported_to], [6, 1, 30]);
    // Days that haven't happened yet don't count.
    assert.equal(day(data, 24, '2026-09-10').status, 'upcoming');
});

test('explains a blank with a note made on the day, before the DTR is imported', async () => {
    let data = R.saveNote(R.blankData(), { starts_on: '2026-09-07', slots: ['am_arrival'], reason: 'no_internet', details: 'Down until 10 AM', actual_time: '07:05' });
    data = R.importDtr(data, await read(dtrPdf(sampleBlanks())), 'September-2026.pdf');

    assert.equal(day(data, 7).status, 'explained');
    assert.deepEqual([data.notes[0].ends_on, data.notes[0].actual_time], ['2026-09-07', '07:05']);
    assert.equal(R.slotsLabel(data.notes[0]), 'Time in');
    assert.equal(R.month(data, '2026-09', TODAY).summary.unexplained, 5);
});

test('explains a run of days, or only the times it names', async () => {
    let data = await imported();
    data = R.saveNote(data, { starts_on: '2026-09-14', ends_on: '2026-09-16', slots: [], reason: 'official_business', actual_time: '07:00' });
    data = R.saveNote(data, { starts_on: '2026-09-02', slots: ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'], reason: 'holiday' });
    data = R.saveNote(data, { starts_on: '2026-09-03', slots: ['am_arrival', 'am_departure'], reason: 'forgot' });

    const business = data.notes.find(n => n.reason === 'official_business');
    assert.equal(business.actual_time, null);
    assert.equal(R.datesLabel(business), 'Sep 14 – 16');
    assert.deepEqual(data.notes.find(n => n.reason === 'holiday').slots, []);
    for (const n of [2, 14, 15, 16]) assert.equal(day(data, n).status, 'explained');
    assert.deepEqual(day(data, 3).unexplained, ['pm_arrival']);
    assert.deepEqual(R.month(data, '2026-09', TODAY).summary.unexplained, 2);
});

test('checks a note', () => {
    const errors = form => R.noteErrors({ starts_on: '2026-09-07', slots: [], reason: 'forgot', ...form });

    assert.deepEqual(errors({ reason: '' }), { reason: 'Pick why.' });
    assert.deepEqual(errors({ reason: 'other', details: ' ' }), { details: 'Say what happened.' });
    assert.deepEqual(errors({ ends_on: '2026-09-01' }), { ends_on: 'The last day can\'t be before the first.' });
    assert.deepEqual(errors({ ends_on: '2026-12-31' }), { ends_on: 'One note covers two months at most.' });
    assert.deepEqual(errors({ starts_on: '2026-02-30' }), { starts_on: 'Pick the day.' });
    assert.throws(() => R.saveNote(R.blankData(), { starts_on: '2026-09-07', reason: '' }), R.RecordError);
});

test('changes and deletes a note', () => {
    let data = R.saveNote(R.blankData(), { starts_on: '2026-09-07', slots: [], reason: 'forgot' });
    const id = data.notes[0].id;

    data = R.saveNote(data, { starts_on: '2026-09-07', slots: ['pm_departure'], reason: 'system_down', actual_time: '18:02' }, id);
    assert.equal(data.notes.length, 1);
    assert.deepEqual([data.notes[0].reason, data.notes[0].slots, data.notes[0].actual_time], ['system_down', ['pm_departure'], '18:02']);

    assert.equal(R.deleteNote(data, id).notes.length, 0);
});

test('keeps the other half of the month when one half is imported later', async () => {
    const first = september({ 2: [] });
    const second = september({ 24: [] });
    let data = R.importDtr(R.blankData(), await read(dtrPdf(first)), 'a.pdf');
    data = R.importDtr(data, await read(dtrPdf(second)), 'b.pdf');

    assert.equal(Object.keys(data.months['2026-09'].days).length, 30);
    assert.equal(day(data, 2).status, 'complete');
    assert.equal(day(data, 24).status, 'unexplained');
});

test('saves to a file, opens it again, and adds another device\'s file', async () => {
    const laptop = R.saveNote(await imported(), { starts_on: '2026-09-07', slots: ['am_arrival'], reason: 'no_internet' });

    // Round trip: the same records, readable as plain text.
    const text = R.toFile(laptop);
    assert.match(text, /\n {2}"app": "OneDOST DTR"/);
    assert.deepEqual(R.fromFile(text).notes, laptop.notes);
    assert.deepEqual(R.fromFile(text).months, laptop.months);

    // A phone with its own note: adding the laptop's file keeps both, each once.
    const phone = R.saveNote(R.blankData(), { starts_on: '2026-09-02', slots: [], reason: 'holiday' });
    const both = R.merge(phone, R.fromFile(text));
    assert.equal(both.notes.length, 2);
    assert.equal(R.merge(both, R.fromFile(text)).notes.length, 2);
    assert.equal(day(both, 2).status, 'explained');
    assert.equal(both.person, 'DELA CRUZ, JUAN A.');

    assert.throws(() => R.fromFile('not json'), R.RecordError);
    assert.throws(() => R.fromFile('{"app": "something else"}'), R.RecordError);
});

test('sets right what was hand-edited out of shape in Notepad', () => {
    const data = R.fromFile(JSON.stringify({
        app: 'OneDOST DTR',
        notes: [
            { id: 'a', starts_on: '2026-09-07', ends_on: '2026-09-07', slots: ['am_arrival', 'lunch'], reason: 'forgot' },
            { id: 'b', starts_on: 'yesterday', reason: 'forgot' },
            { id: 'c', starts_on: '2026-09-08', reason: 'nonsense' },
        ],
    }));

    // An unknown time is dropped; a note without a real date can't be placed; a mistyped reason becomes "Other".
    assert.deepEqual(data.notes.map(n => [n.id, n.slots, n.reason, n.details]), [
        ['a', ['am_arrival'], 'forgot', null],
        ['c', [], 'other', 'nonsense'],
    ]);
});

test('notices a DTR for someone else', async () => {
    const data = await imported();
    assert.equal(R.isSomeoneElse(data, { name: 'Dela Cruz, Juan A.' }), false);
    assert.equal(R.isSomeoneElse(data, { name: 'SANTOS, MARIA B.' }), true);
});

test('prints each blank with its reason, and flags what has none', async () => {
    let data = await imported();
    data = R.saveNote(data, { starts_on: '2026-09-07', slots: ['am_arrival'], reason: 'no_internet', details: 'Down until 10 AM', actual_time: '07:05' });
    const entries = R.printEntries(data, '2026-09', TODAY);

    assert.deepEqual(entries.map(e => e.day.day), [2, 3, 7, 14, 15, 16]);
    const seventh = entries.find(e => e.day.day === 7);
    assert.deepEqual(seventh.lines, [{ entry: 'A.M. arrival', text: 'No internet connection — Down until 10 AM', actual: '7:05 AM' }]);
    assert.deepEqual(entries[0].lines, [{ entry: 'Whole day', text: 'No explanation yet', actual: '', missing: true }]);
    assert.deepEqual(entries.find(e => e.day.day === 3).lines, [{ entry: 'A.M. arrival, A.M. departure, P.M. arrival', text: 'No explanation yet', actual: '', missing: true }]);
    assert.equal(R.displayName('MULOC, VINCE BRADLEY C.'), 'VINCE BRADLEY C. MULOC');
});

test('the page renders a month with blanks and notes', async () => {
    const { createSSRApp } = await import('vue');
    const { renderToString } = await import('vue/server-renderer');
    await import('../js/app.js');

    const data = R.saveNote(await imported(), { starts_on: '2026-09-07', slots: ['am_arrival'], reason: 'no_internet' });
    R.persist(data);
    const todayIso = R.todayIso;
    R.todayIso = () => '2026-09-30';
    try {
        const html = await renderToString(createSSRApp(globalThis.DtrApp.App));

        assert.match(html, /Something off today\?/);
        assert.match(html, /September 2026/);
        assert.match(html, /JUAN A\. DELA CRUZ/);
        assert.match(html, /Why blank\?/);
        assert.match(html, /No internet/);
        assert.match(html, /NON-WORKING DAY/);
        assert.match(html, /EXPLANATION OF BLANK DTR ENTRIES/);
    } finally {
        R.todayIso = todayIso;
        store.clear();
    }
});
