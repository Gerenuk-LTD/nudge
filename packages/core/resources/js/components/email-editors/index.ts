import { HtmlEditor } from './html-editor';
import type { EmailEditor } from './types';

/**
 * The editors a campaign can use, by the key stored on the campaign. Add new editors here.
 */
export const emailEditors: Record<string, EmailEditor> = {
    html: { key: 'html', label: 'HTML', component: HtmlEditor },
};

export function editorFor(key: string): EmailEditor {
    return emailEditors[key] ?? emailEditors.html;
}

export type { EmailEditorProps, MergeTag } from './types';
