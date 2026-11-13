# Plan 033: Small shared pieces — `CopyField`, `ToggleRow`, `ContactStatusBadge`; drop dead `EditDialog`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 2c44823..HEAD -- resources/js/hooks/use-clipboard.ts packages/core/resources/js/components/contact-fields.tsx packages/core/resources/js/components/contact-preview.tsx packages/core/resources/js/components/edit-dialog.tsx packages/core/resources/js/pages/marketing/providers/show.tsx packages/core/resources/js/pages/marketing/workspace/api-tokens.tsx packages/core/resources/js/pages/marketing/workspace/sending.tsx packages/core/resources/js/pages/marketing/contacts/index.tsx packages/core/resources/js/pages/marketing/contacts/show.tsx`
> Expected: no output, or only changes from plans 029–032. Re-check each excerpt below; STOP if one doesn't match.

## Status

- **Priority**: P3
- **Effort**: S
- **Risk**: LOW
- **Depends on**: run after 030 and 032 (they touch `providers/show.tsx` and `workspace/api-tokens.tsx`).
- **Category**: reuse + small bug
- **Planned at**: commit `2c44823`, 2026-11-12

## Why this matters

Four small duplications, one of them carrying a bug:

1. **Copy field** — a read-only input + "Copy/Copied" button is written twice, and the shared `useClipboard` hook never resets, so the button says "Copied" forever after one click.
2. **Toggle row** — label + description + switch in a muted box is identical in two places.
3. **Contact status badge** — the subscribed/unsubscribed → tone mapping is written three times (campaigns and imports already have `campaign-status-badge.tsx` / `import-status-badge.tsx`).
4. **`EditDialog`** (`packages/core/resources/js/components/edit-dialog.tsx`) has no importers — dead code.

## Current state

`resources/js/hooks/use-clipboard.ts`:

```ts
export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);

    const copy: CopyFn = async (text) => {
        if (!navigator?.clipboard) {
            console.warn('Clipboard not supported');
            return false;
        }
        try {
            await navigator.clipboard.writeText(text);
            setCopiedText(text);
            return true;
        } catch (error) {
            console.warn('Copy failed', error);
            setCopiedText(null);
            return false;
        }
    };

    return [copiedText, copy];
}
```

Used by `providers/show.tsx`, `workspace/api-tokens.tsx`, `custom-fields/index.tsx`, `resources/js/components/two-factor-setup-modal.tsx`.

`packages/core/resources/js/pages/marketing/providers/show.tsx` (around line 409):

```tsx
<div className="flex gap-2">
    <Input
        readOnly
        aria-label="Webhook URL"
        value={provider.webhook_url ?? ''}
        onFocus={(event) => event.target.select()}
        className="font-mono text-xs"
    />
    <Button
        type="button"
        variant="outline"
        onClick={() => copy(provider.webhook_url ?? '')}
    >
        {copied === provider.webhook_url ? <Check /> : <Copy />}
        {copied === provider.webhook_url ? 'Copied' : 'Copy'}
    </Button>
</div>
```

`packages/core/resources/js/pages/marketing/workspace/api-tokens.tsx` (around line 247) — same, with `aria-label="New API token"`, `value={token}`, and `className="bg-card font-mono text-xs"` on the input / `className="bg-card"` on the button (it sits on a green tinted box).

`packages/core/resources/js/components/contact-fields.tsx` (around line 182) and `packages/core/resources/js/pages/marketing/workspace/sending.tsx` (around line 127):

```tsx
        <div className="flex items-start justify-between gap-4 rounded-lg border bg-muted/30 px-3.5 py-3">
            <div className="space-y-0.5">
                <Label htmlFor={id} className="text-sm">…title…</Label>
                <p className="text-xs text-muted-foreground">…description…</p>
                {/* sending.tsx adds a second <p> and an <InputError> here */}
            </div>
            <span className="pt-0.5">
                <ToggleSwitch id={id} checked={…} onCheckedChange={…} /* sending.tsx also passes disabled */ />
            </span>
        </div>
```

Contact status, three copies:

- `pages/marketing/contacts/index.tsx` ~433: local `function ContactStatusBadge({ status }: { status: ContactStatus })` returning `<StatusBadge tone={status === 'subscribed' ? 'success' : 'neutral'}>{status === 'subscribed' ? 'Subscribed' : 'Unsubscribed'}</StatusBadge>`
- `pages/marketing/contacts/show.tsx` ~202: the same JSX inline on `contact.status`
- `components/contact-preview.tsx` ~82: the same JSX inline on `contact.status`

`ContactStatus` is exported from `@marketing/types`.

## Conventions

Exemplar for a status badge wrapper: `packages/core/resources/js/components/import-status-badge.tsx`. Core components: `packages/core/resources/js/components/<kebab>.tsx`, named exports, JSDoc line.

## Commands you will need

`npm run check` · `npm run types:check` · `npm run test` · `npm run build` — all exit 0.

## Scope

