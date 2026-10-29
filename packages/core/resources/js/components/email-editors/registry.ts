import { BlockEditor } from './blocks/block-editor';
import { HtmlEditor } from './html-editor';
import type { EmailEditor } from './types';

/**
 * The editors an email can use, by the key stored with it. Add new editors here.
 */
export const emailEditors: Record<string, EmailEditor> = {
    blocks: { key: 'blocks', label: 'Drag and drop', component: BlockEditor },
    html: { key: 'html', label: 'HTML', component: HtmlEditor },
};

export function editorFor(key: string): EmailEditor {
    return emailEditors[key] ?? emailEditors.html;
}
