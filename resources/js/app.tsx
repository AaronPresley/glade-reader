import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import Layout from './Layout';

type PageModule = {
    default: ResolvedComponent;
};

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob<PageModule>('./pages/**/*.tsx', { eager: true });
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        page.default.layout = page.default.layout || ((page: ReactNode) => <Layout>{page}</Layout>);

        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
