<script>
import { router, useForm } from '@inertiajs/vue3';

/**
 * Why some DTR times are blank: which times (or the whole day), the reason,
 * and optionally details, the time it really was, and a last day for a run
 * of days. Adds a note, or changes the one passed in.
 */
export default {
    props: {
        personId: { type: Number, required: true },
        options: { type: Object, required: true },
        // The note to change; null adds one.
        note: { type: Object, default: null },
        date: { type: String, default: '' },
        // Times to start with ticked; empty is the whole day.
        slots: { type: Array, default: () => [] },
        // Show the date picker (the quick "today" card); the day dialog already says which day.
        pickDate: { type: Boolean, default: false },
        today: { type: String, default: '' },
        idPrefix: { type: String, default: 'note' },
    },

    emits: ['saved', 'cancel'],

    data() {
        const n = this.note;
        return {
            form: useForm({
                traveler_id: this.personId,
                starts_on: n?.starts_on ?? this.date,
                ends_on: n?.ends_on ?? this.date,
                slots: [...(n?.slots ?? this.slots)],
                reason: n?.reason ?? '',
                details: n?.details ?? '',
                actual_time: n?.actual_time ?? '',
            }),
            spanning: Boolean(n && n.ends_on !== n.starts_on),
            confirmingDelete: false,
        };
    },

    computed: {
        wholeDay() {
            return this.form.slots.length === 0;
        },
        reason() {
            return this.options.reasons.find(r => r.value === this.form.reason);
        },
        // The actual time only means something for one time on one day.
        actualFor() {
            if (this.spanning || this.form.slots.length !== 1) return null;
            return this.options.slots.find(s => s.value === this.form.slots[0]);
        },
        isToday() {
            return this.form.starts_on === this.today;
        },
        errorText() {
            return Object.values(this.form.errors)[0];
        },
    },

    watch: {
        personId(id) {
            this.form.traveler_id = id;
        },
        'form.starts_on'(start) {
            if (!this.spanning || this.form.ends_on < start) this.form.ends_on = start;
        },
    },

    methods: {
        toggle(slot) {
            const slots = this.form.slots;
            this.form.slots = slots.includes(slot) ? slots.filter(s => s !== slot) : [...slots, slot];
        },

        chosen(reason) {
            return this.form.reason === reason.value;
        },

        reasonClass(reason) {
            if (!this.chosen(reason)) return 'btn-outline-secondary';
            return `btn-${reason.variant === 'light' ? 'secondary' : reason.variant}`;
        },

        stopSpanning() {
            this.spanning = false;
            this.form.ends_on = this.form.starts_on;
        },

        submit() {
            const options = {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    if (!this.note) this.form.reset('reason', 'details', 'actual_time', 'slots');
                    this.$emit('saved');
                },
            };
            this.form.transform(data => ({
                ...data,
                ends_on: this.spanning ? data.ends_on : data.starts_on,
                actual_time: this.actualFor ? data.actual_time : '',
            }));
            if (this.note) {
                this.form.put(route('dtr.notes.update', this.note.id), options);
            } else {
                this.form.post(route('dtr.notes.store'), options);
            }
        },

        destroy() {
            router.delete(route('dtr.notes.destroy', this.note.id), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => this.$emit('saved'),
            });
        },
    },
};
</script>

