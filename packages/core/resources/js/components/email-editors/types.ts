import type { ComponentType } from 'react';

export type MergeTag = { tag: string; label: string };

/**
 * Every email editor takes and produces HTML. An editor with its own document model (such as a
 * drag-and-drop builder) also keeps that model in `state`, which is stored alongside the HTML.
 */
export type EmailEditorProps = {
    html: string;
    state: unknown;
    mergeTags: MergeTag[];
    onChange: (html: string, state: unknown) => void;
};

export type EmailEditor = {
    key: string;
    label: string;
    component: ComponentType<EmailEditorProps>;
};
