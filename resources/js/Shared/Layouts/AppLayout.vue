<script>
import { themeMixin } from '@/Shared/mixins/themeMixin.js';

export default {
    mixins: [themeMixin],

    data() {
        return {
            // Mobile drawer only; on desktop the rail is always open.
            sidebarOpen: false,
        };
    },

    computed: {
        user() {
            return this.$page.props.auth?.user ?? null;
        },
    },

    watch: {
        '$page.url'() {
            this.sidebarOpen = false;
        },
    },

    mounted() {
        window.addEventListener('keydown', this.onKeydown);
    },
    beforeUnmount() {
        window.removeEventListener('keydown', this.onKeydown);
    },

    methods: {
        isActive(prefix) {
            return this.$page.url === prefix
                || this.$page.url.startsWith(prefix + '?')
                || this.$page.url.startsWith(prefix + '/');
        },
        onKeydown(e) {
            if (e.key === 'Escape') this.sidebarOpen = false;
        },
    },
};
</script>

<template>
    <div>
        <!-- ─── Navigation rail ─────────────────────────────────────────── -->
        <aside class="sidebar" :class="{ 'sidebar-open': sidebarOpen }">
            <Link :href="route('dashboard')" class="sidebar-brand">
                <i class="ri-apps-2-line"></i>
                <span class="sidebar-brand-name">OneDOST</span>
            </Link>

            <div class="sidebar-label">Overview</div>
            <Link :href="route('dashboard')" class="sidebar-link"
                  :class="{ active: isActive('/dashboard') }">
                <i class="ri-home-4-line"></i><span>Dashboard</span>
            </Link>

            <div class="sidebar-label">Modules</div>
            <Link :href="route('dtr.index')" class="sidebar-link"
                  :class="{ active: isActive('/dtr') }">
                <i class="ri-time-line"></i><span>Daily Time Record</span>
            </Link>
            <Link :href="route('booking-requests.index')" class="sidebar-link"
                  :class="{ active: isActive('/booking-requests') }">
                <i class="ri-plane-line"></i><span>Booking Request</span>
            </Link>
            <Link :href="route('cash-advances.index')" class="sidebar-link"
                  :class="{ active: isActive('/cash-advances') }">
                <i class="ri-hand-coin-line"></i><span>Cash Advance</span>
            </Link>
            <Link :href="route('liquidations.index')" class="sidebar-link"
                  :class="{ active: isActive('/liquidations') }">
                <i class="ri-suitcase-line"></i><span>Travel Liquidation</span>
            </Link>
            <Link :href="route('reimbursements.index')" class="sidebar-link"
                  :class="{ active: isActive('/reimbursements') }">
                <i class="ri-refund-2-line"></i><span>Reimbursement</span>
            </Link>
            <Link :href="route('leave-applications.index')" class="sidebar-link"
                  :class="{ active: isActive('/leave-applications') }">
                <i class="ri-calendar-check-line"></i><span>Leave Application</span>
            </Link>

            <div class="sidebar-label">Account</div>
            <a :href="route('backup')" class="sidebar-link" title="Download everything saved here: the database and the uploaded photos">
                <i class="ri-download-cloud-2-line"></i><span>Download backup</span>
            </a>
            <Link :href="route('logout')" method="post" as="button" class="sidebar-link sidebar-button">
                <i class="ri-logout-box-r-line"></i><span>Log out</span>
            </Link>

            <div class="sidebar-footer">
                <div v-if="user" class="sidebar-user" :title="user.email">
                    <i class="ri-user-3-line"></i><span>{{ user.name }}</span>
                </div>
                <button class="btn btn-sm w-100" @click="cycleTheme"
                        :title="'Appearance: ' + themeLabel + ' — click to change'">
                    <i :class="themeIcon" class="me-1"></i><span>{{ themeLabel }}</span>
                </button>
            </div>
        </aside>

        <!-- Mobile backdrop -->
        <div class="sidebar-backdrop" :class="{ show: sidebarOpen }" @click="sidebarOpen = false"></div>

        <!-- ─── Content area ────────────────────────────────────────────── -->
        <div class="main-with-sidebar">
            <!-- Desktop top bar -->
            <div class="topbar-desktop">
                <div class="ms-auto d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-outline-secondary" @click="cycleTheme"
                            :title="'Appearance: ' + themeLabel">
                        <i :class="themeIcon"></i>
                        <span class="ms-1 d-none d-xl-inline">{{ themeLabel }}</span>
                    </button>
                    <Link :href="route('logout')" method="post" as="button" class="btn btn-sm btn-outline-secondary" title="Log out">
                        <i class="ri-logout-box-r-line"></i>
                        <span class="ms-1 d-none d-xl-inline">Log out</span>
                    </Link>
                </div>
            </div>

            <!-- Mobile top bar -->
            <div class="topbar-mobile">
                <button class="btn btn-sm icon-btn" @click="sidebarOpen = !sidebarOpen" aria-label="Open menu">
                    <i class="ri-menu-line fs-5"></i>
                </button>
                <span class="fw-bold small text-white">OneDOST</span>
                <button class="btn btn-sm icon-btn ms-auto" @click="cycleTheme" aria-label="Change appearance">
                    <i :class="themeIcon"></i>
                </button>
            </div>

            <main class="page-content"><slot /></main>

            <ToastContainer />
        </div>
    </div>
</template>
