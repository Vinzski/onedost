<script>
import { findTraveler, fromTraveler } from './passengers.js';

/** One passenger column of the booking form. */
export default {
    props: {
        passenger: { type: Object, required: true },
        index: { type: Number, required: true },
        count: { type: Number, required: true },
        // The page form's errors, keyed "passengers.0.first_name".
        errors: { type: Object, default: () => ({}) },
        travelers: { type: Array, default: () => [] },
        hasReturn: { type: Boolean, default: true },
    },

    emits: ['remove', 'move'],

    data() {
        return { lookup: '' };
    },

    computed: {
        id() {
            return `passenger-${this.index}`;
        },
        linked() {
            return this.passenger.traveler_id ? this.travelers.find(t => t.id === this.passenger.traveler_id) : null;
        },
    },

    methods: {
        error(field) {
            return this.errors[`passengers.${this.index}.${field}`];
        },

        // A saved name fills in everything the traveller saved last time.
        pick() {
            const saved = findTraveler(this.travelers, this.lookup);
            if (!saved) return;
            const filled = fromTraveler(saved, {
                remarks: this.passenger.remarks,
                going_baggage: this.passenger.going_baggage,
                return_baggage: this.passenger.return_baggage,
            });
            Object.assign(this.passenger, filled);
            this.lookup = '';
        },

        unlink() {
            this.passenger.traveler_id = null;
        },
    },
};
</script>

<template>
    <div class="card h-100 passenger">
        <div class="card-header d-flex align-items-center gap-1 py-2">
            <span class="fw-semibold small">Passenger {{ index + 1 }}</span>
            <button type="button" class="btn btn-sm btn-link px-1 ms-auto" :disabled="index === 0" :aria-label="`Move passenger ${index + 1} left`"
                    @click="$emit('move', -1)"><i class="ri-arrow-left-line"></i></button>
            <button type="button" class="btn btn-sm btn-link px-1" :disabled="index === count - 1" :aria-label="`Move passenger ${index + 1} right`"
                    @click="$emit('move', 1)"><i class="ri-arrow-right-line"></i></button>
            <button type="button" class="btn btn-sm btn-link px-1 text-danger" :disabled="count === 1" :aria-label="`Remove passenger ${index + 1}`"
                    @click="$emit('remove')"><i class="ri-close-line"></i></button>
        </div>
        <div class="card-body">
            <div class="mb-2">
                <label :for="`${id}-lookup`" class="form-label small mb-1">Saved traveller</label>
                <input :id="`${id}-lookup`" v-model="lookup" type="text" class="form-control form-control-sm" list="booking-travelers"
                       placeholder="Pick a name to fill this in" autocomplete="off" @change="pick">
                <div v-if="linked" class="form-text">
                    <i class="ri-user-follow-line"></i> {{ linked.name }} — saved for next time
                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="unlink">Not them</button>
                </div>
            </div>

            <div class="btn-group btn-group-sm mb-2" role="group" :aria-label="`Passenger ${index + 1} title`">
                <template v-for="title in ['Ms.', 'Mr.']" :key="title">
                    <input :id="`${id}-title-${title}`" v-model="passenger.title" type="radio" class="btn-check" :value="title" :name="`${id}-title`">
                    <label class="btn btn-outline-secondary" :for="`${id}-title-${title}`">{{ title }}</label>
                </template>
            </div>

            <div class="row g-2">
                <div class="col-6">
                    <label :for="`${id}-first`" class="form-label small mb-1">First name *</label>
                    <input :id="`${id}-first`" v-model="passenger.first_name" type="text" class="form-control form-control-sm"
                           :class="{ 'is-invalid': error('first_name') }">
                    <div class="invalid-feedback">{{ error('first_name') }}</div>
                </div>
                <div class="col-6">
                    <label :for="`${id}-last`" class="form-label small mb-1">Last name *</label>
                    <input :id="`${id}-last`" v-model="passenger.last_name" type="text" class="form-control form-control-sm"
                           :class="{ 'is-invalid': error('last_name') }">
                    <div class="invalid-feedback">{{ error('last_name') }}</div>
                </div>
                <div class="col-12 form-text mt-0">As on their ID — no middle initial.</div>
                <div class="col-6">
                    <label :for="`${id}-birth`" class="form-label small mb-1">Date of birth</label>
                    <input :id="`${id}-birth`" v-model="passenger.birth_date" type="date" class="form-control form-control-sm"
                           :class="{ 'is-invalid': error('birth_date') }">
                    <div class="invalid-feedback">{{ error('birth_date') }}</div>
                </div>
                <div class="col-6">
                    <label :for="`${id}-contact`" class="form-label small mb-1">Contact number</label>
                    <input :id="`${id}-contact`" v-model="passenger.contact_number" type="tel" class="form-control form-control-sm"
                           placeholder="09XX XXX XXXX" :class="{ 'is-invalid': error('contact_number') }">
                    <div class="invalid-feedback">{{ error('contact_number') }}</div>
                </div>
                <div class="col-12">
                    <label :for="`${id}-email`" class="form-label small mb-1">Email</label>
                    <input :id="`${id}-email`" v-model="passenger.email" type="email" class="form-control form-control-sm"
                           :class="{ 'is-invalid': error('email') }">
                    <div class="invalid-feedback">{{ error('email') }}</div>
                </div>
                <div class="col-6">
                    <label :for="`${id}-idno`" class="form-label small mb-1">Senior/PWD ID/Passport</label>
                    <input :id="`${id}-idno`" v-model="passenger.id_number" type="text" class="form-control form-control-sm"
                           :class="{ 'is-invalid': error('id_number') }">
                </div>
                <div class="col-6">
                    <label :for="`${id}-remarks`" class="form-label small mb-1">Note under name</label>
                    <input :id="`${id}-remarks`" v-model="passenger.remarks" type="text" class="form-control form-control-sm" maxlength="100"
                           placeholder="e.g. Resource person">
                </div>
            </div>

            <div class="mt-3 small fw-semibold">Check-in baggage</div>
            <div class="form-check form-check-inline">
                <input :id="`${id}-bag-going`" v-model="passenger.going_baggage" class="form-check-input" type="checkbox">
                <label class="form-check-label small" :for="`${id}-bag-going`">Going</label>
            </div>
            <div v-if="hasReturn" class="form-check form-check-inline">
                <input :id="`${id}-bag-return`" v-model="passenger.return_baggage" class="form-check-input" type="checkbox">
                <label class="form-check-label small" :for="`${id}-bag-return`">Return</label>
            </div>
        </div>
    </div>
</template>

<style scoped>
.passenger .card-header { background: var(--surface-sunken); }
</style>
