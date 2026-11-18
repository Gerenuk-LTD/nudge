import { router } from '@inertiajs/react';
import { Eye, EyeOff, LockKeyhole, RefreshCw } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import { Button } from '@/components/ui/button';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

type Props = {
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    /** Runs the action once the password is confirmed, as reading or replacing codes needs. */
    requirePassword: (action: () => void) => void;
    errors: string[];
};

export default function TwoFactorRecoveryCodes({
    recoveryCodesList,
    fetchRecoveryCodes,
    requirePassword,
    errors,
}: Props) {
    const [codesAreVisible, setCodesAreVisible] = useState<boolean>(false);
    const codesSectionRef = useRef<HTMLDivElement | null>(null);
    const canRegenerateCodes = recoveryCodesList.length > 0 && codesAreVisible;

    const show = useCallback(async () => {
        if (!recoveryCodesList.length) {
            await fetchRecoveryCodes();
        }

        setCodesAreVisible(true);
        setTimeout(() => {
            codesSectionRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
            });
        });
    }, [recoveryCodesList.length, fetchRecoveryCodes]);

    // Codes are only fetched when asked for, since reading them needs a recent password confirmation.
    const toggleCodesVisibility = () =>
        codesAreVisible
            ? setCodesAreVisible(false)
            : requirePassword(() => void show());

    const RecoveryCodeIconComponent = codesAreVisible ? EyeOff : Eye;

    return (
        <div className="rounded-md border bg-muted/30 p-4">
            <div className="flex flex-col gap-3 select-none sm:flex-row sm:items-start sm:justify-between">
                <div className="flex gap-3">
                    <LockKeyhole
                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div className="space-y-0.5">
                        <p className="text-sm font-medium">Recovery codes</p>
                        <p className="text-sm text-muted-foreground">
                            Get back in if you lose your authenticator. Keep
                            them in a password manager.
                        </p>
                    </div>
                </div>
                <div className="flex shrink-0 gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={toggleCodesVisibility}
                        className="w-fit"
                        aria-expanded={codesAreVisible}
                        aria-controls="recovery-codes-section"
                    >
                        <RecoveryCodeIconComponent
                            className="size-4"
                            aria-hidden="true"
                        />
                        {codesAreVisible ? 'Hide' : 'View'} codes
                    </Button>

                    {canRegenerateCodes && (
                        <Button
                            variant="outline"
                            size="sm"
                            aria-describedby="regenerate-warning"
                            onClick={() =>
                                requirePassword(() =>
                                    router.post(
                                        regenerateRecoveryCodes.url(),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                void fetchRecoveryCodes(),
                                        },
                                    ),
                                )
                            }
                        >
                            <RefreshCw /> Regenerate
                        </Button>
                    )}
                </div>
            </div>
            <div
                id="recovery-codes-section"
                className={`relative overflow-hidden transition-all duration-300 ${codesAreVisible ? 'h-auto opacity-100' : 'h-0 opacity-0'}`}
                aria-hidden={!codesAreVisible}
            >
                <div className="mt-4 space-y-3">
                    {errors?.length ? (
                        <AlertError errors={errors} />
                    ) : (
                        <>
                            <div
                                ref={codesSectionRef}
                                className="grid grid-cols-1 gap-x-6 gap-y-1 rounded-md border bg-background p-4 font-mono text-sm sm:grid-cols-2"
                                role="list"
                                aria-label="Recovery codes"
                            >
                                {recoveryCodesList.length ? (
                                    recoveryCodesList.map((code, index) => (
                                        <div
                                            key={index}
                                            role="listitem"
                                            className="select-text"
                                        >
                                            {code}
                                        </div>
                                    ))
                                ) : (
                                    <div
                                        className="col-span-2 space-y-2"
                                        aria-label="Loading recovery codes"
                                    >
                                        {Array.from(
                                            { length: 8 },
                                            (_, index) => (
                                                <div
                                                    key={index}
                                                    className="h-4 animate-pulse rounded bg-muted-foreground/20"
                                                    aria-hidden="true"
                                                />
                                            ),
                                        )}
                                    </div>
                                )}
                            </div>

                            <div className="text-xs text-muted-foreground select-none">
                                <p id="regenerate-warning">
                                    Each code works once. Regenerate to get a
                                    fresh set; the old codes stop working.
                                </p>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
