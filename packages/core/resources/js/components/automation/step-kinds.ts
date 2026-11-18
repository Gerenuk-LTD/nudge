import type { LucideIcon } from 'lucide-react';
import {
    Clock,
    DoorOpen,
    GitBranch,
    Mail,
    UserPen,
    Webhook,
    Zap,
} from 'lucide-react';
import type { StepType } from '@marketing/types';

/**
 * Each kind of automation node's icon and tinted tile colours, shared by the canvas and the automation cards.
 */
export const stepKinds: Record<
    StepType | 'trigger',
    { icon: LucideIcon; tone: string }
> = {
    trigger: {
        icon: Zap,
        tone: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    send_email: {
        icon: Mail,
        tone: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
    },
    wait: {
        icon: Clock,
        tone: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    condition: {
        icon: GitBranch,
        tone: 'bg-violet-500/10 text-violet-700 dark:text-violet-400',
    },
    update_contact: {
        icon: UserPen,
        tone: 'bg-slate-500/10 text-slate-700 dark:text-slate-300',
    },
    webhook: {
        icon: Webhook,
        tone: 'bg-orange-500/10 text-orange-700 dark:text-orange-400',
    },
    exit: {
        icon: DoorOpen,
        tone: 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
    },
};
