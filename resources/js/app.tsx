import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';

const appName = import.meta.env.VITE_APP_NAME || 'RazzanStore';

void createInertiaApp({
    title: (title) => title ? `${title} · ${appName}` : appName,
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
        const page = pages[`./pages/${name}.tsx`];
        if (!page) {
            throw new Error(`Page component not found: ${name}`);
        }
        return page;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#F59E0B' },
});
