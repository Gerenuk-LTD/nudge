# Plan 013: Frontend unit tests run in CI

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat 1091bad..HEAD -- vite.config.ts package.json`
> Expected: no output.

## Status

- **Priority**: P1. CI on `dev` is red until this is fixed.
- **Effort**: S
- **Risk**: LOW
- **Depends on**: plans/010 (DONE)
- **Category**: dx
- **Planned at**: commit `1091bad`, 2026-10-30

## Why this matters

Plan 010 added `npm run test` (`vp test run`, which uses Vitest) to `composer ci:check`. In GitHub Actions, run `41049581995` failed at that step:

```
Startup Error
Error: You should not run the Vite HMR server in CI environments. You should build your assets for production instead. To disable this ENV check you may set LARAVEL_BYPASS_ENV_CHECK=1
    at async resolveConfig (.../vitest/dist/chunks/index...js)
```

Vitest loads `vite.config.ts`, including `laravel-vite-plugin`. That plugin refuses to start a dev server when the `CI` environment variable is set. The tests only cover pure TypeScript modules, so they need neither the Laravel plugin nor the Wayfinder plugin. Locally `CI` is unset, which is why plan 010 passed there.

## Current state

The top of `vite.config.ts`:

```ts
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.tsx'], refresh: true, fonts: [ ... ] }),
        inertia(),
        react(),
        babel({ presets: [reactCompilerPreset()] }),
        tailwindcss(),
        wayfinder({ formVariants: true }),
    ]),
    ...
    test: {
        include: ['packages/core/resources/js/**/*.test.ts', 'resources/js/**/*.test.ts'],
        environment: 'node',
    },
```

Vitest sets `process.env.VITEST` while it runs.

## Commands you will need

| Purpose                  | Command                                | Expected                                                                                                               |
| ------------------------ | -------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Reproduce the CI failure | `CI=true npm run test`                 | Before the fix: "Startup Error … You should not run the Vite HMR server in CI environments". After the fix: 13 passed. |
| Tests                    | `npm run test`                         | 13 passed                                                                                                              |
| Build                    | `npm run build`                        | exit 0                                                                                                                 |
| Lint, format and types   | `npm run check && npm run types:check` | exit 0                                                                                                                 |

## Scope

**In scope:** `vite.config.ts`.

**Out of scope:**

- `package.json`. Don't put `LARAVEL_BYPASS_ENV_CHECK` in the script, because that would also bypass the check that protects real CI builds.
- `composer.json` and any test file.

## Git workflow

- Branch: `advisor/013-tests-in-ci`.
- Commit: `fix: skip the laravel and wayfinder vite plugins when vitest loads the config`.
- Use the Co-Authored-By trailer your system instructions specify.
- Do not push.

## Steps

### Step 1: Reproduce

Run `CI=true npm run test`. Expected: the startup error above. If it passes instead, STOP and report.

### Step 2: Skip the server-only plugins under Vitest

Inside the `lazyPlugins` callback, add `const testing = Boolean(process.env.VITEST);`. Include `laravel(...)` and `wayfinder(...)` only when `! testing`, for example by spreading `...(testing ? [] : [laravel({...})])` and doing the same for `wayfinder`. Keep every other plugin and every option unchanged. Add a one-line comment: `// Vitest only runs pure modules; the Laravel and Wayfinder plugins expect a dev server or build and refuse to run under CI.`

**Verify**: `CI=true npm run test` → 13 passed
**Verify**: `npm run test && npm run build && npm run check && npm run types:check` → all exit 0

## Done criteria

- [ ] `CI=true npm run test` passes, with 13 tests.
- [ ] `npm run build`, `npm run check` and `npm run types:check` exit 0.
- [ ] Only `vite.config.ts` is changed.

## STOP conditions

- `lazyPlugins` doesn't run its callback with `process.env.VITEST` set (the error persists after the change). Report the observed behaviour instead of falling back to the env-bypass variable.
- The build output changes in any way other than timestamps or hashes. It shouldn't, because `VITEST` is unset during builds.

## Maintenance notes

- Any future Vite plugin that needs a server or PHP, or that checks for CI, should also be skipped under `VITEST`.
