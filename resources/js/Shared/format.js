// Display formatting. Values arrive from Laravel as plain numbers / 'YYYY-MM-DD' strings.

const pesoFormat = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const dateFormat = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
const dateTimeFormat = new Intl.DateTimeFormat('en-US', {
    month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
});

export function peso(value) {
    if (value === null || value === undefined || value === '') return '—';
    return pesoFormat.format(Number(value));
}

/** 'YYYY-MM-DD' → 'Sep 30, 2026', read as a local date (not UTC midnight). */
export function date(value) {
    if (!value) return '—';
    const [y, m, d] = value.split('-').map(Number);
    return dateFormat.format(new Date(y, m - 1, d));
}

export function dateTime(value) {
    return value ? dateTimeFormat.format(new Date(value)) : '—';
}

/** Whole days from today until 'YYYY-MM-DD' (negative once it has passed). */
export function daysUntil(value) {
    if (!value) return null;
    const [y, m, d] = value.split('-').map(Number);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.round((new Date(y, m - 1, d) - today) / 86400000);
}
