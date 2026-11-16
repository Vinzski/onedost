/**
 * Reads the Daily Time Record (CSC Form No. 48) as the DTR system exports it:
 * a dompdf page holding copies of the form side by side, each covering part
 * of the month (1–15, 16–30). A copy shades the rows outside its part dark
 * grey, so a white row with nothing in it is a real blank.
 *
 * Every value is a positioned text run, so columns are found from the header
 * labels (Arrival, Departure, Arrival, Departure, Hours, Minutes) and each
 * value goes to the column it sits under. Text that isn't a time —
 * "NON-WORKING DAY", "HALF DAY" — is a remark covering the columns it spans.
 *
 * The browser twin of app/Services/Dtr/DtrPdfReader.php: change both.
 */
(function (root) {
    'use strict';

    const SLOTS = ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'];
    const COLUMNS = ['day', ...SLOTS, 'undertime_hours', 'undertime_minutes'];

    /** The header labels under A.M, P.M and UNDERTIME, left to right. */
    const HEADERS = ['Arrival', 'Departure', 'Arrival', 'Departure', 'Hours', 'Minutes'];

    /** Fill colours this dark (out of 1) shade a row out of a copy's part of the month; non-working days are lighter. */
    const SHADE = 0.7;

    /** How far (points) a value's baseline may sit from its row's day number. */
    const ROW_TOLERANCE = 4;

    /** The most one stream may inflate to: a DTR page is about 60 KB. */
    const MAX_STREAM = 8 * 1024 * 1024;

    const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    const cp1252 = new TextDecoder('windows-1252');

    class DtrReadError extends Error {}

    /**
     * @param {ArrayBuffer|Uint8Array} file The PDF's bytes.
     * @returns {Promise<{name: string, month: string, days: Object<number, object>}>} month as YYYY-MM;
     *          days keyed by day of the month, only days some copy covers.
     */
    async function read(file) {
        const bytes = file instanceof Uint8Array ? file : new Uint8Array(file);
        const copies = [];
        for (const stream of await contentStreams(bytes)) {
            const { texts, shades } = scan(stream);
            copies.push(...findCopies(texts, shades));
        }
        if (!copies.length) {
            throw new DtrReadError('This PDF doesn\'t look like a Daily Time Record. Import the DTR as the DTR system exports it.');
        }
        if (new Set(copies.map(c => c.name)).size > 1 || new Set(copies.map(c => c.month)).size > 1) {
            throw new DtrReadError('This PDF holds more than one person or month. Import one DTR at a time.');
        }

        // A day comes from the copy that covers it; if two do, the one with something in it.
        const days = {};
        for (const copy of copies) {
            for (const [day, entry] of Object.entries(copy.days)) {
                if (!days[day] || isEmpty(days[day])) days[day] = entry;
            }
        }

        return { name: copies[0].name, month: copies[0].month, days };
    }

    /** Each stream that draws text, inflated, as text (one character a byte). */
    async function contentStreams(bytes) {
        const whole = cp1252.decode(bytes);
        const found = [];
        const pattern = /stream\r?\n([\s\S]*?)\r?\nendstream/g;
        let match;
        while ((match = pattern.exec(whole)) !== null) {
            const start = match.index + match[0].indexOf('\n') + 1;
            const raw = bytes.subarray(start, start + match[1].length);
            let text;
            try {
                text = cp1252.decode(await inflate(raw));
            } catch {
                text = match[1];
            }
            if (text.includes(' Td') && text.includes('BT')) found.push(text);
        }
        return found;
    }

    /** zlib (FlateDecode), stopping at MAX_STREAM so a PDF built to inflate into gigabytes can't eat the memory. */
    async function inflate(data) {
        const reader = new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate')).getReader();
        const chunks = [];
        let size = 0;
        for (;;) {
            const { done, value } = await reader.read();
            if (done) break;
            size += value.length;
            if (size > MAX_STREAM) {
                await reader.cancel();
                throw new Error('Stream too large');
            }
            chunks.push(value);
        }
        const out = new Uint8Array(size);
        let at = 0;
        for (const chunk of chunks) {
            out.set(chunk, at);
            at += chunk.length;
        }
        return out;
    }

    /** The text runs (position, size, character spacing, text) and the dark fills a content stream draws. */
    function scan(stream) {
        const texts = [];
        const shades = [];
        let fill = 0;
        for (const rawLine of stream.split(/\r?\n/)) {
            const line = rawLine.trim();
            let m;
            if ((m = line.match(/^([\d.]+) ([\d.]+) ([\d.]+) rg$/))) {
                fill = (Number(m[1]) + Number(m[2]) + Number(m[3])) / 3;
            } else if ((m = line.match(/^([\d.]+) (-?[\d.]+) ([\d.]+) ([\d.]+) re f\*?$/))) {
                if (fill > 0.05 && fill < SHADE) {
                    shades.push({ x: Number(m[1]), y: Number(m[2]), w: Number(m[3]), h: Number(m[4]) });
                }
            } else if ((m = line.match(/^BT (-?[\d.]+) (-?[\d.]+) Td(?: (-?[\d.]+) Tc)? \/\w+ ([\d.]+) Tf\s+\[(.*)\] TJ/))) {
                const text = decode(m[5]).trim();
                if (text !== '') {
                    texts.push({ x: Number(m[1]), y: Number(m[2]), size: Number(m[4]), spacing: Number(m[3] || 0), text });
                }
            }
        }
        return { texts, shades };
    }

    /** Each copy of the form on the page, from its "Days" header to the next one's. */
    function findCopies(texts, shades) {
        const starts = texts.filter(t => t.text === 'Days').sort((a, b) => a.x - b.x);
        return starts.map((days, i) => {
            const left = days.x - 10;
            const right = starts[i + 1] ? starts[i + 1].x - 10 : Infinity;
            const inside = item => item.x >= left && item.x < right;
            return copy(texts.filter(inside), shades.filter(inside), days);
        });
    }

    function copy(texts, shades, daysHeader) {
        // Columns: the day number, then the six labels under the header.
        const labels = texts
            .filter(t => HEADERS.includes(t.text) && t.y < daysHeader.y && t.y > daysHeader.y - 20)
            .sort((a, b) => a.x - b.x);
        if (labels.map(t => t.text).join() !== HEADERS.join()) {
            throw new DtrReadError('The DTR\'s table headings aren\'t where they should be, so its times can\'t be read.');
        }
        const centres = [centre(daysHeader), ...labels.map(centre)];

        const month = findMonth(texts);
        const inMonth = daysInMonth(month);
        const rows = texts.filter(t => t.y < daysHeader.y - 20
            && /^\d{1,2}$/.test(t.text)
            && column(t, centres) === 0
            && Number(t.text) >= 1 && Number(t.text) <= inMonth);

        const days = {};
        for (const row of rows) {
            if (shaded(row.y, shades)) continue;
            const entry = { am_arrival: null, am_departure: null, pm_arrival: null, pm_departure: null,
                undertime_hours: null, undertime_minutes: null, remark: null, remark_slots: [] };
            for (const item of texts) {
                if (item === row || Math.abs(item.y - row.y) > ROW_TOLERANCE || item.x < row.x) continue;
                const name = COLUMNS[column(item, centres)];
                if (/^\d{1,2}:\d{2}$/.test(item.text) && SLOTS.includes(name)) {
                    entry[name] = item.text.padStart(5, '0');
                } else if (/^\d+$/.test(item.text) && name.startsWith('undertime_')) {
                    entry[name] = Number(item.text);
                } else {
                    entry.remark = ((entry.remark ? entry.remark + ' ' : '') + item.text.replace(/\s+/g, ' ')).trim();
                    entry.remark_slots = [...new Set([...entry.remark_slots, ...spanned(item, centres)])];
                }
            }
            days[Number(row.text)] = entry;
        }

        return { name: findName(texts), month, days };
    }

    /** "September-2026", beside "For the month of" → "2026-09". */
    function findMonth(texts) {
        for (const item of texts) {
            const m = item.text.match(/^([A-Za-z]+)[- ](\d{4})$/);
            const index = m ? MONTHS.indexOf(m[1].toLowerCase()) : -1;
            if (index >= 0) return `${m[2]}-${String(index + 1).padStart(2, '0')}`;
        }
        throw new DtrReadError('The DTR\'s month ("For the month of") couldn\'t be read.');
    }

    /** The name printed under "DAILY TIME RECORD". */
    function findName(texts) {
        const title = texts.find(t => t.text.toUpperCase() === 'DAILY TIME RECORD');
        const name = title && texts
            .filter(t => t.y < title.y && t.y > title.y - 25)
            .sort((a, b) => b.y - a.y)[0];
        if (!name) throw new DtrReadError('The name on the DTR couldn\'t be read.');
        return name.text;
    }

    /** Which column an item sits under: the nearest column centre. */
    function column(item, centres) {
        const x = centre(item);
        const distances = centres.map(c => Math.abs(c - x));
        return distances.indexOf(Math.min(...distances));
    }

    /** The time columns a remark's text spans. */
    function spanned(item, centres) {
        const from = item.x;
        const to = item.x + width(item);
        return SLOTS.filter((slot, i) => {
            const col = i + 1;
            const cellFrom = (centres[col - 1] + centres[col]) / 2;
            const cellTo = (centres[col] + centres[col + 1]) / 2;
            return from < cellTo && to > cellFrom;
        });
    }

    /** Whether a dark fill covers this row: the row is outside the copy's part of the month. */
    function shaded(baseline, shades) {
        return shades.some(s => baseline >= s.y - 1 && baseline <= s.y + s.h + 1);
    }

    function centre(item) {
        return item.x + width(item) / 2;
    }

    /** About how wide a text run is: Helvetica averages a little over half an em a character. */
    function width(item) {
        const length = [...item.text].length;
        return length * item.size * 0.56 + length * item.spacing;
    }

    /** The text of a TJ array: its (strings) joined, escapes undone. */
    function decode(array) {
        const strings = array.match(/\(((?:\\.|[^\\)])*)\)/g) || [];
        return strings.map(s => s.slice(1, -1).replace(/\\([0-7]{1,3}|[\s\S])/g, (_, c) => {
            if (/^[0-7]+$/.test(c)) return cp1252.decode(Uint8Array.of(parseInt(c, 8) & 0xff));
            return { n: '\n', r: '\r', t: '\t' }[c] ?? c;
        })).join('');
    }

    function daysInMonth(month) {
        const [y, m] = month.split('-').map(Number);
        return new Date(Date.UTC(y, m, 0)).getUTCDate();
    }

    function isEmpty(entry) {
        return Object.values(entry).every(v => v === null || (Array.isArray(v) && v.length === 0));
    }

    root.DtrReader = { read, DtrReadError, SLOTS };
})(globalThis);
