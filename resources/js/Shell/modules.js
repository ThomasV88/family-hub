import { House, ListTodo, Gift, Wallet, HeartPulse } from '@lucide/vue';

// Phase 2 filters this list by server-side permission; hiding here is never the access control.
// href: null means the module is not built yet, so choosing it opens the coming-soon panel.
export const MODULES = [
    { key: 'overview', labelKey: 'nav.overview', titleKey: 'nav.overview', icon: House, href: '/' },
    { key: 'tasks', labelKey: 'nav.tasks', titleKey: 'module.tasks', icon: ListTodo, href: null },
    { key: 'wishlist', labelKey: 'nav.wishlist', titleKey: 'module.wishlist', icon: Gift, href: null },
    { key: 'finances', labelKey: 'nav.finances', titleKey: 'module.finances', icon: Wallet, href: null },
    { key: 'health', labelKey: 'nav.health', titleKey: 'module.health', icon: HeartPulse, href: null },
];
