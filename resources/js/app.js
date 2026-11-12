import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { createAppI18n, setAppLocale } from './i18n.js';

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
    },
    setup({ el, App, props, plugin }) {
        const shared = props.initialPage.props;
        const i18n = createAppI18n(shared.locale, shared.messages);
        document.documentElement.lang = shared.locale;

        // Switching language posts to /locale and redirects back; apply the new messages without a reload.
        router.on('navigate', (event) => {
            const p = event.detail.page.props;
            setAppLocale(i18n, p.locale, p.messages);
        });

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18n)
            .mount(el);
    },
});
