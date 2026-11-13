import { House, ListTodo, Gift, Wallet, HeartPulse } from '@lucide/vue';

// visibleModules() filters this list by the shared auth.access levels; hiding here is cosmetic and never the access control (the server enforces every route).
// href: null means the module is not built yet, so choosing it opens the coming-soon panel.
export const MODULES = [
    { key: 'overview', labelKey: 'nav.overview', titleKey: 'nav.overview', icon: House, href: '/' },
    { key: 'tasks', labelKey: 'nav.tasks', titleKey: 'module.tasks', icon: ListTodo, href: null },
    { key: 'wishlist', labelKey: 'nav.wishlist', titleKey: 'module.wishlist', icon: Gift, href: null },
    { key: 'finances', labelKey: 'nav.finances', titleKey: 'module.finances', icon: Wallet, href: null },
    { key: 'health', labelKey: 'nav.health', titleKey: 'module.health', icon: HeartPulse, href: null },
];

// Frontend module key -> server module key (app/Access/Module.php).
export const ACCESS_KEY = { tasks: 'todo', wishlist: 'wishlist', finances: 'finance', health: 'health' };

// Overview is always visible; a module needs any level other than 'none'. Missing access hides everything else.
export function visibleModules(access) {
    return MODULES.filter((m) => {
        const key = ACCESS_KEY[m.key];
        if (!key) return true;
        const level = access?.[key];
        return level === 'view' || level === 'edit';
    });
}
