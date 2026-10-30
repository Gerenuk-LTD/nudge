# Plan 010: Editing an HTML email in the builder keeps its styles, and the builder's pure logic has tests

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat f7bb714..HEAD -- packages/core/resources/js/components/email-editors vite.config.ts package.json composer.json`
> Expected: no output. On a mismatch with the excerpts below, STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: MED. The builder is used by every campaign, template and automation email.
- **Depends on**: none
- **Category**: bug + tests
- **Planned at**: commit `f7bb714`, 2026-10-30

## Why this matters

Drag and drop is the default editor (a deliberate product decision), so every email opens in the builder, including emails written as raw HTML. Those open as a single "HTML block", which is fine. But `documentFromHtml` keeps only what is inside `<body>`. The first edit in the builder then replaces the stored HTML with `renderEmail(doc)`, which discards the original `<head>`: its `<style>` sheet, media queries and meta tags.

With autosave on, a typo fix in a campaign written in HTML silently strips its responsive CSS within 1.2 seconds. There is no warning and no undo.

There are also no frontend tests at all, so nothing catches regressions in this serialisation code. vite-plus already bundles Vitest (`vp test`), so no new dependency is needed.

## Current state

All paths below are relative to `packages/core/resources/js/components/email-editors/` unless stated otherwise.

`blocks/model.ts`:

```ts
export type EmailSettings = {
    /** Behind the email. */
    backdrop: string;
    /** The email's own background. */
    canvas: string;
    /** Content width in pixels on wide screens; it shrinks to fit phones. */
    width: number;
    font: string;
    text: string;
    link: string;
};
...
export const defaultSettings: EmailSettings = { backdrop: '#f4f4f5', canvas: '#ffffff', width: 600, font: FONTS[0].value, text: '#18181b', link: '#2563eb' };
...
/** The stored state as a document, or null when it is missing or from an editor this one cannot read. */
export function asDocument(state: unknown): EmailDocument | null { ... settings: { ...defaultSettings, ...document.settings } ... }

/**
 * A builder document holding an existing HTML email as one HTML block, so switching an email to the builder keeps
 * its content. White backgrounds let the email's own design show as it did.
 */
