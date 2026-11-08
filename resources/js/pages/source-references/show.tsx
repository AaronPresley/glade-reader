import { Button } from '@/components/ui/button';
import MobileMenu from '@/components/mobile-menu';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import type { ReactNode } from 'react';

type SourceReference = {
    id: number;
    user_id: number;
    url: string;
    content_url: string | null;
    status: string;
    raw_content: string | null;
    screenshot_path: string | null;
    metadata: unknown;
    created_at: string | null;
    updated_at: string | null;
    image_url: string | null;
    article_entry?: {
        id: number;
        status?: string | null;
        type: string;
        updated_at: string | null;
    } | null;
};

type Props = {
    sourceReference: SourceReference;
};

function formatPreValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    if (typeof value === 'string') {
        try {
            return JSON.stringify(JSON.parse(value), null, 2);
        } catch {
            return value;
        }
    }

    return JSON.stringify(value, null, 2);
}

function FieldRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <dt className="text-sm font-medium">{label}</dt>
            <dd className="break-all text-sm">{children}</dd>
        </div>
    );
}

export default function SourceReferencesShow({ sourceReference }: Props) {
    const { delete: destroy, processing: deleting } = useForm({});
    const { post: generateArticle, processing: generatingArticle } = useForm({});
    const articleEntry = sourceReference.article_entry ?? null;
    const canGenerateArticle = sourceReference.status === 'COMPLETED';

    function submitDelete(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!window.confirm('Delete this source reference?')) {
            return;
        }

        destroy(`/source-references/${sourceReference.id}`);
    }

    function submitGenerateArticle(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        generateArticle(`/source-references/${sourceReference.id}/entries/article`);
    }

    const actions = (
        <div className="page-actions">
            {articleEntry && (
                <Link href={`/entries/${articleEntry.id}/details`} className="text-sm underline underline-offset-4">
                    Article{articleEntry.status ? `: ${articleEntry.status}` : ''}
                </Link>
            )}

            <form onSubmit={submitGenerateArticle}>
                <Button type="submit" disabled={generatingArticle || !canGenerateArticle}>
                    {articleEntry ? 'Regenerate article' : 'Generate article'}
                </Button>
            </form>

            <form onSubmit={submitDelete}>
                <Button type="submit" variant="destructive" disabled={deleting}>
                    Delete
                </Button>
            </form>
        </div>
    );

    return (
        <div className="space-y-8">
            <div className="page-toolbar">
                <Link href="/source-references" className="hover:text-foreground">
                    ← Sources
                </Link>
                <nav aria-label="Source actions" className="hidden md:block">
                    {actions}
                </nav>
                <MobileMenu label="Source menu">{actions}</MobileMenu>
            </div>
            <h1 className="page-title">Source details</h1>
            <dl className="detail-fields">
                <FieldRow label="id">{sourceReference.id}</FieldRow>
                <FieldRow label="user_id">{sourceReference.user_id}</FieldRow>
                <FieldRow label="url">
                    <a
                        href={sourceReference.url}
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-4"
                    >
                        {sourceReference.url}
                    </a>
                </FieldRow>
                {sourceReference.content_url && sourceReference.content_url !== sourceReference.url && (
                    <FieldRow label="Content URL (HTML and screenshot)">
                        <a
                            href={sourceReference.content_url}
                            target="_blank"
                            rel="noreferrer"
                            className="underline underline-offset-4"
                        >
                            {sourceReference.content_url}
                        </a>
                    </FieldRow>
                )}
                <FieldRow label="status">{sourceReference.status}</FieldRow>
                <FieldRow label="raw_content">
                    <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded border p-3 text-xs">
                        {formatPreValue(sourceReference.raw_content)}
                    </pre>
                </FieldRow>
                <FieldRow label="screenshot_path">
                    {sourceReference.screenshot_path && sourceReference.image_url ? (
                        <a
                            href={sourceReference.image_url}
                            target="_blank"
                            rel="noreferrer"
                            className="underline underline-offset-4"
                        >
                            {sourceReference.screenshot_path}
                        </a>
                    ) : (
                        ''
                    )}
                </FieldRow>
                <FieldRow label="metadata">
                    <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded border p-3 text-xs">
                        {formatPreValue(sourceReference.metadata)}
                    </pre>
                </FieldRow>
                <FieldRow label="created_at">{sourceReference.created_at ?? ''}</FieldRow>
                <FieldRow label="updated_at">{sourceReference.updated_at ?? ''}</FieldRow>
            </dl>
        </div>
    );
}
