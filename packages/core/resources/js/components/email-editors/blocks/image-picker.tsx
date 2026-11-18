import { ImagePlus, LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { DragEvent } from 'react';
import EmailAssetController from '@/actions/Gerenuk/Marketing/Http/Controllers/EmailAssetController';
import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { getJson, uploadFile } from '@marketing/lib/http';

export type EmailAsset = {
    id: number;
    url: string;
    name: string;
    width: number | null;
    height: number | null;
};

/** Recent uploads, shared between pickers so switching images does not refetch them. */
let recentCache: EmailAsset[] | null = null;

/**
 * Upload an image by dropping or choosing a file, or pick one uploaded before. Hands back the image's public address.
 */
export function ImagePicker({
    value,
    onPick,
}: {
    value: string;
    onPick: (asset: EmailAsset) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [recent, setRecent] = useState<EmailAsset[] | null>(recentCache);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const [over, setOver] = useState(false);

    useEffect(() => {
        if (recentCache !== null) {
            return;
        }

        const controller = new AbortController();

        getJson<{ assets: EmailAsset[] }>(
            EmailAssetController.index.url(),
            controller.signal,
        )
            .then(({ assets }) => {
                recentCache = assets;
                setRecent(assets);
            })
            .catch(() => setRecent([]));

        return () => controller.abort();
    }, []);

    const upload = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(true);
        setError(undefined);
        const result = await uploadFile<{ asset: EmailAsset }>(
            EmailAssetController.store.url(),
            file,
        );
        setUploading(false);

        if (!result.ok) {
            setError(Object.values(result.errors).flat()[0]);

            return;
        }

        recentCache = [result.data.asset, ...(recentCache ?? [])];
        setRecent(recentCache);
        onPick(result.data.asset);
    };

    const onDrop = (event: DragEvent) => {
        event.preventDefault();
        setOver(false);
        void upload(event.dataTransfer.files[0]);
    };

    return (
        <div className="space-y-3">
            <button
                type="button"
                onClick={() => input.current?.click()}
                onDragOver={(event) => {
                    event.preventDefault();
                    setOver(true);
                }}
                onDragLeave={() => setOver(false)}
                onDrop={onDrop}
                disabled={uploading}
                className={cn(
                    'flex w-full flex-col items-center gap-1.5 rounded-lg border border-dashed px-3 py-5 text-center text-13 text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground',
                    over && 'border-sky-500 bg-sky-50 text-sky-700',
                )}
            >
                {uploading ? (
                    <LoaderCircle className="size-5 animate-spin" />
                ) : (
                    <ImagePlus className="size-5" />
                )}
                <span className="font-medium">
                    {uploading ? 'Uploading…' : 'Upload an image'}
                </span>
                <span className="text-xs">
                    Drop a file here or click to choose. JPG, PNG, GIF or WebP,
                    up to 5 MB.
                </span>
            </button>
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                className="hidden"
                onChange={(event) => {
                    void upload(event.target.files?.[0]);
                    event.target.value = '';
                }}
            />
            <InputError message={error} />

            {recent && recent.length > 0 && (
                <div className="space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">
                        Recent uploads
                    </p>
                    <div className="grid grid-cols-3 gap-1.5">
                        {recent.map((asset) => (
                            <button
                                key={asset.id}
                                type="button"
                                title={asset.name}
                                aria-label={`Use ${asset.name}`}
                                onClick={() => onPick(asset)}
                                className={cn(
                                    'aspect-square overflow-hidden rounded-md border bg-muted transition-[box-shadow] outline-none hover:ring-2 hover:ring-foreground/20 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    asset.url === value &&
                                        'ring-2 ring-foreground',
                                )}
                            >
                                <img
                                    src={asset.url}
                                    alt=""
                                    loading="lazy"
                                    className="size-full object-cover"
                                />
                            </button>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
