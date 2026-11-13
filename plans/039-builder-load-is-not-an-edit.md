# Plan 039: Opening a template in the builder no longer counts as an unsaved change

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 4a7e572..HEAD -- packages/core/resources/js/components/email-editors packages/core/resources/js/pages/marketing/templates/form.tsx`
> Expected: no output. If a file changed, check that the excerpts below still match, and STOP if one doesn't.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW. Only the template page treats the builder's first write differently. The campaign editor is deliberately left alone (see Scope).
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `4a7e572`, 2026-11-13

## Why this matters

The maintainer opens a template, changes nothing, clicks away, and gets "You have unsaved changes. Leave without saving?" every time.

The cause: when the drag-and-drop builder mounts, it renders the email from its blocks and, if that HTML differs from the saved HTML (or there is no saved block state), it calls `onChange` with the new rendering. That's intentional, so emails made from a starter have HTML before the first edit. But the template page treats that call like an edit. `form.setData` makes `form.isDirty` true, and `useUnsavedChangesGuard(form.isDirty && …)` then asks before every navigation.

It happens for every new template, and for every existing template saved before the builder's output last changed (commit `68e3b4d` changed the renderer). Those re-render differently on each open.

The fix: the builder tells its caller that this one write is the initial sync, not an edit. The template page applies it **and** makes it the form's baseline (`form.setDefaults`), so only real edits make the form dirty.

## Current state

`packages/core/resources/js/components/email-editors/types.ts`:

```ts
export type EmailEditorProps = {
    html: string;
    state: unknown;
    mergeTags: MergeTag[];
    onChange: (html: string, state: unknown) => void;
    /**
     * Whether the editor may write its own rendering of the content back as soon as it opens. Off when only showing
     * an existing email in a different editor, so looking at it never changes it.
     */
    syncOnMount?: boolean;
};
```

`packages/core/resources/js/components/email-editors/blocks/block-editor.tsx` (around line 169):

```tsx
// Start from HTML that matches the document, so emails made from a starter have content before the first edit.
useEffect(() => {
    const rendered = renderEmail(latest.current);

    if (syncOnMount && (rendered !== html || asDocument(state) === null)) {
        latestOnChange.current(rendered, latest.current);
    }
    // Only on first load; later changes go through commit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
}, []);
```

All other `onChange` calls in the builder go through `commit` and are real edits.

`packages/core/resources/js/components/email-editors/content-editor.tsx`. The props, and the place it passes the editor's change up:

```tsx
    mergeTags: MergeTag[];
    onChange: (content: EmailContent) => void;
}) {
```

```tsx
                onChange={(html, state) =>
                    onChange({ editor: editorKey, state, html })
                }
```

(`switchTo` in the same file also calls `onChange`. That's a user action, so leave it as a real change.)

`packages/core/resources/js/pages/marketing/templates/form.tsx`:

```tsx
useSaveShortcut(save);
useUnsavedChangesGuard(form.isDirty && !form.processing);
```

```tsx
                        onChange={(content) =>
                            form.setData((data) => ({
                                ...data,
                                editor: content.editor,
                                editor_state:
                                    content.state === null
                                        ? ''
                                        : JSON.stringify(content.state),
                                html: content.html,
                            }))
                        }
```

Inertia 3's `form.setDefaults(fields)` merges `fields` into the defaults (`Object.assign(cloneDeep(defaults), fields)`), and `isDirty` is `!isEqual(data, defaults)`. So setting the same three fields in both data and defaults leaves the form clean.

## Conventions

- Prettier style through `npm run check:fix`. Never run bare `npx prettier`.
- Comments explain _why_ in one line, like the existing ones in `block-editor.tsx`.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build`. All must exit 0.

## Scope

**In scope:** `email-editors/types.ts`, `email-editors/blocks/block-editor.tsx` (the mount effect only), `email-editors/content-editor.tsx` (the props type and the pass-through only), `pages/marketing/templates/form.tsx` (the `onChange` handler only).

**Out of scope, do not touch:**

- `pages/marketing/campaigns/edit.tsx`. The campaign editor autosaves, and it **relies** on the initial sync being dirty. That's how a campaign made from a starter gets its HTML saved. Making it the baseline there could send blank emails. Its existing `onChange` keeps working unchanged, because the new argument is optional.
- `html-editor.tsx`. It never syncs on mount.
- The mount effect's condition (`rendered !== html || …`). Keep when it fires exactly as is.

## Git workflow

Branch `advisor/039-builder-load-not-an-edit` from `dev`. One conventional commit, e.g. `fix(core): opening a template no longer counts as an unsaved change`. Do not push.

## Steps

### Step 1: Mark the initial sync

In `types.ts`, change `onChange` to:

```ts
    /** `initial` is true for the editor's own write as it opens, which is not an edit by the person. */
    onChange: (html: string, state: unknown, initial?: boolean) => void;
```

In `block-editor.tsx`'s mount effect, change the call to `latestOnChange.current(rendered, latest.current, true);`. Change nothing else in that effect.

In `content-editor.tsx`:

- Change the prop type to `onChange: (content: EmailContent, initial?: boolean) => void;`.
- Change the pass-through to `onChange={(html, state, initial) => onChange({ editor: editorKey, state, html }, initial)}`.
- Leave `switchTo` as it is.

**Verify**: `npm run types:check` exits 0. `grep -n "latest.current, true" packages/core/resources/js/components/email-editors/blocks/block-editor.tsx` returns one match.

### Step 2: The template page takes the initial sync as its baseline

In `templates/form.tsx`, replace the `onChange` handler with:

```tsx
                        onChange={(content, initial) => {
                            const fields = {
                                editor: content.editor,
                                editor_state:
                                    content.state === null
                                        ? ''
                                        : JSON.stringify(content.state),
                                html: content.html,
                            };

                            form.setData((data) => ({ ...data, ...fields }));

                            // The builder's own rendering as it opens is the starting point, not an edit, so leaving asks nothing.
                            if (initial) {
                                form.setDefaults(fields);
                            }
                        }}
```

**Verify**: `npm run check:fix`, then `npm run check && npm run types:check && npm run test && npm run build`. All exit 0.

## Test plan

There are no React component tests in this repo (Vitest covers only `blocks/*.ts` logic), so there's no unit test. Manual, if a browser is available:

1. Open an existing template, change nothing, then click Templates in the sidebar. You leave with no prompt.
2. Open it again, type a letter in the name, then click away. The prompt appears.
3. Open New template, change nothing, then click away. No prompt.
4. Open a campaign made from a starter. Its content still autosaves as before, and the editor bar shows saving and saved.

Say in the report if this wasn't possible.

## Done criteria

- All four commands exit 0.
- `git diff --stat dev` lists only the four in-scope files.
- `git diff dev -- packages/core/resources/js/pages/marketing/campaigns` is empty.

## STOP conditions

- An excerpt doesn't match.
- `form.setDefaults` doesn't exist on the template page's form, or types reject it.
- Any other caller of `EmailContentEditor` or of `EmailEditorProps['onChange']` breaks type-checking. It shouldn't, because the argument is optional.

## Maintenance notes

- The campaign editor deliberately still counts the initial sync as a change, so autosave persists starter content. If that page ever stops autosaving, it needs the same baseline treatment.
- A template whose saved HTML is stale keeps that HTML until someone saves it. That's the same as today. Making a campaign from it re-renders anyway.