export function documentFromHtml(html: string): EmailDocument {
    const body = /<body[^>]*>([\s\S]*)<\/body>/i.exec(html)?.[1] ?? html;

    return {
        version: 1,
        settings: { ...defaultSettings, backdrop: '#ffffff', canvas: '#ffffff' },
        blocks: [{ id: newId(), type: 'html', html: body.trim() }],
    };
}
```

`blocks/render.ts` (lines 232-259):

```ts
export function renderEmail(document: EmailDocument): string {
    const { settings } = document;
    const rows = renderRows(
        document.blocks,
        settings,
        contentWidth(settings),
        false,
    );

    return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>@media (max-width: ${settings.width + 32}px) { .email-container { width: 100% !important; } .email-column { display: block !important; width: 100% !important; box-sizing: border-box; } }</style>
</head>
<body style="margin:0;padding:0;background:${settings.backdrop}">
...
</html>`;
}
```

`render.ts` imports only `type { CSSProperties } from 'react'`, `COLUMN_LAYOUTS` and types from `./model`. `tree.ts` imports only types from `./model`. Both are pure and easy to unit-test.

`content-editor.tsx` (lines 34-50) always opens the builder (`const editorKey = chosen ?? 'blocks';`). It shows HTML content through `documentFromHtml(content.html)` when `content.editor !== 'blocks'`. Leave that behaviour alone: the builder being the default is a product decision.

**Tooling** (repo root):

- `package.json` has these scripts: `build: vp build`, `check: vp check`, `check:fix: vp check --fix`, `types:check: tsc --noEmit`. It has no `test` script.
- `vp test` exists (`npx vp --help` lists "test Run tests"), backed by Vitest 5, which is already installed through vite-plus.
- `vite.config.ts` uses `defineConfig` from `vite-plus` with `plugins: lazyPlugins(...)`, `resolve.alias['@marketing']` pointing to `realpathSync('vendor/gerenuk/marketing-core/resources/js')` (a symlink to `packages/core/resources/js`), and blocks for `lint` and `fmt`. It has no `test` block.
- `composer.json` has `"ci:check": ["Composer\\Config::disableProcessTimeout", "npm run check", "npm run types:check", "@test"]`. CI runs this.

Vitest's default include would also scan `vendor/`, and the core files are reachable through both `packages/core` and the `vendor/gerenuk/marketing-core` symlink. The include must therefore be explicit.

## Commands you will need

Run these from the repo root.

| Purpose         | Command                                           | Expected |
| --------------- | ------------------------------------------------- | -------- |
| JS tests        | `npm run test` (you will add it as `vp test run`) | all pass |
| Types           | `npm run types:check`                             | exit 0   |
| Lint and format | `npm run check`                                   | exit 0   |
| Build           | `npm run build`                                   | exit 0   |

## Scope

**In scope:**

- `vite.config.ts` (add a `test` block)
- `package.json` (add a `test` script only, with no dependency changes)
- `composer.json` (add `npm run test` to `ci:check` only)
- `packages/core/resources/js/components/email-editors/blocks/model.ts`
- `packages/core/resources/js/components/email-editors/blocks/render.ts`
- New test files `packages/core/resources/js/components/email-editors/blocks/model.test.ts`, `render.test.ts` and `tree.test.ts`

**Out of scope:**

- `content-editor.tsx` and its default-to-builder behaviour.
- The 600px wrapper that the builder adds around imported HTML. Its removal is deferred and noted below.
- The block editor UI.
- Installing any package. If `vp test` can't run without a new dependency, STOP.

## Git workflow

- Branch: `advisor/010-keep-html-head`.
- Commits:
    - `test: run frontend unit tests with vp test`
    - `fix: keep an html email's head styles when it is edited in the builder`
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Set up `vp test` with a smoke test first

- In `vite.config.ts`, add `test: { include: ['packages/core/resources/js/**/*.test.ts', 'resources/js/**/*.test.ts'], environment: 'node' }`.
- In `package.json`, add the script `"test": "vp test run"`.
- Write `blocks/tree.test.ts` with these cases, reading `tree.ts` to see its exact exported function names:
    - inserting a block at an index;
    - moving a block from index 0 to 2 matches `arrayMove` semantics;
    - removing a block;
    - a columns block cannot be inserted into a column;
    - an out-of-range index is clamped.

**Verify**: `npm run test` → tree tests pass. If config or plugin loading errors appear, for example from the laravel plugin under Vitest, STOP and report the exact error.

### Step 2: Characterise the current behaviour before changing it

Write `blocks/render.test.ts` and `blocks/model.test.ts`, and run them against the current code:

- `renderEmail(defaultDocument())` contains `<!DOCTYPE html>`, the 600px container and the footer, and escapes text with `<`/`&`. Use a text block whose HTML is set through the model, and assert the output.
- `asDocument(null)` returns `null`. `asDocument({version:1, blocks:[], settings:{}})` returns a document whose settings equal `defaultSettings`.
- `documentFromHtml('<html><body><p>Hi</p></body></html>')` gives one `html` block with `<p>Hi</p>`.

Then add the regression test, which **must fail now**:

```ts
it('keeps the head of an imported HTML email when it is rendered again', () => {
    const html =
        '<!DOCTYPE html><html><head><style>.cta{color:red}@media (max-width:480px){.cta{display:block}}</style></head><body><a class="cta">Go</a></body></html>';
    const output = renderEmail(documentFromHtml(html));
    expect(output).toContain('.cta{color:red}');
    expect(output).toContain('@media (max-width:480px)');
    expect(output).toContain('<a class="cta">Go</a>');
});
```

**Verify**: `npm run test` → only the regression test fails. Report that result.

### Step 3: Preserve the head

- In `model.ts`, add an optional `head?: string` to `EmailSettings`, with the doc comment `/** Markup from an imported HTML email's <head>, such as its styles, kept so editing it in the builder doesn't lose them. */`.
- In `documentFromHtml`, extract `/<head[^>]*>([\s\S]*)<\/head>/i`. Drop any `<meta charset…>` and `<meta name="viewport"…>` tags from it, plus the builder's own `<style>` element (the one whose text contains `.email-container`), because `renderEmail` already emits those. Without this, re-importing builder output would duplicate them. Store the rest, trimmed, as `settings.head`, or leave the key out when the result is empty.
- In `render.ts` `renderEmail`, emit `${settings.head ?? ''}` inside `<head>`, after the builder's own `<style>` line, so the email's own styles take precedence. Don't escape it: it is the user's own email markup, and every preview renders inside sandboxed iframes.
- Don't put `head` in `defaultSettings`. `asDocument`'s spread keeps it when present.

**Verify**: `npm run test` → all pass, including the regression test
**Verify**: `npm run types:check && npm run check && npm run build` → exit 0

### Step 4: Run it in CI

In `composer.json`'s `ci:check`, add `"npm run test"` after `"npm run types:check"`.

**Verify**: `node -e "console.log(require('./composer.json').scripts['ci:check'])"` → includes `npm run test`

## Test plan

This plan creates the frontend test harness:

- `tree.test.ts`: at least 5 cases.
- `render.test.ts` and `model.test.ts`: at least 6 cases, including the head regression test.
- Also add a round-trip test: `documentFromHtml(renderEmail(documentFromHtml(html)))` keeps the head exactly once and does not duplicate it.

## Done criteria

- [ ] `npm run test` exits 0, with at least 12 tests.
- [ ] `grep -n "head?:" packages/core/resources/js/components/email-editors/blocks/model.ts` returns a match.
- [ ] `grep -n '"test"' package.json` returns a match, and `git diff f7bb714 -- package.json` shows only that script line added.
- [ ] `npm run types:check`, `npm run check` and `npm run build` all exit 0.
- [ ] Only in-scope files are changed.

## STOP conditions

- `vp test` needs a dependency that isn't installed, or the vite config can't load under the test runner.
- Preserving the head makes the round-trip test duplicate styles and there's no simple fix. Report it rather than rewriting the builder's head output.
- Any change is needed in `content-editor.tsx`.

## Maintenance notes

- Imported HTML is still wrapped in the builder's container, which defaults to 600px wide with padding. Full-width designs will look narrower after their first builder edit. The follow-up is a "raw" flag on documents built from HTML that skips the wrapper while the document is a single HTML block.
- New pure modules under `components/email-editors` should get a `*.test.ts` alongside them.
- Reviewers should check that `settings.head` is never rendered outside a sandboxed iframe in the app.
