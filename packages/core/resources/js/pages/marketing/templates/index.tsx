import { Head, Link, router } from '@inertiajs/react';
import { LayoutTemplate, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import EmailTemplateController from '@/actions/Gerenuk/Marketing/Http/Controllers/EmailTemplateController';
import { Button } from '@/components/ui/button';
import { index as campaignsIndex } from '@/routes/marketing/campaigns';
import {
    create,
    edit,
    index as templatesIndex,
} from '@/routes/marketing/templates';
import { ConfirmDelete } from '@marketing/components/confirm-delete';
import { PageBody, PageHeader } from '@marketing/components/page-header';
import {
    EmailThumbnail,
    templateHtml,
} from '@marketing/components/template-picker';
import { useCan } from '@marketing/hooks/use-workspace';
import { formatDate } from '@marketing/lib/format';

type SavedTemplate = {
    id: number;
    name: string;
    description: string | null;
    subject: string | null;
    html: string;
    updated_at: string | null;
};

type Starter = {
    key: string;
    name: string;
    description: string;
    editor: string;
    editor_state: unknown;
    html: string | null;
};

export default function TemplatesIndex({
    templates,
    starters,
}: {
    templates: SavedTemplate[];
    starters: Starter[];
}) {
    const canEdit = useCan()('campaigns.manage');

    return (
        <>
            <Head title="Templates" />

            <PageBody>
                <PageHeader
                    title="Templates"
                    description="Reusable designs to start campaigns and automation emails from."
                    actions={
                        canEdit && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus />
                                    New template
                                </Link>
                            </Button>
                        )
                    }
                />

                <section className="space-y-3">
                    <div className="space-y-1">
                        <h2 className="text-base font-semibold">
                            Your templates
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            An email copies its template, so editing one never
                            changes emails already made from it.
                        </p>
                    </div>
                    {templates.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed px-6 py-12 text-center">
                            <LayoutTemplate className="size-5 text-muted-foreground" />
                            <p className="max-w-md text-sm text-muted-foreground">
                                No templates yet. Customise a starter below, or
                                save any campaign as a template from its page.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {templates.map((template) => (
                                <TemplateCard
                                    key={template.id}
                                    html={template.html}
                                    title={template.name}
                                    detail={
                                        template.description ??
                                        template.subject ??
                                        (template.updated_at &&
                                            `Edited ${formatDate(template.updated_at)}`)
                                    }
                                    href={canEdit ? edit(template.id) : null}
                                    actions={
                                        canEdit && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={campaignsIndex({
                                                            query: {
                                                                new: 1,
                                                                template: `saved:${template.id}`,
                                                            },
                                                        })}
                                                    >
                                                        Use
                                                    </Link>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            EmailTemplateController.duplicate.url(
                                                                template.id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    Duplicate
                                                </Button>
                                                <ConfirmDelete
                                                    url={EmailTemplateController.destroy.url(
                                                        template.id,
                                                    )}
                                                    title={`Delete ${template.name}?`}
                                                    description="Emails already made from it keep their content."
                                                />
                                            </>
                                        )
                                    }
                                />
                            ))}
                        </div>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="space-y-1">
                        <h2 className="text-base font-semibold">Starters</h2>
                        <p className="text-sm text-muted-foreground">
                            Built-in designs. Customise one to make it your own.
                        </p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {starters.map((starter) => (
                            <TemplateCard
                                key={starter.key}
                                html={templateHtml(starter)}
                                title={starter.name}
                                detail={starter.description}
                                href={
                                    canEdit
                                        ? create({
                                              query: { starter: starter.key },
                                          })
                                        : null
                                }
                                actions={
                                    canEdit && (
                                        <>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={campaignsIndex({
                                                        query: {
                                                            new: 1,
                                                            template: `starter:${starter.key}`,
                                                        },
                                                    })}
                                                >
                                                    Use
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={create({
                                                        query: {
                                                            starter:
                                                                starter.key,
                                                        },
                                                    })}
                                                >
                                                    Customise
                                                </Link>
                                            </Button>
                                        </>
                                    )
                                }
                            />
                        ))}
                    </div>
                </section>
            </PageBody>
        </>
    );
}

function TemplateCard({
    html,
    title,
    detail,
    href,
    actions,
}: {
    html: string;
    title: string;
    detail: string | null;
    href: ReturnType<typeof create> | null;
    actions?: ReactNode;
}) {
    return (
        <div className="group relative flex flex-col overflow-hidden rounded-xl border bg-card shadow-xs transition-[border-color,box-shadow] hover:border-foreground/20 hover:shadow-sm">
            <EmailThumbnail html={html} className="border-b" />
            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="min-w-0">
                    {href ? (
                        <Link
                            href={href}
                            className="text-sm font-semibold after:absolute after:inset-0"
                        >
                            {title}
                        </Link>
                    ) : (
                        <p className="text-sm font-semibold">{title}</p>
                    )}
                    {detail && (
                        <p className="truncate text-13 text-muted-foreground">
                            {detail}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="relative z-10 -mx-2 mt-auto flex flex-wrap">
                        {actions}
                    </div>
                )}
            </div>
        </div>
    );
}

TemplatesIndex.layout = {
    breadcrumbs: [{ title: 'Templates', href: templatesIndex() }],
};
