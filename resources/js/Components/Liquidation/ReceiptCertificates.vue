<script>
import { peso } from '@/Shared/format.js';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** "2026-09-20" → "20 September 2026", as the certificates word dates. */
function longDate(value) {
    const [y, m, d] = value.split('-').map(Number);
    return `${d} ${MONTHS[m - 1]} ${y}`;
}

function line(particulars = '', amount = '') {
    return { particulars, amount };
}

export default {
    props: {
        // The page's useForm(); this editor works on form.receipt_certificates.
        form: { type: Object, required: true },
        options: { type: Object, required: true },
    },

    data() {
        return { confirmingFill: false, notice: null };
    },

    computed: {
        certificates() {
            return this.form.receipt_certificates;
        },
        max() {
            return this.options.cenrrMaxAmount;
        },
        maxLines() {
            return this.options.cenrrMaxLines;
        },
        // Fares in the itinerary, worded for a certificate: a "from → to" leg is
        // transportation; anything else (e.g. "Porterage") is named as is. A leg
        // without a date takes the day above's.
        fares() {
            let day = '';
            return this.form.items
                .map((item) => {
                    day = item.date || day;
                    return { item, day };
                })
                .filter(({ item }) => Number(item.transport_cost) > 0 && item.place)
                .map(({ item, day: on }) => {
                    const when = on ? ` on ${longDate(on)}` : '';
                    return line(
                        / to /i.test(item.place) ? `Payment for Transportation from ${item.place}${when}` : `Payment for ${item.place}${when}`,
                        Number(item.transport_cost),
                    );
                });
        },
    },

    methods: {
        peso,

        total(certificate) {
            return certificate.lines.reduce((sum, l) => sum + Number(l.amount || 0), 0);
        },

        fill(certificate) {
            return Math.min(100, (this.total(certificate) / this.max) * 100);
        },

        error(path) {
            return this.form.errors[`receipt_certificates.${path}`];
        },

        lineErrors(c, l) {
            return [this.error(`${c}.lines.${l}.particulars`), this.error(`${c}.lines.${l}.amount`)].filter(Boolean);
        },

        addCertificate() {
            this.certificates.push({ lines: [line()] });
        },

        removeCertificate(c) {
            this.certificates.splice(c, 1);
            this.form.clearErrors();
        },

        addLine(certificate) {
            if (certificate.lines.length < this.maxLines) certificate.lines.push(line());
        },

        removeLine(c, l) {
            this.certificates[c].lines.splice(l, 1);
            if (!this.certificates[c].lines.length) this.certificates.splice(c, 1);
            this.form.clearErrors();
        },

        /** Pack the itinerary's fares into certificates of at most ₱300 and three items. */
        fillFromItinerary() {
            if (this.certificates.length && !this.confirmingFill) {
                this.confirmingFill = true;
                return;
            }
            this.confirmingFill = false;

            const fits = this.fares.filter(f => f.amount <= this.max);
            const tooBig = this.fares.length - fits.length;
            const packed = [];
            for (const fare of fits) {
                const current = packed[packed.length - 1];
                if (current && current.lines.length < this.maxLines && this.total(current) + fare.amount <= this.max) {
                    current.lines.push(fare);
                } else {
                    packed.push({ lines: [fare] });
                }
            }

            this.certificates.splice(0, this.certificates.length, ...packed);
            this.form.clearErrors();
            this.notice = tooBig
                ? `${tooBig} fare${tooBig === 1 ? ' is' : 's are'} over ${peso(this.max)} and can't go on a certificate — those need a receipt.`
                : null;
        },
    },
};
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <button type="button" class="btn btn-sm btn-outline-primary" :disabled="!fares.length" @click="fillFromItinerary"
                    :title="fares.length ? '' : 'No fares in the itinerary yet'">
                <i class="ri-magic-line me-1"></i>Fill from itinerary fares
            </button>
            <template v-if="confirmingFill">
                <span class="small text-danger">This replaces the {{ certificates.length }} certificate{{ certificates.length === 1 ? '' : 's' }} below.</span>
                <button type="button" class="btn btn-sm btn-danger" @click="fillFromItinerary">Replace</button>
                <button type="button" class="btn btn-sm btn-link" @click="confirmingFill = false">Keep mine</button>
            </template>
            <span v-else class="small text-body-secondary">
                For fares and small expenses paid without an official receipt. Each certificate holds up to {{ maxLines }} items and {{ peso(max) }}.
            </span>
        </div>

        <div v-if="notice" class="small text-warning-emphasis mb-2">{{ notice }}</div>

        <p v-if="!certificates.length" class="small text-body-secondary mb-3">
            No certificates — only needed when an expense came without a receipt.
        </p>

        <div class="row g-3 mb-3">
            <div v-for="(certificate, c) in certificates" :key="c" class="col-12">
                <div class="certificate h-100" :class="{ 'is-over': total(certificate) > max || error(c) }">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <strong class="small">Certificate {{ c + 1 }}</strong>
                        <span class="ms-auto small num" :class="total(certificate) > max ? 'text-danger fw-semibold' : 'text-body-secondary'">
                            {{ peso(total(certificate)) }} of {{ peso(max) }}
                        </span>
                        <button type="button" class="btn btn-sm btn-link text-danger px-1" :aria-label="`Remove certificate ${c + 1}`" @click="removeCertificate(c)">
                            <i class="ri-delete-bin-line"></i>
                        </button>
                    </div>
                    <div class="meter mb-2" role="progressbar" :aria-valuenow="total(certificate)" aria-valuemin="0" :aria-valuemax="max"
                         :aria-label="`Certificate ${c + 1} total`">
                        <div class="meter-fill" :style="{ width: `${fill(certificate)}%` }"></div>
                    </div>

                    <div v-for="(item, l) in certificate.lines" :key="l" class="mb-2">
                        <div class="d-flex gap-2">
                            <input v-model="item.particulars" type="text" class="form-control form-control-sm" maxlength="255"
                                   :class="{ 'is-invalid': error(`${c}.lines.${l}.particulars`) }"
                                   placeholder="Particulars, e.g. Payment for Transportation from Residence to DOST IX Office on 20 September 2026"
                                   :aria-label="`Certificate ${c + 1}, item ${l + 1} particulars`">
                            <input v-model="item.amount" type="number" min="0" :max="max" step="0.01" class="form-control form-control-sm text-end num amount"
                                   :class="{ 'is-invalid': error(`${c}.lines.${l}.amount`) }" placeholder="₱"
                                   :aria-label="`Certificate ${c + 1}, item ${l + 1} amount`">
                            <button type="button" class="btn btn-sm btn-link text-danger px-1" :aria-label="`Remove item ${l + 1} of certificate ${c + 1}`"
                                    @click="removeLine(c, l)">
                                <i class="ri-close-line"></i>
                            </button>
                        </div>
                        <div v-for="message in lineErrors(c, l)" :key="message" class="small text-danger">{{ message }}</div>
                    </div>

                    <div v-if="error(c) || error(`${c}.lines`)" class="small text-danger mb-2">{{ error(c) || error(`${c}.lines`) }}</div>
                    <button v-if="certificate.lines.length < maxLines" type="button" class="btn btn-sm btn-link px-0" @click="addLine(certificate)">
                        <i class="ri-add-line me-1"></i>Add item
                    </button>
                </div>
            </div>
        </div>

        <button type="button" class="btn btn-sm btn-outline-secondary" @click="addCertificate">
            <i class="ri-add-line me-1"></i>Add certificate
        </button>
    </div>
</template>

<style scoped>
.certificate {
    border: 1px solid var(--hairline);
    border-radius: var(--r-lg);
    padding: .75rem .9rem;
    background: var(--surface-sunken);
}
.certificate.is-over { border-color: var(--bs-danger); }
.amount { width: 6.5rem; flex-shrink: 0; }
.meter { height: 4px; border-radius: 2px; background: var(--hairline); overflow: hidden; }
.meter-fill { height: 100%; background: var(--brand); transition: width .15s ease; }
.is-over .meter-fill { background: var(--bs-danger); }

/* Spinner arrows eat the space amounts need. */
.amount { appearance: textfield; -moz-appearance: textfield; }
.amount::-webkit-outer-spin-button,
.amount::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
</style>