**In scope:** `resources/js/hooks/use-clipboard.ts`; new `packages/core/resources/js/components/copy-field.tsx`, `toggle-row.tsx`, `contact-status-badge.tsx`; edits to `providers/show.tsx`, `workspace/api-tokens.tsx`, `contact-fields.tsx`, `workspace/sending.tsx`, `contacts/index.tsx`, `contacts/show.tsx`, `contact-preview.tsx`; delete `edit-dialog.tsx`.
**Out of scope:** `two-factor-setup-modal.tsx` and `custom-fields/index.tsx` markup (they use the hook differently; they only pick up the reset behaviour); `campaigns/edit.tsx`'s `SettingRow`; segmented controls.

## Git workflow

Branch `advisor/033-small-components` from `dev`, one commit per step, conventional commits. Do not push.

## Steps

### Step 1: `useClipboard` resets after 2 seconds

In `use-clipboard.ts`, after `setCopiedText(text);` schedule a reset with a ref so repeated clicks restart the timer:

```ts
import { useEffect, useRef, useState } from 'react';
// inside the hook:
const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
useEffect(
    () => () => {
        if (timer.current) clearTimeout(timer.current);
    },
    [],
);
// after setCopiedText(text):
if (timer.current) clearTimeout(timer.current);
timer.current = setTimeout(() => setCopiedText(null), 2000);
```

Keep the return type and the `// Credit` comment.

**Verify**: `npm run types:check` → exit 0.

### Step 2: `CopyField`

```tsx
/** A read-only value with a Copy button, for URLs and tokens people paste elsewhere. */
export function CopyField({
    value,
    label,
    className,
}: {
    value: string;
    label: string;
    className?: string;
}) {
    const [copied, copy] = useClipboard();
    const done = copied === value;
    return (
        <div className="flex gap-2">
            <Input
                readOnly
                aria-label={label}
                value={value}
                onFocus={(event) => event.target.select()}
                className={cn('min-w-0 font-mono text-xs', className)}
            />
            <Button
                type="button"
                variant="outline"
                className={className}
                onClick={() => copy(value)}
            >
                {done ? <Check /> : <Copy />}
                {done ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}
```

(imports: `Check, Copy` from `lucide-react`, `Button`, `Input` from `@/components/ui/*`, `useClipboard` from `@/hooks/use-clipboard`, `cn`.)

Replace the providers block with `<CopyField label="Webhook URL" value={provider.webhook_url ?? ''} />` and the api-tokens block with `<CopyField label="New API token" value={token} className="bg-card" />`. Remove the `useClipboard` call and `Check`/`Copy` imports from those files **only if** nothing else in the file uses them.

### Step 3: `ToggleRow`

```tsx
/** A setting that is on or off: a label and explanation beside a switch, in a light inset box. */
export function ToggleRow({
    id,
    label,
    description,
    checked,
    onCheckedChange,
    disabled,
    children,
}: {
    id: string;
    label: ReactNode;
    description?: ReactNode;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    /** Extra notes or an error under the description. */
    children?: ReactNode;
}) {
    return (
        <div className="flex items-start justify-between gap-4 rounded-lg border bg-muted/30 px-3.5 py-3">
            <div className="space-y-0.5">
                <Label htmlFor={id} className="text-sm">
                    {label}
                </Label>
                {description && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
                {children}
            </div>
            <span className="pt-0.5">
                <ToggleSwitch
                    id={id}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={onCheckedChange}
                />
            </span>
        </div>
    );
}
```

Import `ToggleSwitch` the same way `contact-fields.tsx` does now. If `ToggleSwitch` has no `disabled` prop, STOP. Use it in both places; in `sending.tsx` pass the second `<p>` and `<InputError>` as `children`.

### Step 4: `ContactStatusBadge`

Create `contact-status-badge.tsx` with the body of the local function in `contacts/index.tsx` (props `{ status: ContactStatus }`). Delete the local one, and replace the two inline copies with `<ContactStatusBadge status={contact.status} />`.

### Step 5: Delete `EditDialog`

First run `grep -rn "edit-dialog\|EditDialog" packages/core resources/js --include=*.ts --include=*.tsx | grep -v "components/edit-dialog.tsx"` → must be no output (otherwise STOP). Then `git rm packages/core/resources/js/components/edit-dialog.tsx`.

**Verify (final)**: `npm run check && npm run types:check && npm run test && npm run build` → all exit 0.

## Test plan

No component test infrastructure; don't add one. Manual if possible: a provider page's webhook URL copies and the button returns to "Copy" after ~2s; creating an API token shows the same; Settings → Sending approval toggle still saves and still shows its disabled note for non-approvers; the add-contact dialog's subscribed toggle works; contact list, contact page and contact preview sheet show the same badge.

## Done criteria

- All four commands exit 0.
- `test -f packages/core/resources/js/components/edit-dialog.tsx` → false.
- `grep -rn "'Subscribed' : 'Unsubscribed'" packages/core/resources/js` → only `contact-status-badge.tsx`.
- `grep -rn "bg-muted/30 px-3.5 py-3" packages/core/resources/js` → only `toggle-row.tsx`.

## STOP conditions

- An excerpt doesn't match; `ToggleSwitch` lacks `disabled`; `EditDialog` turns out to have an importer.

## Maintenance notes

- `useClipboard` lives in the host app (`resources/js/hooks`) but core imports it; the reset change affects the 2FA modal too (it now resets after 2s — intended).
- Not done here: the segmented control (5 copies with inconsistent ARIA roles) and a form `Field` wrapper (~85 inline copies) — larger, separate plans if wanted.