<template>
    <form class="note-form" novalidate @submit.prevent="submit">
        <div v-if="pickDate" class="d-flex flex-wrap align-items-end gap-2 mb-3">
            <div>
                <label :for="`${idPrefix}-date`" class="form-label small mb-1">{{ spanning ? 'First day' : 'Day' }}</label>
                <input :id="`${idPrefix}-date`" v-model="form.starts_on" type="date" class="form-control form-control-sm"
                       :class="{ 'is-invalid': form.errors.starts_on }">
            </div>
            <div v-if="spanning">
                <label :for="`${idPrefix}-until`" class="form-label small mb-1">Last day</label>
                <input :id="`${idPrefix}-until`" v-model="form.ends_on" type="date" class="form-control form-control-sm" :min="form.starts_on"
                       :class="{ 'is-invalid': form.errors.ends_on }">
            </div>
            <span v-if="isToday && !spanning" class="badge text-bg-primary mb-2">Today</span>
            <button v-if="today && !isToday" type="button" class="btn btn-link btn-sm px-0 mb-1" @click="form.starts_on = today">Today</button>
            <button v-if="!spanning" type="button" class="btn btn-link btn-sm px-0 mb-1" @click="spanning = true">Several days?</button>
            <button v-else type="button" class="btn btn-link btn-sm px-0 mb-1" @click="stopSpanning">Just one day</button>
        </div>

        <!-- Which times -->
        <div class="mb-3">
            <div class="form-label small mb-1">What's blank</div>
            <div class="d-flex flex-wrap gap-1" role="group" aria-label="What's blank">
                <button type="button" class="btn btn-sm" :class="wholeDay ? 'btn-primary' : 'btn-outline-primary'" :aria-pressed="wholeDay"
                        @click="form.slots = []">Whole day</button>
                <button v-for="s in options.slots" :key="s.value" type="button" class="btn btn-sm"
                        :class="form.slots.includes(s.value) ? 'btn-primary' : 'btn-outline-primary'" :aria-pressed="form.slots.includes(s.value)"
                        :title="s.form_label" @click="toggle(s.value)">{{ s.label }}</button>
            </div>
        </div>

        <!-- Why -->
        <div class="mb-3">
            <div class="form-label small mb-1">Why</div>
            <div class="d-flex flex-wrap gap-1" role="radiogroup" aria-label="Why">
                <button v-for="r in options.reasons" :key="r.value" type="button" class="btn btn-sm reason" :class="reasonClass(r)"
                        role="radio" :aria-checked="chosen(r)" @click="form.reason = r.value">
                    <i :class="r.icon" class="me-1"></i>{{ r.short }}
                </button>
            </div>
        </div>

        <div class="row g-2 align-items-end">
            <div :class="actualFor ? 'col-sm-8' : 'col-12'">
                <label :for="`${idPrefix}-details`" class="form-label small mb-1">Details{{ reason?.needs_details ? ' *' : '' }}</label>
                <input :id="`${idPrefix}-details`" v-model="form.details" type="text" class="form-control form-control-sm" maxlength="255"
                       :placeholder="reason?.hint ?? 'Optional'" :class="{ 'is-invalid': form.errors.details }">
            </div>
            <div v-if="actualFor" class="col-sm-4">
                <label :for="`${idPrefix}-actual`" class="form-label small mb-1">Actual {{ actualFor.label.toLowerCase() }}</label>
                <input :id="`${idPrefix}-actual`" v-model="form.actual_time" type="time" class="form-control form-control-sm"
                       :class="{ 'is-invalid': form.errors.actual_time }">
            </div>
            <div v-if="!pickDate && !spanning" class="col-12">
                <button type="button" class="btn btn-link btn-sm px-0" @click="spanning = true">The same for several days?</button>
            </div>
            <div v-if="!pickDate && spanning" class="col-sm-6">
                <label :for="`${idPrefix}-until`" class="form-label small mb-1">Through</label>
                <div class="input-group input-group-sm">
                    <input :id="`${idPrefix}-until`" v-model="form.ends_on" type="date" class="form-control" :min="form.starts_on"
                           :class="{ 'is-invalid': form.errors.ends_on }">
                    <button type="button" class="btn btn-outline-secondary" @click="stopSpanning">Just this day</button>
                </div>
            </div>
        </div>

        <div v-if="errorText" class="small text-danger mt-2"><i class="ri-error-warning-line me-1"></i>{{ errorText }}</div>

        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            <template v-if="note">
                <template v-if="confirmingDelete">
                    <span class="small text-danger">Delete this note?</span>
                    <button type="button" class="btn btn-sm btn-danger" @click="destroy">Delete</button>
                    <button type="button" class="btn btn-sm btn-link" @click="confirmingDelete = false">Keep</button>
                </template>
                <button v-else type="button" class="btn btn-sm btn-outline-danger" @click="confirmingDelete = true">
                    <i class="ri-delete-bin-line me-1"></i>Delete
                </button>
                <button type="button" class="btn btn-sm btn-link ms-auto" @click="$emit('cancel')">Cancel</button>
            </template>
            <button type="submit" class="btn btn-sm btn-primary" :class="{ 'ms-auto': !note }" :disabled="form.processing || !form.reason">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ note ? 'Save changes' : 'Save note' }}
            </button>
        </div>
    </form>
</template>

<style scoped>
.reason { white-space: nowrap; }
</style>
