import { Button } from '@/components/ui/button';
import { Link, router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import type { ReactNode } from 'react';

type SourceReferenceSummary = {
    id?: number | string | null;
    url?: string | null;
};

type Entry = {
    id: number;
    type: string;
    status?: string | null;
    content: string | null;
    metadata?: unknown;
    created_at?: string | null;
    updated_at: string | null;
    archived_at?: string | null;
    source_reference_id?: number | string | null;
    source_reference_url?: string | null;
    source_url?: string | null;
    source_reference?: SourceReferenceSummary | null;
    sourceReference?: SourceReferenceSummary | null;
};

type Props = {
    entry: Entry;
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

function getSourceReferenceId(entry: Entry): number | string | null {
    return entry.source_reference_id ?? entry.source_reference?.id ?? entry.sourceReference?.id ?? null;
}

function getSourceUrl(entry: Entry): string {
    return (
        entry.source_reference_url ??
        entry.source_url ??
        entry.source_reference?.url ??
        entry.sourceReference?.url ??
        ''
    );
}

function FieldRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <dt className="text-sm font-medium">{label}</dt>
            <dd className="break-all text-sm">{children}</dd>
        </div>
    );
}

export default function EntriesShow({ entry }: Props) {
    const { delete: destroy, processing } = useForm({});
    const sourceReferenceId = getSourceReferenceId(entry);
    const sourceUrl = getSourceUrl(entry);
    const canRead = entry.status === 'COMPLETED';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!window.confirm('Delete this entry?')) {
            return;
        }

        destroy(`/entries/${entry.id}`);
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <Link href="/entries" className="underline underline-offset-4">
                    Entries
                </Link>

                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        disabled={!canRead}
                        onClick={() => router.visit(`/entries/${entry.id}`)}
                    >
                        Read
                    </Button>

                    <form onSubmit={submit}>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            Delete
                        </Button>
                    </form>
                </div>
            </div>

            <dl className="space-y-4">
                <FieldRow label="id">{entry.id}</FieldRow>
                <FieldRow label="source_reference_id">{sourceReferenceId ?? ''}</FieldRow>
                <FieldRow label="source_url">
                    {sourceUrl ? (
                        <a href={sourceUrl} target="_blank" rel="noreferrer" className="underline underline-offset-4">
                            {sourceUrl}
                        </a>
                    ) : (
                        ''
                    )}
                </FieldRow>
                <FieldRow label="type">{entry.type}</FieldRow>
                <FieldRow label="status">{entry.status ?? ''}</FieldRow>
                <FieldRow label="metadata">
                    <pre className="max-h-96 overflow-auto whitespace-pre-wrap rounded border p-3 text-xs">
                        {formatPreValue(entry.metadata)}
                    </pre>
                </FieldRow>
                <FieldRow label="created_at">{entry.created_at ?? ''}</FieldRow>
                <FieldRow label="updated_at">{entry.updated_at ?? ''}</FieldRow>
                <FieldRow label="archived_at">{entry.archived_at ?? ''}</FieldRow>
            </dl>

            <section className="space-y-2">
                <h2 className="text-lg font-medium">Content</h2>
                <pre className="max-h-[32rem] overflow-auto whitespace-pre-wrap rounded border p-3 text-sm">
                    {entry.content ?? ''}
                </pre>
            </section>
        </div>
    );
}
