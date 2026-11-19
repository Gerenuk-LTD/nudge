import { Stepper } from '@nudge/components/editor-bar';

const steps = [
    { key: 'upload', label: 'Upload' },
    { key: 'map', label: 'Map columns' },
    { key: 'import', label: 'Import' },
];

/**
 * Where an import is: uploading the file, matching its columns, then bringing the rows in.
 */
export function ImportSteps({
    current,
    finished = false,
}: {
    current: 'upload' | 'map' | 'import';
    finished?: boolean;
}) {
    const position = steps.findIndex((step) => step.key === current);

    return (
        <Stepper
            current={finished ? '' : current}
            steps={steps.map((step, index) => ({
                ...step,
                state: index < position || finished ? 'complete' : undefined,
            }))}
        />
    );
}
