<script>
const OTHER = '__other__';

/**
 * A dropdown of fixed choices plus "Others (indicate)", which swaps in a text
 * box. A value that isn't one of the choices is shown as typed text.
 */
export default {
    props: {
        modelValue: { type: String, default: '' },
        options: { type: Array, required: true },
        id: { type: String, default: null },
        size: { type: String, default: '' },
        // A message shows under the field; `invalid` only colours it (the caller shows the message).
        error: { type: String, default: null },
        invalid: { type: Boolean, default: false },
        maxlength: { type: Number, default: 255 },
        ariaLabel: { type: String, default: null },
        placeholder: { type: String, default: 'Choose…' },
        // Optional fields: offer "None" so a choice can be taken back.
        clearable: { type: Boolean, default: false },
    },

    emits: ['update:modelValue'],

    data() {
        return { typing: this.isOther(this.modelValue), other: OTHER };
    },

    computed: {
        hasError() {
            return this.invalid || !!this.error;
        },
    },

    watch: {
        // Follow changes made elsewhere (starter itinerary, reordering, a reloaded record).
        modelValue(value) {
            if (this.options.includes(value)) {
                this.typing = false;
            } else if (value) {
                this.typing = true;
            }
        },
    },

    methods: {
        isOther(value) {
            return !!value && !this.options.includes(value);
        },

        choose(event) {
            if (event.target.value === OTHER) {
                this.typing = true;
                this.$emit('update:modelValue', '');
                this.$nextTick(() => this.$refs.input?.focus());
            } else {
                this.$emit('update:modelValue', event.target.value);
            }
        },

        backToList() {
            this.typing = false;
            this.$emit('update:modelValue', '');
        },
    },
};
</script>

<template>
    <div>
        <select v-if="!typing" :id="id" class="form-select" :class="[size && `form-select-${size}`, { 'is-invalid': hasError }]"
                :value="modelValue || ''" :aria-label="ariaLabel" @change="choose">
            <option value="" :disabled="!clearable">{{ clearable ? 'None' : placeholder }}</option>
            <option v-for="option in options" :key="option" :value="option">{{ option }}</option>
            <option :value="other">Others (indicate)</option>
        </select>
        <div v-else class="input-group flex-nowrap" :class="size && `input-group-${size}`">
            <input :id="id" ref="input" type="text" class="form-control" :class="{ 'is-invalid': hasError }" :value="modelValue"
                   :maxlength="maxlength" placeholder="Indicate…" :aria-label="ariaLabel"
                   @input="$emit('update:modelValue', $event.target.value)">
            <button type="button" class="btn btn-outline-secondary" title="Pick from the list instead" aria-label="Pick from the list instead"
                    @click="backToList">
                <i class="ri-list-unordered"></i>
            </button>
        </div>
        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
    </div>
</template>
