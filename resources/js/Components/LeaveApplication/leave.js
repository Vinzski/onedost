/** Days and dates on the Application for Leave. */

function parse(value) {
    if (!value) return null;
    const [y, m, d] = value.split('-').map(Number);
    return new Date(y, m - 1, d);
}

const monthDay = new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric' });
const full = new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' });

/** Weekdays from one 'YYYY-MM-DD' to another, both counted (holidays aren't known here). */
export function workingDays(start, end) {
    const from = parse(start);
    const to = parse(end);
    if (!from || !to || to < from) return null;

    let days = 0;
    for (const day = new Date(from); day <= to; day.setDate(day.getDate() + 1)) {
        if (day.getDay() !== 0 && day.getDay() !== 6) days++;
    }
    return days;
}

/**
 * "October 7-8, 2026" — 6.C's inclusive dates, worded as
 * LeaveApplication::wordDates() does on the server. Change both.
 */
export function inclusiveDates(start, end) {
    const from = parse(start);
    const to = parse(end);
    if (!from || !to || to < from) return '';

    const sameYear = from.getFullYear() === to.getFullYear();
    if (sameYear && from.getMonth() === to.getMonth()) {
        if (from.getDate() === to.getDate()) return full.format(to);
        return `${monthDay.format(from)}-${to.getDate()}, ${to.getFullYear()}`;
    }
    if (sameYear) return `${monthDay.format(from)} - ${full.format(to)}`;
    return `${full.format(from)} - ${full.format(to)}`;
}

/** "2 DAYS", "1 DAY", "0.5 DAY" — as 6.C prints it (LeaveApplication::daysApplied()). */
export function daysApplied(days) {
    const n = Number(days);
    if (!n) return '';
    return `${n} ${n > 1 ? 'DAYS' : 'DAY'}`;
}
