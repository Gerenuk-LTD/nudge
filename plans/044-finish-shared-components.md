# Plan 044: Finish the shared components: the last empty screens, starter cards and dialogs

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a87a701..HEAD -- packages/core/resources/js/pages/marketing/lists/index.tsx packages/core/resources/js/pages/marketing/segments/index.tsx packages/core/resources/js/pages/marketing/tags/index.tsx packages/core/resources/js/pages/marketing/custom-fields/index.tsx packages/core/resources/js/pages/marketing/automations/index.tsx packages/core/resources/js/pages/marketing/campaigns/show.tsx packages/core/resources/js/components/empty-state.tsx packages/core/resources/js/components/confirm-dialog.tsx`
> Expected: no output. Otherwise check the excerpts below still match, and STOP if one doesn't.

## Status

- **Priority**: P2
- **Effort**: S–M
- **Risk**: LOW. Presentational. Every button keeps the same action and request.
- **Depends on**: 031, 032, 034 (all done)
- **Category**: reuse + consistency
- **Planned at**: commit `a87a701`, 2026-11-13

## Why this matters

Plans 031 and 032 introduced the shared `EmptyState` and `ConfirmDialog`, but left some screens hand-written:

1. **Four empty screens** (Lists, Segments, Tags, Custom fields) still hand-write the dashed "nothing here yet" box. 031's executor skipped them because they also show a "start from one of these" grid. They do fit `EmptyState`, though: plan 034 already passes such a grid through `actions` on the Automations page.
2. **The starter card is copied five times.** It's a bordered button or link with a name, a one-line description and an arrow, used on Lists, Segments, Custom fields, Automations and Tags. Tags uses chips instead. One `StarterCard` component and one `StarterGrid` section should replace the copies.
3. **Two dialogs on the campaign report** ("Send the leading version to everyone else now?" and "Resend to people who didn't open?") are still hand-written `Dialog`s with a grey `secondary` Cancel button. Everywhere else uses `ConfirmDialog`, with a `ghost` Cancel.

## Current state

`packages/core/resources/js/components/empty-state.tsx` exports `EmptyState({ icon?, title?, description?, actions?, className? })`. It renders a dashed box. `actions` goes in `<div className="flex flex-wrap justify-center gap-2 pt-1">{actions}</div>`.

`packages/core/resources/js/components/confirm-dialog.tsx` exports `ConfirmDialog({ open, onOpenChange, title, description?, children?, confirmLabel, cancelLabel = 'Cancel', destructive = true, processing = false, onConfirm })`.

**Exemplar: Automations** (`pages/marketing/automations/index.tsx`, from plan 034). It already uses `EmptyState`, with the starter grid passed in `actions`:

```tsx
                        actions={
                            canManage && (
                                <>
                                    <NewAutomation triggers={triggers} />
                                    {starters.length > 0 && (
                                        <div className="mt-5 w-full max-w-3xl space-y-2.5">
                                            <p className="text-xs font-medium text-muted-foreground">
                                                Or start from one of these
                                            </p>
                                            <div className="grid gap-2 text-left sm:grid-cols-2">
                                                {starters.map((starter) => (
                                                    <button
                                                        key={starter.name}
                                                        type="button"
                                                        onClick={() => router.post(AutomationController.store.url(), { name: starter.name, trigger_type: starter.trigger })}
                                                        className="group flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 text-left shadow-xs transition-colors outline-none hover:border-foreground/20 focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                    >
                                                        <span className="min-w-0 flex-1">
                                                            <span className="block truncate text-sm font-medium">{starter.name}</span>
                                                            <span className="block truncate text-xs text-muted-foreground">{starter.description}</span>
                                                        </span>
                                                        <ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </>
                            )
                        }
```

**The four hand-written screens.** Each is `<div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-muted/40 px-6 py-16 text-center">`, containing an icon circle, a title, a description and `{canEdit && (<>…button… …grid or chips…</>)}`.

| File                      | Line (approx.)                   | Icon              | Title                | Primary button                                                                                  | Starters                                                                                                                                                                                                  |
| ------------------------- | -------------------------------- | ----------------- | -------------------- | ----------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `lists/index.tsx`         | 108                              | `List`            | No lists yet         | `<Button size="sm" className="mt-1" onClick={() => add()}><Plus />New list</Button>`            | `STARTERS.map` buttons → `add(starter)`, showing `starter.name` and `starter.description`, `sm:grid-cols-2`                                                                                               |
| `segments/index.tsx`      | 108 (in a local empty component) | `Filter`          | No segments yet      | `<Button size="sm" className="mt-1" asChild><Link href={create()}>…New segment</Link></Button>` | `segmentStarters(fields).map` **`<Link href={create({ query: { starter: starter.key } })}>`**, showing `starter.name` and `starter.description`, `sm:grid-cols-2`                                         |
| `tags/index.tsx`          | 149                              | `TagIcon`         | No tags yet          | `onClick={() => setAction({ kind: 'create' })}` → New tag                                       | Chips (`rounded-full`), header "Or add some common ones", each `router.post(TagController.store.url(), { name }, { preserveScroll: true })`                                                               |
| `custom-fields/index.tsx` | 249                              | `TextCursorInput` | No custom fields yet | `onClick={() => create()}` → New field                                                          | `STARTERS.map` buttons → `create(starter)`, **with a leading type icon** (`fieldTypes[starter.type].icon`), showing `starter.label` and `fieldTypes[starter.type].label`, `sm:grid-cols-2 lg:grid-cols-3` |

Copy each description string across exactly as it is.

**The two report dialogs** (`pages/marketing/campaigns/show.tsx`, around lines 569 and 600):

- `<Dialog open={confirmingWinner} onOpenChange={setConfirmingWinner}>`:
    - Title: "Send the leading version to everyone else now?"
    - Description: "The remaining audience gets the version that's ahead right now. This can't be undone."
    - `<Button variant="secondary">Not yet</Button>` inside `DialogClose`.
    - A confirm button whose `onClick` runs `setConfirmingWinner(false); router.post(CampaignController.pickWinner.url(campaign.id));`, labelled "Pick the winner". It's the default variant, not destructive.
- `<Dialog open={confirmingResend} onOpenChange={setConfirmingResend}>`:
    - Title: "Resend to people who didn't open?"
    - Description: "This creates a segment … so you can change its subject line first.", copied exactly.
    - `<Button variant="secondary">Cancel</Button>`.
    - A confirm button whose `onClick` runs `setConfirmingResend(false); router.post(CampaignController.resendToNonOpeners.url(campaign.id));`, labelled "Draft the resend".

## Conventions

- Prettier style through `npm run check:fix`. Never run bare `npx prettier`.
- Core components live in `packages/core/resources/js/components/<kebab>.tsx`, export named functions, and carry a one-line JSDoc. Import siblings as `@marketing/components/<name>`.
- Keep each starter's action exactly as it is now: the same function call, `router.post` arguments or `Link` href.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build`. All must exit 0.

## Scope

**In scope:** new `components/starter-grid.tsx`; `lists/index.tsx`, `segments/index.tsx`, `tags/index.tsx`, `custom-fields/index.tsx` and `automations/index.tsx` (their empty branches only); and `campaigns/show.tsx` (the two dialogs only).

**Out of scope:** `empty-state.tsx` and `confirm-dialog.tsx` (use them as they are), the starter data (`STARTERS`, `segmentStarters`), and everything outside the empty branches.

## Git workflow

Branch `advisor/044-finish-shared` from `dev`. Two commits:

- `refactor(core): the last empty screens use EmptyState and a shared starter grid`
- `refactor(core): the report's last two dialogs use ConfirmDialog`

Do not push.

## Steps

### Step 1: `StarterGrid` and `StarterCard`

Create `components/starter-grid.tsx`:

```tsx
/** "Or start from one of these" under an empty screen's main button, as a grid of starter cards. */
export function StarterGrid({ heading = 'Or start from one of these', columns = 2, children }: { heading?: string; columns?: 2 | 3; children: ReactNode }) {
    return (
        <div className="mt-5 w-full max-w-3xl space-y-2.5">
            <p className="text-xs font-medium text-muted-foreground">{heading}</p>
            <div className={cn('grid gap-2 text-left sm:grid-cols-2', columns === 3 && 'lg:grid-cols-3')}>{children}</div>
        </div>
    );
}

/** One starter: a name, a short description and an arrow. Give `href` for a link, or `onClick` for an action. */
export function StarterCard({ title, description, icon: Icon, href, onClick }: {
    title: ReactNode; description: ReactNode; icon?: LucideIcon; href?: string | { url: string; method: string }; onClick?: () => void;
}) { … }
```

`StarterCard` details:

- Classes: `group flex items-center gap-3 rounded-lg border bg-card px-3.5 py-3 text-left shadow-xs transition-colors outline-none hover:border-foreground/20 focus-visible:ring-[3px] focus-visible:ring-ring/50`.
- Body: an optional leading icon (`<Icon className="size-4 shrink-0 text-muted-foreground" />`), then `<span className="min-w-0 flex-1">` holding a title line (`block truncate text-sm font-medium`) and a description line (`block truncate text-xs text-muted-foreground`), then `<ArrowRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />`.
- With `href`, render Inertia `<Link href={href}>`. Type `href` the way `Link`'s own `href` prop is typed: use `ComponentProps<typeof Link>['href']`. Otherwise render `<button type="button" onClick={onClick}>`.

**Verify**: `npm run types:check` exits 0.

### Step 2: Move the five empty screens onto `EmptyState` and `StarterGrid`

For Lists, Segments and Custom fields, replace the hand-written box with:

```tsx
<EmptyState icon={…} title="…" description="…"
    actions={canEdit && (<>{primary button, unchanged}<StarterGrid columns={…}>{…StarterCard per starter, same action…}</StarterGrid></>)} />
```

- Custom fields uses `columns={3}`, and its cards pass `icon={fieldTypes[starter.type].icon}`.
- Segments' cards pass `href={create({ query: { starter: starter.key } })}`.
- Drop `className="mt-1"` from the primary buttons, because `EmptyState` already spaces its actions.

For Automations, replace its inline grid with `StarterGrid` and `StarterCard`, using the same `router.post` call.

For Tags, use `EmptyState` with `actions={canEdit && (<>{New tag button}<div className="mt-4 w-full space-y-2.5"><p className="text-xs font-medium text-muted-foreground">Or add some common ones</p><div className="flex flex-wrap justify-center gap-1.5">{chips exactly as now}</div></div></>)}`. Keep the chips themselves as they are.

Remove imports that are no longer used. `npm run check` flags them.

**Verify**: `npm run check:fix && npm run check && npm run types:check` all exit 0. `grep -rn "rounded-xl border border-dashed bg-muted/40" packages/core/resources/js/pages` lists only `imports/create.tsx`, `campaigns/edit.tsx` and `campaigns/index.tsx`, which are different by design. `grep -rln "StarterCard" packages/core/resources/js/pages` lists `lists`, `segments`, `custom-fields` and `automations`.

### Step 3: The report's two dialogs

Replace both with `ConfirmDialog`. Each keeps:

- the same `open` / `onOpenChange` state,
- its title and description text, copied exactly,
- `destructive={false}`,
- `cancelLabel="Not yet"` for the winner dialog (the resend dialog keeps the default "Cancel"),
- `confirmLabel` set to "Pick the winner" or "Draft the resend",
- `onConfirm` with exactly the same two statements as today.

Remove `Dialog*` imports that are no longer used.

**Verify (final)**: `npm run check:fix`, then `npm run check && npm run types:check && npm run test && npm run build`. All exit 0. `grep -n 'variant="secondary">' packages/core/resources/js/pages/marketing/campaigns/show.tsx` gives no output. (The `Badge variant="secondary"` "Winner" badge doesn't match this grep, because it isn't followed by `>` straight after the quote. If it does match, check it's only the Badge.)

## Test plan

No unit tests (presentational). The reviewer will screenshot the empty Lists, Segments, Tags and Custom fields screens with headless Edge. Their test workspace is empty, so it shows those screens. Report if you couldn't check them visually.

## Done criteria

- All four commands exit 0.
- The greps in Steps 2 and 3 give the expected output.
- `git diff --stat dev` lists only in-scope files.

## STOP conditions

- An excerpt doesn't match.
- A starter's action can't be expressed as `href` or `onClick` without changing what it does.

## Maintenance notes

- New empty screens: `EmptyState`, plus `StarterGrid` and `StarterCard` when there are suggestions. New confirms: `ConfirmDialog`.
