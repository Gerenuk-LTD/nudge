# Plan 045: Opening a campaign no longer re-saves it or withdraws its approval

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a87a701..HEAD -- packages/core/resources/js/pages/marketing/campaigns/edit.tsx`
> Expected: no output. If plan 044 has landed first, it doesn't touch this file. STOP if an excerpt below doesn't match.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: MED-LOW. It changes when the campaign editor autosaves on open. The rule below keeps the one case that relies on it, starter content.
- **Depends on**: 039 (done; adds the `initial` flag)
- **Category**: bug
- **Planned at**: commit `a87a701`, 2026-11-13

## Why this matters

When the drag-and-drop builder opens, it renders the email from its blocks. If that rendering differs from the saved HTML, it writes it back through `onChange(content, initial = true)` (plan 039 added the flag).

The template page now treats that write as its starting point. The campaign editor still treats it as an edit, which causes two problems:

1. **Opening a campaign autosaves it.** `useAutosave` sees a dirty form and submits it about 1.2 seconds later.
2. **Opening an approved campaign withdraws its approval.** `CampaignController::update` clears `approved_at` and `approved_by` when any field in `CONTENT_FIELDS` changes, and `html` is one of them. The builder's output changed in plan 010, so any campaign saved before that renders differently today. Someone who only opens an approved campaign to look at it silently un-approves it.

The one case that **needs** the write is a campaign whose saved HTML is empty: a fresh draft, or one made from a starter that has blocks but no HTML yet. Autosave must persist that HTML, or the campaign would go out blank.

So: treat the initial write as the starting point when the field already had saved HTML, and as an edit when it was empty. Apply the same rule to the A/B version B editor and its `ab_html_b`.

## Current state

`packages/core/resources/js/pages/marketing/campaigns/edit.tsx`:

The main editor in the Content step (around line 604):

```tsx
                <EmailContentEditor
                    key={revision}
                    content={{ editor: form.data.editor, state: …, html: form.data.html }}
                    mergeTags={props.mergeTags}
                    previewValues={previewValues}
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
                    actions={…}
                />
```

`key={revision}` remounts the editor when a template is chosen. That content is new, and **must** be saved. See Step 1.

The version B editor (in the A/B panel, around line 1030):

```tsx
                        onChange={(content) =>
                            form.setData((data) => ({
                                ...data,
                                ab_html_b: content.html,
                                ab_editor_state_b:
                                    content.state === null
                                        ? ''
                                        : JSON.stringify(content.state),
                            }))
                        }
```

Autosave (around line 429) submits when `form.isDirty`, then calls `form.setDefaults(sent)`.

`EmailContentEditor`'s `onChange` is `(content, initial?: boolean) => void`, where `initial` means "the builder's own write as it opened" (plan 039).

The pattern to copy is the template page (`pages/marketing/templates/form.tsx`, plan 039):

```tsx
                        onChange={(content, initial) => {
                            const fields = { … };

                            form.setData((data) => ({ ...data, ...fields }));

                            // The builder's own rendering as it opens is the starting point, not an edit, so leaving asks nothing.
                            if (initial) {
                                form.setDefaults(fields);
                            }
                        }}
```

Inertia 3's `form.setDefaults(fields)` merges those fields into the defaults, and `isDirty` is `!isEqual(data, defaults)`.

## Conventions

- Prettier style through `npm run check:fix`. Never run bare `npx prettier`.
- One-line _why_ comments, like the existing ones.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build`. All must exit 0.

## Scope

**In scope:** `pages/marketing/campaigns/edit.tsx`, the two `onChange` handlers only, plus a ref if Step 1 needs one.

**Out of scope:** `useAutosave`, the `email-editors/*` components, PHP, and the approval rule on the server.

## Git workflow

Branch `advisor/045-campaign-open-not-edit` from `dev`. One commit, e.g. `fix(core): opening a campaign no longer re-saves it or withdraws its approval`. Do not push.

## Steps

### Step 1: Main editor

Change its `onChange` to:

```tsx
                    onChange={(content, initial) => {
                        const fields = {
                            editor: content.editor,
                            editor_state: content.state === null ? '' : JSON.stringify(content.state),
                            html: content.html,
                        };

                        form.setData((data) => ({ ...data, ...fields }));

                        // Re-rendering saved content on open isn't an edit, so it isn't autosaved and can't withdraw an approval.
                        // Empty saved HTML (a fresh draft or a starter) still saves, or the campaign would go out blank.
                        if (initial && form.data.html.trim() !== '' && revision === 0) {
                            form.setDefaults(fields);
                        }
                    }}
```

`revision === 0` means the editor wasn't remounted by choosing a template. A chosen template is new content: it's applied with `setData` (not as defaults), so it's already dirty and gets saved anyway. The check stops its mount sync from being taken as the starting point.

Note that `form.data` here is the value at render time, before this `setData`, which is the saved or loaded HTML. That's intended.

### Step 2: Version B editor

Same treatment:

```tsx
                        onChange={(content, initial) => {
                            const fields = {
                                ab_html_b: content.html,
                                ab_editor_state_b: content.state === null ? '' : JSON.stringify(content.state),
                            };

                            form.setData((data) => ({ ...data, ...fields }));

                            // As for version A: re-rendering saved content on open isn't an edit.
                            if (initial && form.data.ab_html_b.trim() !== '') {
                                form.setDefaults(fields);
                            }
                        }}
```

**Verify**: `npm run check:fix`, then `npm run check && npm run types:check && npm run test && npm run build`. All exit 0.

## Test plan

There are no React component tests in this repo. The behaviour is pinned by the manual checks below. The reviewer can run them against the local app with headless Edge.

1. **Approved campaign, opened only.** Take a draft whose saved HTML differs from the builder's current output (any campaign saved before 2026-11-01 will do, or edit `html` in the database to add a trailing space). Approve it, open its editor, wait 3 seconds, then reload the campaign. It's still approved, and there was no `PUT campaigns/{id}` request.
2. **Fresh draft.** Create a new campaign from a starter. Within about 2 seconds, autosave saves its HTML, and the editor bar shows saving and then saved.
3. **Choose a template** on an existing draft. Autosave saves the new content.
4. **A real edit** on an approved campaign still withdraws the approval, as before.

Report which of these you could run.

## Done criteria

- All four commands exit 0.
- `git diff --stat dev` lists only `campaigns/edit.tsx`.
- `grep -n "setDefaults(fields)" packages/core/resources/js/pages/marketing/campaigns/edit.tsx` matches twice.

## STOP conditions

- An excerpt doesn't match.
- `revision` isn't in scope inside `ContentStep`'s editor `onChange`. It's declared in `ContentStep`, so it should be.

## Maintenance notes

- If the campaign editor ever stops autosaving, every initial write should become the starting point, as on the template page.
- The server still withdraws an approval on any real content change, which is correct. This plan only stops the client sending a change that isn't one.
