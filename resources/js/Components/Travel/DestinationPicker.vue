<script>
import { markRaw } from 'vue';
import { peso } from '@/Shared/format.js';
import { guessPlace, loadPlaces, perDiemRate, searchPlaces } from './places.js';

/**
 * Type-ahead over every province, city and municipality in the Philippines.
 * The pick is stored as its PSGC code; its region sets the EO 77 per diem,
 * shown under the field.
 */
export default {
    props: {
        // PSGC code.
        modelValue: { type: String, default: '' },
        // The trip's free-text venue: offers a pick when none is made yet.
        venue: { type: String, default: '' },
        // Page options: dteRates, perDiemRate, officialStationPlace.
        options: { type: Object, required: true },
        id: { type: String, default: 'destination_code' },
        error: { type: String, default: null },
    },

    emits: ['update:modelValue', 'pick'],

    data() {
        return {
            places: null,
            byCode: null,
            loadFailed: false,
            query: '',
            editing: false,
            open: false,
            active: 0,
        };
    },

    computed: {
        selected() {
            return (this.modelValue && this.byCode?.get(this.modelValue)) || null;
        },
        results() {
            return this.open && this.places ? searchPlaces(this.places, this.query) : [];
        },
        guess() {
            return !this.modelValue && this.places && this.venue ? guessPlace(this.places, this.venue) : null;
        },
        rate() {
            return perDiemRate(this.modelValue, this.options);
        },
        atOfficialStation() {
            return !!this.modelValue && this.modelValue === this.options.officialStationPlace;
        },
        listId() {
            return `${this.id}-places`;
        },
        shown() {
            return this.editing ? this.query : (this.selected?.label ?? '');
        },
    },

    mounted() {
        loadPlaces()
            .then(({ list, byCode }) => {
                this.places = markRaw(list);
                this.byCode = markRaw(byCode);
            })
            .catch(() => { this.loadFailed = true; });
    },

    methods: {
        peso,
        perDiemRate,

        onFocus(event) {
            this.editing = true;
            this.query = this.selected?.label ?? '';
            event.target.select();
        },

        onInput(event) {
            this.query = event.target.value;
            this.open = true;
            this.active = 0;
        },

        onBlur() {
            if (!this.query.trim() && this.modelValue) this.choose(null);
            this.editing = false;
            this.open = false;
        },

        onKeydown(event) {
            const count = this.results.length;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (!this.open) {
                    this.open = !!this.query.trim();
                    return;
                }
                if (count) this.active = (this.active + (event.key === 'ArrowDown' ? 1 : -1) + count) % count;
            } else if (event.key === 'Enter' && this.open) {
                // Never submit the form from here.
                event.preventDefault();
                if (count) this.choose(this.results[this.active]);
            } else if (event.key === 'Escape' && this.open) {
                event.preventDefault();
                this.open = false;
            }
        },

        choose(place) {
            const previous = this.selected;
            this.query = place?.label ?? '';
            this.open = false;
            if ((place?.code ?? '') === (this.modelValue ?? '')) return;
            this.$emit('update:modelValue', place?.code ?? '');
            this.$emit('pick', place, previous);
        },
    },
};
</script>

<template>
    <div>
        <div class="position-relative">
            <div class="input-group">
                <span class="input-group-text"><i class="ri-map-pin-2-line" aria-hidden="true"></i></span>
                <input :id="id" type="text" class="form-control" :class="{ 'is-invalid': error }" autocomplete="off"
                       role="combobox" aria-autocomplete="list" :aria-expanded="open && !!query.trim()" :aria-controls="listId"
                       :aria-activedescendant="open && results.length ? `${listId}-${active}` : null"
                       :value="shown" :placeholder="places ? 'Search a city, town or province' : 'Loading places…'"
                       @focus="onFocus" @input="onInput" @blur="onBlur" @keydown="onKeydown">
                <button v-if="modelValue" type="button" class="btn btn-outline-secondary" title="Clear the destination"
                        aria-label="Clear the destination" @click="choose(null)">
                    <i class="ri-close-line"></i>
                </button>
            </div>

            <!-- .show, not .d-block: d-block is !important and would beat v-show. -->
            <ul v-show="open && query.trim()" :id="listId" role="listbox" class="dropdown-menu show w-100 place-menu shadow-sm">
                <li v-for="(place, i) in results" :id="`${listId}-${i}`" :key="place.code" role="option" :aria-selected="i === active"
                    class="dropdown-item d-flex align-items-baseline gap-2" :class="{ active: i === active }"
                    @mousedown.prevent="choose(place)" @mousemove="active = i">
                    <span class="text-truncate">
                        <span class="fw-semibold">{{ place.name }}</span><span v-if="place.province">, {{ place.province }}</span>
                        <span v-if="place.type === 'province'" class="badge text-bg-light border ms-1">Province</span>
                        <span class="d-block small place-region">{{ place.regionName }}</span>
                    </span>
                    <span class="ms-auto small num text-nowrap">{{ peso(perDiemRate(place.code, options)) }}/day</span>
                </li>
                <li v-if="!results.length" class="dropdown-item-text small text-body-secondary">
                    {{ places ? `No place matches “${query.trim()}”.` : 'Loading places…' }}
                </li>
            </ul>
        </div>

        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
        <div v-if="loadFailed" class="form-text text-danger">Couldn't load the list of places — reload the page.</div>
        <template v-else-if="selected">
            <div class="form-text">
                {{ selected.regionName }} · <strong class="num">{{ peso(rate) }}</strong>/day per diem (EO 77)
            </div>
            <div v-if="atOfficialStation" class="form-text text-warning-emphasis">
                <i class="ri-error-warning-line"></i>
                Within 50 km of your official station: per diem only when staying there overnight instead of
                commuting daily, with the hotel bill. Fares are claimed as spent.
            </div>
        </template>
        <div v-else-if="guess" class="form-text">
            From the venue: <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="choose(guess)">{{ guess.label }}</button>?
        </div>
        <div v-else class="form-text">Sets the per diem (EO 77). Until picked: {{ peso(rate) }}/day.</div>
    </div>
</template>

<style scoped>
.place-menu {
    top: 100%;
    left: 0;
    max-height: 20rem;
    overflow-y: auto;
    z-index: 1060;
}
.place-menu .dropdown-item { cursor: pointer; white-space: normal; }
.place-region { color: var(--bs-secondary-color); }
.place-menu .dropdown-item.active .place-region { color: inherit; opacity: .85; }
</style>
