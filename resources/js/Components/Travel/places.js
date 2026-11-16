/**
 * Philippine provinces, cities and municipalities (PSGC) a trip can go to,
 * and the EO 77 per diem each earns. The list (resources/data/places.json,
 * ~100 KB) loads on first use and is shared by every component after that.
 */

let loading = null;

/** Lower-case words without accents or punctuation: "Parañaque City" → "paranaque city". */
export function normalize(text) {
    return (text || '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ').trim();
}

/** @returns {Promise<{ list: object[], byCode: Map<string, object> }>} */
export function loadPlaces() {
    loading ??= import('@/../data/places.json')
        .then(({ default: data }) => index(data))
        .catch(error => {
            loading = null;
            throw error;
        });
    return loading;
}

function index(data) {
    const list = data.places.map(([code, name, province, type]) => {
        const region = code.slice(0, 2);
        const regionName = data.regions[region] ?? '';
        const venue = [name, province].filter(Boolean).join(', ');
        return {
            code, name, province, type, region, regionName,
            // What the venue field gets: "Pagadian City, Zamboanga del Sur".
            venue,
            label: type === 'province' ? `${name} (province)` : venue,
            key: normalize(name),
            words: normalize(`${name} ${province} ${regionName}`),
        };
    });

    // Names shared by several towns ("San Jose") only count with their province.
    const seen = new Map();
    list.filter(p => p.type !== 'province').forEach(p => seen.set(p.key, (seen.get(p.key) ?? 0) + 1));
    list.forEach(p => { p.unique = seen.get(p.key) === 1; });

    return { list, byCode: new Map(list.map(p => [p.code, p])) };
}

const TYPE_ORDER = { city: 0, province: 1, municipality: 2 };

/** Best matches for what was typed: whole name, then name start, then any word. */
export function searchPlaces(list, query, limit = 8) {
    const q = normalize(query);
    if (!q) return [];
    const tokens = q.split(' ');

    return list
        .filter(p => tokens.every(token => p.words.includes(token)))
        .map(p => ({
            p,
            rank: p.key === q ? 0 : p.key.startsWith(q) ? 1 : ` ${p.key}`.includes(` ${tokens[0]}`) ? 2 : 3,
        }))
        .sort((a, b) => a.rank - b.rank || TYPE_ORDER[a.p.type] - TYPE_ORDER[b.p.type] || a.p.label.localeCompare(b.p.label))
        .slice(0, limit)
        .map(({ p }) => p);
}

/**
 * The place a free-text venue most likely names, for records saved before
 * destinations existed: "…, Lahug, Cebu City." → Cebu City.
 */
export function guessPlace(list, venue) {
    const text = ` ${normalize(venue)} `;
    if (!text.trim()) return null;

    let best = null;
    let bestScore = 0;
    for (const p of list) {
        if (!text.includes(` ${p.key} `)) continue;
        const withProvince = p.province && text.includes(` ${normalize(p.province)} `);
        let score;
        if (p.type === 'province') score = 10;
        else if (withProvince) score = 50 + (p.type === 'city' ? 30 : 20);
        else if (p.unique) score = p.type === 'city' ? 30 : 20;
        else continue;
        score += p.key.length / 100;
        if (score > bestScore) {
            best = p;
            bestScore = score;
        }
    }
    return best;
}

/** EO 77 daily travel expense at the destination; the default rate until one is picked. */
export function perDiemRate(code, options) {
    return Number((code && options.dteRates?.[code.slice(0, 2)]) || options.perDiemRate);
}

/**
 * Move per diem figures still at the old rate (full day, half day, or less
 * `lunch`) to the new one; amounts someone typed in by hand stay. Returns
 * how many changed.
 */
export function rescalePerDiem(items, from, to, lunch = 0) {
    if (!from || !to || from === to) return 0;

    let changed = 0;
    for (const item of items) {
        if (item.per_diem === '' || item.per_diem === null) continue;
        const amount = Number(item.per_diem);
        if (amount === from) item.per_diem = to;
        else if (amount === from / 2) item.per_diem = to / 2;
        else if (lunch && amount === from - lunch) item.per_diem = to - lunch;
        else continue;
        changed++;
    }
    return changed;
}

/**
 * Lunch provided at the activity: per diem on the days there — not the days
 * going and coming back — moves between the full rate and the rate less
 * `lunch`. Only figures still at the one move; hand-typed amounts stay.
 * Returns how many changed.
 */
export function applyLunch(form, rate, lunch, provided) {
    if (!lunch) return 0;
    const [from, to] = provided ? [rate, rate - lunch] : [rate - lunch, rate];

    let changed = 0;
    // A leg without a date continues the day above.
    let day = form.departure_date;
    for (const item of form.items) {
        day = item.date || day;
        const there = day > form.departure_date && day < form.return_date;
        if (!there || item.per_diem === '' || item.per_diem === null || Number(item.per_diem) !== from) continue;
        item.per_diem = to;
        changed++;
    }
    return changed;
}
