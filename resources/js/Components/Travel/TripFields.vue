<script>
import SelectOrOther from '@/Components/ui/SelectOrOther.vue';
import DestinationPicker from '@/Components/Travel/DestinationPicker.vue';
import { peso } from '@/Shared/format.js';
import { perDiemRate, rescalePerDiem } from './places.js';

/** Travel order, dates, destination, venue, fund cluster and purpose — shared by both travel forms. */
export default {
    components: { DestinationPicker, SelectOrOther },

    props: {
        // The page's useForm().
        form: { type: Object, required: true },
        options: { type: Object, required: true },
    },

    methods: {
        // A new destination: the venue follows it unless it was written by hand,
        // and per diem still at the old rate (or that less lunch) moves to the new one.
        onPick(place, previous) {
            const venue = (this.form.venue || '').trim();
            if (place && (!venue || venue === previous?.venue)) {
                this.form.venue = place.venue;
            }

            const rate = perDiemRate(place?.code, this.options);
            const lunch = this.form.lunch_provided ? Number(this.options.lunchDeduction) : 0;
            if (rescalePerDiem(this.form.items, perDiemRate(previous?.code, this.options), rate, lunch)) {
                this.$toast.add(place
                    ? `Per diem changed to ${peso(rate)}/day, the EO 77 rate for ${place.regionName}.`
                    : `Per diem changed back to the default ${peso(rate)}/day.`, 'info');
            }
        },
    },
};
</script>

<template>
    <div class="row g-3">
        <div class="col-md-3">
            <label for="travel_order_no" class="form-label">Travel order no.</label>
            <input id="travel_order_no" v-model="form.travel_order_no" type="text" class="form-control" placeholder="2026-5698"
                   :class="{ 'is-invalid': form.errors.travel_order_no }">
            <div class="invalid-feedback">{{ form.errors.travel_order_no }}</div>
        </div>
        <div class="col-md-3">
            <label for="travel_order_date" class="form-label">Travel order date</label>
            <input id="travel_order_date" v-model="form.travel_order_date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.travel_order_date }">
            <div class="invalid-feedback">{{ form.errors.travel_order_date }}</div>
        </div>
        <div class="col-md-3">
            <label for="departure_date" class="form-label">Departure</label>
            <input id="departure_date" v-model="form.departure_date" type="date" class="form-control" :class="{ 'is-invalid': form.errors.departure_date }">
            <div class="invalid-feedback">{{ form.errors.departure_date }}</div>
        </div>
        <div class="col-md-3">
            <label for="return_date" class="form-label">Return</label>
            <input id="return_date" v-model="form.return_date" type="date" class="form-control" :min="form.departure_date"
                   :class="{ 'is-invalid': form.errors.return_date }">
            <div class="invalid-feedback">{{ form.errors.return_date }}</div>
        </div>
        <div class="col-md-6">
            <label for="destination_code" class="form-label">Destination</label>
            <DestinationPicker id="destination_code" v-model="form.destination_code" :venue="form.venue" :options="options"
                               :error="form.errors.destination_code" @pick="onPick" />
        </div>
        <div class="col-md-6">
            <label for="venue" class="form-label">Venue</label>
            <input id="venue" v-model="form.venue" type="text" class="form-control" placeholder="Pagadian City, Zamboanga del Sur"
                   :class="{ 'is-invalid': form.errors.venue }">
            <div class="invalid-feedback">{{ form.errors.venue }}</div>
            <div class="form-text">As printed on the forms — add the hall, office or hotel if you like.</div>
        </div>
        <div class="col-md-8">
            <label for="purpose" class="form-label">Purpose of travel</label>
            <textarea id="purpose" v-model="form.purpose" rows="2" class="form-control" :class="{ 'is-invalid': form.errors.purpose }"
                      placeholder="For on-site deployment of Regional Office personnel to Pagadian City on September 20-24, 2026."></textarea>
            <div class="invalid-feedback">{{ form.errors.purpose }}</div>
            <div class="form-text">Printed on every form, word for word.</div>
        </div>
        <div class="col-md-4">
            <label for="fund_cluster" class="form-label">Fund cluster</label>
            <SelectOrOther id="fund_cluster" v-model="form.fund_cluster" :options="options.fundClusters" :maxlength="60"
                           :error="form.errors.fund_cluster" />
        </div>
    </div>
</template>
