<script>
import { router } from '@inertiajs/vue3';
import { shrinkPhoto } from '@/Shared/images.js';

const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 15 * 1024 * 1024;

export default {
    props: {
        // null until the liquidation has been saved once.
        liquidationId: { type: Number, default: null },
        photos: { type: Array, default: () => [] },
        // Files picked before the first save; they upload with it (v-model:pending).
        pending: { type: Array, default: () => [] },
        max: { type: Number, required: true },
        // 'liquidations' or 'reimbursements' — whose photo routes to use.
        routePrefix: { type: String, default: 'liquidations' },
    },

    emits: ['update:pending'],

    data() {
        return { uploading: false, removing: null, notice: null };
    },

    computed: {
        saved() {
            return this.liquidationId !== null;
        },
        tiles() {
            return this.saved
                ? this.photos.map(p => ({ key: `saved-${p.id}`, url: p.url, name: p.name, photo: p }))
                : this.pending.map((file, index) => ({ key: `pending-${index}-${file.name}`, url: this.previewOf(file), name: file.name, index }));
        },
        room() {
            return this.max - this.tiles.length;
        },
        errors() {
            const errors = this.$page.props.errors ?? {};
            return Object.entries(errors).filter(([key]) => key.startsWith('photos')).map(([, message]) => message);
        },
    },

    watch: {
        // Free previews for files that are no longer pending (removed, or saved).
        pending(files) {
            for (const [file, url] of this.previews) {
                if (!files.includes(file)) {
                    URL.revokeObjectURL(url);
                    this.previews.delete(file);
                }
            }
        },
    },

    created() {
        // Not reactive on purpose: object URLs are only read while rendering.
        this.previews = new Map();
    },

    beforeUnmount() {
        this.previews.forEach(url => URL.revokeObjectURL(url));
    },

    methods: {
        previewOf(file) {
            if (!this.previews.has(file)) this.previews.set(file, URL.createObjectURL(file));
            return this.previews.get(file);
        },

        pick() {
            this.$refs.input.click();
        },

        async chosen(event) {
            const picked = Array.from(event.target.files || []);
            event.target.value = '';
            this.notice = null;

            const images = picked.filter(f => TYPES.includes(f.type));
            const room = images.slice(0, Math.max(this.room, 0));
            const overflow = images.length - room.length;

            // Scaled down here first: smaller uploads, and nothing too big for the server to open.
            this.uploading = true;
            const shrunk = await Promise.all(room.map(shrinkPhoto));
            this.uploading = false;
            const files = shrunk.filter(f => f.size <= MAX_BYTES);
            const skipped = picked.length - images.length + shrunk.length - files.length;

            const notes = [];
            if (skipped) notes.push(`${skipped} file${skipped === 1 ? ' was' : 's were'} skipped — photos must be JPG, PNG or WebP under 15 MB.`);
            if (overflow) notes.push(`Only ${this.max} photos fit; ${overflow} left out.`);
            this.notice = notes.join(' ') || null;

            if (!files.length) return;
            this.saved ? this.upload(files) : this.$emit('update:pending', [...this.pending, ...files]);
        },

        // preserveState keeps the page's unsaved form while photos change.
        upload(files) {
            router.post(route(`${this.routePrefix}.photos.store`, this.liquidationId), { photos: files }, {
                forceFormData: true,
                preserveState: true,
                preserveScroll: true,
                onStart: () => { this.uploading = true; },
                onFinish: () => { this.uploading = false; },
            });
        },

        remove(tile) {
            if (!this.saved) {
                this.$emit('update:pending', this.pending.filter((_, i) => i !== tile.index));
                return;
            }
            router.delete(route(`${this.routePrefix}.photos.destroy`, [this.liquidationId, tile.photo.id]), {
                preserveState: true,
                preserveScroll: true,
                onStart: () => { this.removing = tile.photo.id; },
                onFinish: () => { this.removing = null; },
            });
        },
    },
};
</script>

<template>
    <div>
        <div class="d-flex flex-wrap gap-3 mb-2">
            <figure v-for="tile in tiles" :key="tile.key" class="photo mb-0">
                <img :src="tile.url" :alt="tile.name" loading="lazy">
                <span v-if="!saved" class="photo-badge">Not saved yet</span>
                <button type="button" class="btn btn-sm btn-light photo-remove" :disabled="saved && removing === tile.photo.id"
                        :aria-label="`Remove ${tile.name}`" @click="remove(tile)">
                    <i class="ri-delete-bin-line"></i>
                </button>
            </figure>
            <button v-if="room > 0" type="button" class="photo photo-add btn btn-outline-secondary" :disabled="uploading" @click="pick">
                <span v-if="uploading" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <i v-else class="ri-image-add-line fs-4"></i>
                <span class="small d-block">{{ uploading ? 'Uploading…' : 'Add photos' }}</span>
            </button>
        </div>

        <div v-if="notice" class="small text-warning-emphasis">{{ notice }}</div>
        <div v-for="message in errors" :key="message" class="small text-danger is-invalid">{{ message }}</div>
        <p class="small text-body-secondary mb-0">
            <template v-if="!saved && pending.length">These upload when you save the liquidation. </template>
            Up to {{ max }} photos; they print three to a row under the narrative.
        </p>
        <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="chosen">
    </div>
</template>

<style scoped>
.photo {
    position: relative;
    width: 9rem;
    height: 6.5rem;
    border: 1px solid var(--hairline);
    border-radius: var(--r-md);
    overflow: hidden;
}
.photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.photo-remove { position: absolute; top: .25rem; right: .25rem; padding: .1rem .35rem; }
.photo-badge {
    position: absolute;
    left: .25rem;
    bottom: .25rem;
    padding: .05rem .4rem;
    font-size: .6875rem;
    border-radius: var(--r-sm);
    background: rgba(0, 0, 0, .6);
    color: #fff;
}
.photo-add { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem; }
</style>
