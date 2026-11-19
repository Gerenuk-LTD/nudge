import type { AutomationStep, StepType } from '@nudge/types';

/**
 * A step setting as display text. Settings are free-form JSON, so anything that is not a string or number shows as empty.
 */
export function text(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

export const stepLabels: Record<StepType, string> = {
    send_email: 'Send an email',
    wait: 'Wait',
    condition: 'Check a condition',
    update_contact: 'Update the contact',
    webhook: 'Call a webhook',
    exit: 'Exit',
};

export function newStep(type: StepType): AutomationStep {
    const id = `s${Math.random().toString(36).slice(2, 10)}`;

    switch (type) {
        case 'wait':
            return { id, type, config: { amount: 1, unit: 'days' } };
        case 'condition':
            return {
                id,
                type,
                config: { definition: { match: 'all', conditions: [] } },
                yes: [],
                no: [],
            };
        case 'send_email':
            return {
                id,
                type,
                config: {
                    subject: '',
                    editor: 'blocks',
                    html: '',
                    track_clicks: true,
                    track_opens: true,
                },
            };
        case 'update_contact':
            return {
                id,
                type,
                config: {
                    add_tags: [],
                    remove_tags: [],
                    add_list_ids: [],
                    remove_list_ids: [],
                    fields: {},
                    unsubscribe: false,
                },
            };
        default:
            return { id, type, config: {} };
    }
}

/**
 * Apply a change to one step, wherever it sits in the tree.
 */
export function updateStep(
    steps: AutomationStep[],
    id: string,
    change: (step: AutomationStep) => AutomationStep,
): AutomationStep[] {
    return steps.map((step) => {
        if (step.id === id) {
            return change(step);
        }

        if (step.type === 'condition') {
            return {
                ...step,
                yes: updateStep(step.yes ?? [], id, change),
                no: updateStep(step.no ?? [], id, change),
            };
        }

        return step;
    });
}

export function findStep(
    steps: AutomationStep[],
    id: string | null,
): AutomationStep | null {
    for (const step of steps) {
        if (step.id === id) {
            return step;
        }

        const nested =
            step.type === 'condition'
                ? (findStep(step.yes ?? [], id) ?? findStep(step.no ?? [], id))
                : null;

        if (nested) {
            return nested;
        }
    }

    return null;
}

/**
 * A one-line description of a step for its card.
 */
export function summarize(step: AutomationStep): string {
    const config = step.config;

    switch (step.type) {
        case 'send_email':
            return text(config.subject) || 'No subject yet';
        case 'wait':
            return `${text(config.amount)} ${text(config.unit)}`;
        case 'condition':
            return `${((config.definition as { conditions?: unknown[] })?.conditions ?? []).length} rule(s)`;
        case 'update_contact': {
            const parts = [
                ...((config.add_tags as string[]) ?? []).map(
                    (tag) => `+${tag}`,
                ),
                ...((config.remove_tags as string[]) ?? []).map(
                    (tag) => `−${tag}`,
                ),
            ];

            if (config.unsubscribe) {
                parts.push('unsubscribe');
            }

            return parts.join(', ') || 'No changes yet';
        }
        case 'webhook':
            return text(config.url) || 'No URL yet';
        default:
            return 'The contact leaves the automation';
    }
}

/**
 * Where a new step goes: a position in the top-level steps (no parent), or in one branch of a condition.
 */
export type InsertPoint = {
    parentId: string | null;
    branch: 'yes' | 'no' | null;
    index: number;
};

/**
 * Put a step at an insert point, wherever it sits in the tree.
 */
export function insertStep(
    steps: AutomationStep[],
    at: InsertPoint,
    step: AutomationStep,
): AutomationStep[] {
    if (at.parentId === null) {
        return [...steps.slice(0, at.index), step, ...steps.slice(at.index)];
    }

    return steps.map((candidate) => {
        if (candidate.type !== 'condition') {
            return candidate;
        }

        if (candidate.id === at.parentId && at.branch) {
            return {
                ...candidate,
                [at.branch]: insertStep(
                    candidate[at.branch] ?? [],
                    { ...at, parentId: null },
                    step,
                ),
            };
        }

        return {
            ...candidate,
            yes: insertStep(candidate.yes ?? [], at, step),
            no: insertStep(candidate.no ?? [], at, step),
        };
    });
}

/**
 * Take a step out of the tree, with its branches if it is a condition.
 */
export function removeStep(
    steps: AutomationStep[],
    id: string,
): AutomationStep[] {
    return steps
        .filter((step) => step.id !== id)
        .map((step) =>
            step.type === 'condition'
                ? {
                      ...step,
                      yes: removeStep(step.yes ?? [], id),
                      no: removeStep(step.no ?? [], id),
                  }
                : step,
        );
}
