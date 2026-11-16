import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { INTL_LOCALES, createAppI18n, setAppLocale } from './i18n.js';
import { initTheme } from './theme.js';
import { installStaleGate } from './Security/staleGate.js';
import StaleGateHost from './Components/Security/StaleGateHost.vue';
import HelpEditorHost from './Help/HelpEditorHost.vue';
import { setHelp, setHelpAdmin } from './Help/helpStore.js';

initTheme();
// Every Inertia write that the sensitive-data gate refuses opens "Confirm it's you" in place and is replayed once.
installStaleGate(router);

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        const shared = props.initialPage.props;
        const i18n = createAppI18n(shared.locale, shared.messages);
        document.documentElement.lang = INTL_LOCALES[shared.locale] ?? INTL_LOCALES.en;
        // Contextual help texts of the active language (the "?" tips). Inertia remembers the once-prop between visits.
        setHelp(shared.help);
        setHelpAdmin(shared.auth?.user?.isAdmin);

        // Switching language posts to /locale and redirects back; apply the new messages without a reload.
        router.on('navigate', (event) => {
            const p = event.detail.page.props;
            setAppLocale(i18n, p.locale, p.messages);
            document.documentElement.lang = INTL_LOCALES[p.locale] ?? INTL_LOCALES.en;
            setHelp(p.help);
            setHelpAdmin(p.auth?.user?.isAdmin);
        });

        createApp({ render: () => [h(App, props), h(StaleGateHost), h(HelpEditorHost)] })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
});
