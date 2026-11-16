/**
 * The fares memo, applied to a traveller: what they may claim for a ride
 * between home and the office (`office`) or the airport, wharf or bus
 * terminal (`terminal`), from where they live and how far. Mirrors
 * App\Services\Travel\HomeFares — keep legKind() the same in both.
 *
 * `traveler` below is anything with home_area, office_distance and
 * terminal_distance: the page's form, or a saved traveller.
 */

export const OFFICE = 'office';
export const TERMINAL = 'terminal';
export const FARE_FIELDS = ['home_area', 'office_distance', 'terminal_distance'];

/** "Residence to DOST IX Office" → office, "Zamboanga Airport to Residence" → terminal, else null. */
export function legKind(place) {
    const text = place || '';
    if (!/\b(residence|home)\b/i.test(text)) return null;
    if (/\b(airport|port|wharf|pier|terminal|ibt)\b/i.test(text)) return TERMINAL;
    return /\b(office|dost)\b/i.test(text) ? OFFICE : null;
}

/** Distance bands for an area: [{ label: '8–15 km', fare: 250 }, …]. */
export function distances(options, area, kind) {
    return Object.entries(options.homeFares?.[area]?.[kind] ?? {}).map(([label, fare]) => ({ label, fare: Number(fare) }));
}

/** The memo's fare for this traveller, or null while their area or distance isn't set. */
export function homeFare(traveler, kind, options) {
    const distance = kind === OFFICE ? traveler.office_distance : traveler.terminal_distance;
    const fare = options.homeFares?.[traveler.home_area]?.[kind]?.[distance];
    return fare === undefined ? null : Number(fare);
}

/** Both fares a home leg starts with: the traveller's own, else the defaults. */
export function startingFares(traveler, options) {
    return {
        [OFFICE]: homeFare(traveler, OFFICE, options) ?? Number(options.commuteFare),
        [TERMINAL]: homeFare(traveler, TERMINAL, options) ?? Number(options.terminalFare),
    };
}

/** A saved traveller's fare fields, or the defaults for someone new. */
export function fareFields(saved, options) {
    return {
        home_area: saved?.home_area || options.homeArea || '',
        office_distance: saved?.office_distance || '',
        terminal_distance: saved?.terminal_distance || '',
    };
}

/**
 * Put the new fares on home legs that are blank or still at the old fare;
 * amounts typed in by hand stay. Returns how many legs changed.
 */
export function refare(items, from, to) {
    let changed = 0;
    for (const item of items) {
        const kind = legKind(item.place);
        if (!kind) continue;
        const blank = item.transport_cost === '' || item.transport_cost === null || item.transport_cost === undefined;
        const current = Number(item.transport_cost);
        if ((!blank && current !== from[kind]) || (!blank && current === to[kind])) continue;
        item.transport_cost = to[kind];
        changed++;
    }
    return changed;
}
