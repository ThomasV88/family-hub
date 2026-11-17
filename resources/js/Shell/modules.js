import { House, ListTodo, Gift, Wallet, HeartPulse, BookOpen, ShoppingCart } from '@lucide/vue';

// visibleModules() filters this list by the shared auth.access levels; hiding here is cosmetic and never the access control (the server enforces every route).
// href: null means the module is not built yet, so choosing it opens the coming-soon panel.
export const MODULES = [
    { key: 'overview', labelKey: 'nav.overview', titleKey: 'nav.overview', icon: House, href: '/' },
    { key: 'tasks', labelKey: 'nav.tasks', titleKey: 'module.tasks', icon: ListTodo, href: '/todo' },
    { key: 'wishlist', labelKey: 'nav.wishlist', titleKey: 'module.wishlist', icon: Gift, href: '/wishlist' },
    { key: 'finances', labelKey: 'nav.finances', titleKey: 'module.finances', icon: Wallet, href: '/finance' },
    { key: 'health', labelKey: 'nav.health', titleKey: 'module.health', icon: HeartPulse, href: '/health' },
    { key: 'shopping', labelKey: 'nav.shopping', titleKey: 'module.shopping', icon: ShoppingCart, href: '/shopping' },
];

// The user manual is not a module: every signed-in member has it, so it is not part of MODULES and needs no grant.
export const MANUAL_NAV = { key: 'manual', labelKey: 'nav.manual', titleKey: 'nav.manual', icon: BookOpen, href: '/manual' };

// Frontend module key -> server module key (app/Access/Module.php).
export const ACCESS_KEY = { tasks: 'todo', wishlist: 'wishlist', finances: 'finance', health: 'health', shopping: 'shopping' };

// Overview is always visible; a module needs any level other than 'none'. Missing access hides everything else.
export function visibleModules(access) {
    return MODULES.filter((m) => {
        const key = ACCESS_KEY[m.key];
        if (!key) return true;
        const level = access?.[key];
        return level === 'view' || level === 'edit';
    });
}
