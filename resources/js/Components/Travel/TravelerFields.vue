<script>
import { peso } from '@/Shared/format.js';
import { distances, fareFields, OFFICE, refare, startingFares, TERMINAL } from './fares.js';

/** Traveller details, shared by the cash advance and liquidation forms. */
export default {
    props: {
        // The page's useForm().
        form: { type: Object, required: true },
        // Saved travellers; picking one fills in the rest.
        travelers: { type: Array, default: () => [] },
        // Page options: homeFares (the fares memo), commuteFare, terminalFare.
        options: { type: Object, required: true },
    },

    computed: {
        areas() {
            return Object.entries(this.options.homeFares ?? {}).map(([key, area]) => ({ key, label: area.label }));
        },
        officeDistances() {
            return distances(this.options, this.form.home_area, OFFICE);
        },
        terminalDistances() {
            return distances(this.options, this.form.home_area, TERMINAL);
        },
        missingDistance() {
            return !this.form.office_distance || !this.form.terminal_distance;
        },
    },

    methods: {
        peso,

        fillTraveler() {
            const name = this.form.traveler_name.trim().toUpperCase();
            const saved = this.travelers.find(t => t.name.toUpperCase() === name);
            if (!saved) return;
            this.form.traveler_name = saved.name;
            ['position', 'employee_no', 'official_station', 'division'].forEach((key) => {
                this.form[key] = saved[key] ?? '';
            });
            this.changeFares(fareFields(saved, this.options));
        },

        setArea(area) {
            // Distances differ by area; keep only ones the new area also has.
            const keep = (kind, current) => (distances(this.options, area, kind).some(d => d.label === current) ? current : '');
            this.changeFares({
                home_area: area,
                office_distance: keep(OFFICE, this.form.office_distance),
                terminal_distance: keep(TERMINAL, this.form.terminal_distance),
            });
        },

        // New fare details: residence legs still at the old fare (or blank) follow.
        changeFares(fields) {
            const before = startingFares(this.form, this.options);
            Object.assign(this.form, fields);
            const after = startingFares(this.form, this.options);
            const changed = refare(this.form.items, before, after);
            if (changed) {
                this.$toast.add(`Fares from home updated on ${changed} leg${changed === 1 ? '' : 's'}.`, 'info');
            }
        },
    },
};
</script>

<template>
    <div class="row g-3">
        <div class="col-md-6">
            <label for="traveler_name" class="form-label">Name</label>
            <input id="traveler_name" v-model="form.traveler_name" type="text" class="form-control" list="travelers-list"
                   :class="{ 'is-invalid': form.errors.traveler_name }" placeholder="As it should print, e.g. VINCE BRADLEY C. MULOC"
                   autocomplete="off" @change="fillTraveler">
            <div class="invalid-feedback">{{ form.errors.traveler_name }}</div>
            <div v-if="travelers.length" class="form-text">Pick a saved name to fill in the rest.</div>
            <datalist id="travelers-list">
                <option v-for="t in travelers" :key="t.name" :value="t.name">{{ t.position }}</option>
            </datalist>
        </div>
        <div class="col-md-6">
            <label for="position" class="form-label">Position</label>
            <input id="position" v-model="form.position" type="text" class="form-control" :class="{ 'is-invalid': form.errors.position }">
            <div class="invalid-feedback">{{ form.errors.position }}</div>
        </div>
        <div class="col-md-3">
            <label for="employee_no" class="form-label">Employee no.</label>
            <input id="employee_no" v-model="form.employee_no" type="text" class="form-control" :class="{ 'is-invalid': form.errors.employee_no }">
            <div class="invalid-feedback">{{ form.errors.employee_no }}</div>
        </div>
        <div class="col-md-4">
            <label for="official_station" class="form-label">Official station</label>
            <input id="official_station" v-model="form.official_station" type="text" class="form-control" :class="{ 'is-invalid': form.errors.official_station }">
            <div class="invalid-feedback">{{ form.errors.official_station }}</div>
        </div>
        <div class="col-md-5">
            <label for="division" class="form-label">Division</label>
            <input id="division" v-model="form.division" type="text" class="form-control" :class="{ 'is-invalid': form.errors.division }">
            <div class="invalid-feedback">{{ form.errors.division }}</div>
        </div>

        <!-- Fares memo: saved with the traveller, so their residence legs fill themselves in. -->
        <div class="col-12">
            <fieldset class="fares">
                <legend class="fares-title">
                    Fares from home
                    <span class="fw-normal text-body-secondary">— per the fares memo; saved with this traveller for next time</span>
                </legend>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label for="home_area" class="form-label small mb-1">Lives in</label>
                        <select id="home_area" class="form-select form-select-sm" :value="form.home_area"
                                :class="{ 'is-invalid': form.errors.home_area }" @change="setArea($event.target.value)">
                            <option v-for="area in areas" :key="area.key" :value="area.key">{{ area.label }}</option>
                        </select>
                        <div class="invalid-feedback">{{ form.errors.home_area }}</div>
                    </div>
                    <div class="col-md-4">
                        <label for="office_distance" class="form-label small mb-1">Home ↔ office</label>
                        <select id="office_distance" class="form-select form-select-sm" :value="form.office_distance"
                                :class="{ 'is-invalid': form.errors.office_distance }"
                                @change="changeFares({ office_distance: $event.target.value })">
                            <option value="">How far?</option>
                            <option v-for="d in officeDistances" :key="d.label" :value="d.label">{{ d.label }} — {{ peso(d.fare) }}</option>
                        </select>
                        <div class="invalid-feedback">{{ form.errors.office_distance }}</div>
                    </div>
                    <div class="col-md-4">
                        <label for="terminal_distance" class="form-label small mb-1">Home ↔ airport, wharf or bus terminal</label>
                        <select id="terminal_distance" class="form-select form-select-sm" :value="form.terminal_distance"
                                :class="{ 'is-invalid': form.errors.terminal_distance }"
                                @change="changeFares({ terminal_distance: $event.target.value })">
                            <option value="">How far?</option>
                            <option v-for="d in terminalDistances" :key="d.label" :value="d.label">{{ d.label }} — {{ peso(d.fare) }}</option>
                        </select>
                        <div class="invalid-feedback">{{ form.errors.terminal_distance }}</div>
                    </div>
                </div>
                <div v-if="missingDistance" class="form-text">
                    Until set, residence legs start at {{ peso(options.commuteFare) }} to the office and {{ peso(options.terminalFare) }} to a terminal.
                </div>
            </fieldset>
        </div>
    </div>
</template>

<style scoped>
.fares {
    border: 1px solid var(--hairline);
    border-radius: var(--r-lg);
    background: var(--surface-sunken);
    padding: .65rem .85rem .75rem;
}
.fares-title {
    float: none;
    width: auto;
    font-size: .875rem;
    font-weight: 600;
    margin-bottom: .35rem;
}
</style>
