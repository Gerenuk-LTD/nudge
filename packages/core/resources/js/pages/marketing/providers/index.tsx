import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    create,
    index as providersIndex,
    show,
} from '@/routes/marketing/providers';
import { Cell, DataTable, EmptyRow } from '@marketing/components/data-table';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import { formatDateTime } from '@marketing/lib/format';

type ProviderRow = {
    id: number;
    name: string;
    type: string;
    from_email: string | null;
    last_sent_at: string | null;
    last_webhook_at: string | null;
    receives_webhooks: boolean;
};

export default function ProvidersIndex({
    providers,
}: {
    providers: ProviderRow[];
}) {
    return (
        <>
            <Head title="Providers" />

            <PageBody>
                <PageHeader
                    title="Sending providers"
                    description="Campaigns send through your own provider account. Connect one or more."
                    actions={
                        <Button asChild>
                            <Link href={create()}>
                                <Plus />
                                Connect provider
                            </Link>
                        </Button>
                    }
                />

                <DataTable
                    headers={['Name', 'Provider', 'Last send', 'Last webhook']}
                    empty={
                        providers.length === 0 && (
                            <EmptyRow message="No providers yet. Connect one to start sending." />
                        )
                    }
                >
                    {providers.map((provider) => (
                        <tr key={provider.id}>
                            <Cell>
                                <Link
                                    href={show(provider.id)}
                                    className="font-medium hover:underline"
                                >
                                    {provider.name}
                                </Link>
                                {provider.from_email && (
                                    <p className="text-xs text-muted-foreground">
                                        {provider.from_email}
                                    </p>
                                )}
                            </Cell>
                            <Cell>{provider.type}</Cell>
                            <Cell className="text-muted-foreground">
                                {formatDateTime(provider.last_sent_at) ??
                                    'Never'}
                            </Cell>
                            <Cell className="text-muted-foreground">
                                {provider.receives_webhooks ? (
                                    (formatDateTime(provider.last_webhook_at) ??
                                    'None received yet')
                                ) : (
                                    <Badge variant="outline">No webhooks</Badge>
                                )}
                            </Cell>
                        </tr>
                    ))}
                </DataTable>
            </PageBody>
        </>
    );
}

ProvidersIndex.layout = {
    breadcrumbs: [{ title: 'Providers', href: providersIndex() }],
};
