/** Passengers on a booking request, and filling one in from a saved traveller. */

export function blankPassenger(extra = {}) {
    return {
        traveler_id: null, title: '', first_name: '', last_name: '', remarks: '', id_number: '',
        contact_number: '', email: '', birth_date: '', going_baggage: false, return_baggage: false, ...extra,
    };
}

// Surname particles that belong to the last name: "DELOS REYES", "DELA CRUZ".
const PARTICLES = /^(DE|DEL|DELA|DELAS|DELOS|DE LA|SAN|STA\.?|STO\.?)$/i;
const SUFFIXES = /^(JR\.?|SR\.?|II|III|IV)$/i;

/**
 * "VINCE BRADLEY C. MULOC" → { first: 'VINCE BRADLEY', middle: 'C.', last: 'MULOC' }.
 * A middle initial splits the name; without one, the last word (with any
 * particle or suffix) is the surname. Airlines want names as on the ID.
 */
export function splitName(name) {
    const words = (name || '').trim().split(/\s+/).filter(Boolean);
    if (words.length < 2) return { first: words.join(' '), middle: '', last: '' };

    const initial = words.findIndex((w, i) => i > 0 && /^[A-Z]\.$/i.test(w));
    if (initial > 0) {
        return { first: words.slice(0, initial).join(' '), middle: words[initial], last: words.slice(initial + 1).join(' ') };
    }

    let cut = words.length - 1;
    if (cut > 1 && SUFFIXES.test(words[cut])) cut--;
    while (cut > 1 && PARTICLES.test(words[cut - 1])) cut--;
    return { first: words.slice(0, cut).join(' '), middle: '', last: words.slice(cut).join(' ') };
}

/** A saved traveller as a passenger: their saved booking details, or their name split. */
export function fromTraveler(traveler, extra = {}) {
    const split = splitName(traveler.name);
    return blankPassenger({
        traveler_id: traveler.id,
        title: traveler.title || '',
        first_name: traveler.first_name || split.first,
        last_name: traveler.last_name || split.last,
        id_number: traveler.id_number || '',
        contact_number: traveler.contact_number || '',
        email: traveler.email || '',
        birth_date: traveler.birth_date || '',
        ...extra,
    });
}

/** The saved traveller with this name, ignoring case. */
export function findTraveler(travelers, name) {
    const key = (name || '').trim().toUpperCase();
    return key ? travelers.find(t => t.name.toUpperCase() === key) : undefined;
}

/**
 * What the form marks required (*) that is still blank — the same rules as
 * BookingRequest::missing() on the server.
 */
export function missingFor(form, hasReturn) {
    const missing = [];
    if (!String(form.departure_etd || '').trim()) missing.push('Preferred ETD (going)');
    if (hasReturn) {
        if (!form.return_date) missing.push('Return date');
        if (!String(form.return_etd || '').trim()) missing.push('Preferred ETD (return)');
    }
    form.passengers.forEach((p, i) => {
        const blank = [
            !String(p.contact_number || '').trim() && 'contact number',
            !String(p.email || '').trim() && 'email',
            !p.birth_date && 'date of birth',
        ].filter(Boolean);
        if (blank.length) missing.push(`Passenger ${i + 1}: ${blank.join(', ')}`);
    });
    return missing;
}
