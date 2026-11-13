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
    /** `initial` is true for the editor's own write as it opens, which is not an edit by the person. */
    onChange: (html: string, state: unknown, initial?: boolean) => void;
    /**
     * Whether the editor may write its own rendering of the content back as soon as it opens. Off when only showing
     * an existing email in a different editor, so looking at it never changes it.
     */
    syncOnMount?: boolean;
};

export type EmailEditor = {
    key: string;
    label: string;
    component: ComponentType<EmailEditorProps>;
};
