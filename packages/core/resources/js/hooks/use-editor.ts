import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * Saves on ⌘S or Ctrl+S instead of the browser saving the page, while `enabled`.
 */
export function useSaveShortcut(save: () => void, enabled = true) {
    const latest = useRef(save);
    latest.current = save;

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 's') {
                event.preventDefault();
                latest.current();
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [enabled]);
}

/**
 * Asks before leaving the page while there are unsaved changes, whether by a link in the app or by closing the tab.
 * Saves and partial reloads on the same page are let through.
 */
export function useUnsavedChangesGuard(dirty: boolean) {
    useEffect(() => {
        if (!dirty) {
            return;
        }

        const message = 'You have unsaved changes. Leave without saving?';

        const removeInertiaListener = router.on('before', (event) => {
            const visit = event.detail.visit;
            const leaving =
                visit.method === 'get' &&
                visit.only.length === 0 &&
                visit.url.pathname !== window.location.pathname;

            if (leaving && !window.confirm(message)) {
                event.preventDefault();
            }
        });

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', onBeforeUnload);

        return () => {
            removeInertiaListener();
            window.removeEventListener('beforeunload', onBeforeUnload);
        };
    }, [dirty]);
}
