import type { Auth } from '@/types/auth';
import type { WorkspaceSharedProps } from '@marketing/types';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            canRegister: boolean;
            sidebarOpen: boolean;
            workspace?: WorkspaceSharedProps;
            [key: string]: unknown;
        };
    }
}
