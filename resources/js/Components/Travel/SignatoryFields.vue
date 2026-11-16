<script>
/** Signatory names and titles (form.signatories), one column per role. */
export default {
    props: {
        // The page's useForm().
        form: { type: Object, required: true },
        // [{ key: 'supervisor', label, hint }] — key matches `${key}_name` / `${key}_title`.
        roles: { type: Array, required: true },
    },
};
</script>

<template>
    <div class="row g-3">
        <div v-for="role in roles" :key="role.key" class="col-md-4">
            <div class="small fw-semibold">{{ role.label }}</div>
            <div class="small text-body-secondary mb-2">{{ role.hint }}</div>
            <input v-model="form.signatories[`${role.key}_name`]" type="text" class="form-control form-control-sm mb-1"
                   :class="{ 'is-invalid': form.errors[`signatories.${role.key}_name`] }" :aria-label="`${role.label} name`">
            <input v-model="form.signatories[`${role.key}_title`]" type="text" class="form-control form-control-sm"
                   :class="{ 'is-invalid': form.errors[`signatories.${role.key}_title`] }" :aria-label="`${role.label} title`">
        </div>
    </div>
</template>
