import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

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
