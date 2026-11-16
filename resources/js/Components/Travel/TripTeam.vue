<script>
import { router } from '@inertiajs/vue3';
import { peso } from '@/Shared/format.js';
import { homeFare, OFFICE, TERMINAL } from './fares.js';

/**
 * Everyone on this travel order (group travel), and "Add travellers": each
 * one picked gets their own copy of the trip, with their own saved details
 * and fares from home.
 */
export default {
    props: {
        // [{ id, traveler_name, position, submitted? }], this record included.
        team: { type: Array, required: true },
        currentId: { type: Number, required: true },
        travelOrderNo: { type: String, required: true },
        // Saved travellers (with their fare details).
        travelers: { type: Array, default: () => [] },
        options: { type: Object, required: true },
        // Route name for each member's page, e.g. 'cash-advances.edit'.
        editRoute: { type: String, required: true },
        addUrl: { type: String, required: true },
        // One workbook with everyone's IOT (cash advances).
        downloadUrl: { type: String, default: null },
        // What a copy carries over, for the explanation.
        copies: { type: String, default: 'the itinerary' },
        // Copies come from the saved version: off while there are unsaved changes.
        disabled: { type: Boolean, default: false },
    },

    data() {
        return { adding: false, picked: [], others: [], other: '', error: null, busy: false };
    },

    computed: {
        onBoard() {
            return new Set(this.team.map(m => (m.traveler_name || '').trim().toUpperCase()));
        },
        available() {
            return this.travelers.filter(t => !this.onBoard.has(t.name.toUpperCase()));
        },
        chosen() {
            return [...this.picked, ...this.others];
        },
    },

    methods: {
        peso,

        fareText(traveler) {
            const office = homeFare(traveler, OFFICE, this.options);
            const terminal = homeFare(traveler, TERMINAL, this.options);
            return [office !== null && `${peso(office)} to the office`, terminal !== null && `${peso(terminal)} to a terminal`]
                .filter(Boolean).join(' · ');
        },

        open() {
            this.picked = [];
            this.others = [];
            this.other = '';
            this.error = null;
            this.adding = true;
        },

        addOther() {
            const name = this.other.trim().replace(/\s+/g, ' ');
            if (!name) return;
            const upper = name.toUpperCase();
            const saved = this.available.find(t => t.name.toUpperCase() === upper);
            if (saved) {
                if (!this.picked.includes(saved.name)) this.picked.push(saved.name);
            } else if (!this.onBoard.has(upper) && !this.others.some(o => o.toUpperCase() === upper)) {
                this.others.push(name);
            }
            this.other = '';
        },

        submit() {
            if (this.other.trim()) this.addOther();
            if (!this.chosen.length) {
                this.error = 'Pick at least one traveller.';
                return;
            }
            router.post(this.addUrl, { travelers: this.chosen }, {
                preserveScroll: true,
                onStart: () => { this.busy = true; },
                onFinish: () => { this.busy = false; },
                onSuccess: () => { this.adding = false; },
                onError: errors => { this.error = Object.values(errors)[0] ?? 'Could not add them.'; },
            });
        },
    },
};
</script>

<template>
    <section class="card mb-3 team" aria-labelledby="team-title">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h2 id="team-title" class="h6 mb-0"><i class="ri-team-line me-1"></i>On TO {{ travelOrderNo }}</h2>
                <span class="small text-body-secondary">{{ team.length }} traveller{{ team.length === 1 ? '' : 's' }}</span>
                <div class="ms-auto d-flex flex-wrap gap-2">
                    <a v-if="downloadUrl && team.length > 1" :href="downloadUrl" class="btn btn-sm btn-outline-primary"
                       title="One workbook, a sheet per traveller">
                        <i class="ri-file-excel-2-line me-1"></i>Everyone's IOT
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="disabled" @click="open"
                            :title="disabled ? 'Save first — copies are made from the saved version' : 'Same trip for others on this travel order'">
                        <i class="ri-user-add-line me-1"></i>Add travellers
                    </button>
                </div>
            </div>
            <ul class="list-inline mb-0 mt-2">
                <li v-for="member in team" :key="member.id" class="list-inline-item mb-1">
                    <span v-if="member.id === currentId" class="badge rounded-pill text-bg-primary member" aria-current="page">
                        {{ member.traveler_name || 'Untitled' }}
                    </span>
                    <Link v-else :href="route(editRoute, member.id)" class="badge rounded-pill member member-link text-decoration-none"
                          :title="member.position || 'Position not filled in yet'">
                        {{ member.traveler_name || 'Untitled' }}
                        <i v-if="member.submitted" class="ri-check-line" aria-label="submitted"></i>
                        <i v-else-if="!member.position" class="ri-error-warning-line text-warning" aria-label="needs details"></i>
                    </Link>
                </li>
            </ul>
        </div>

        <BModal v-model="adding" :title="`Add travellers to TO ${travelOrderNo}`" scrollable>
            <p class="small text-body-secondary">
                Each gets their own copy of this trip — {{ copies }} — with their own details and fares from home.
            </p>

            <template v-if="available.length">
                <div class="small fw-semibold mb-1">Saved travellers</div>
                <div class="list-group mb-3">
                    <label v-for="t in available" :key="t.name" class="list-group-item d-flex gap-2 align-items-start">
                        <input v-model="picked" class="form-check-input mt-1 flex-shrink-0" type="checkbox" :value="t.name">
                        <span class="small">
                            <span class="fw-semibold">{{ t.name }}</span>
                            <span class="text-body-secondary"> · {{ t.position }}</span>
                            <span v-if="fareText(t)" class="d-block text-body-secondary">{{ fareText(t) }}</span>
                            <span v-else class="d-block text-warning-emphasis">No fares saved — keeps this trip's residence fares</span>
                        </span>
                    </label>
                </div>
            </template>

            <label for="team-other" class="form-label small fw-semibold mb-1">Someone not saved yet</label>
            <div class="input-group input-group-sm">
                <input id="team-other" v-model="other" type="text" class="form-control" placeholder="Name, as it should print"
                       autocomplete="off" @keydown.enter.prevent="addOther">
                <button type="button" class="btn btn-outline-secondary" :disabled="!other.trim()" @click="addOther">Add</button>
            </div>
            <div v-if="others.length" class="mt-2 d-flex flex-wrap gap-1">
                <span v-for="(name, i) in others" :key="name" class="badge rounded-pill text-bg-light border">
                    {{ name }}
                    <button type="button" class="btn-close btn-close-sm ms-1 align-middle" :aria-label="`Remove ${name}`" @click="others.splice(i, 1)"></button>
                </span>
            </div>
            <div v-if="others.length" class="form-text">Open their copy afterwards to fill in position and fares.</div>
            <div v-if="error" class="small text-danger mt-2">{{ error }}</div>

            <template #footer>
                <div class="d-flex w-100 align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary ms-auto" @click="adding = false">Cancel</button>
                    <button type="button" class="btn btn-primary" :disabled="busy || (!chosen.length && !other.trim())" @click="submit">
                        <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                        Add {{ chosen.length || '' }} traveller{{ chosen.length === 1 ? '' : 's' }}
                    </button>
                </div>
            </template>
        </BModal>
    </section>
</template>

<style scoped>
.team { border-left: 3px solid var(--brand); }
.member { font-size: .8125rem; font-weight: 500; padding: .35rem .7rem; }
.member-link {
    color: var(--ink);
    background: var(--surface-sunken);
    border: 1px solid var(--hairline);
}
.member-link:hover { border-color: var(--brand); }
.btn-close-sm { width: .5rem; height: .5rem; padding: .1rem; }
</style>
