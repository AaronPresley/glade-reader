import { Button } from '@/components/ui/button';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import type { ReactNode } from 'react';

type SourceReference = {
    id: number;
    user_id: number;
    url: string;
    status: string;
    raw_content: string | null;
    screenshot_path: string | null;
    metadata: unknown;
    created_at: string | null;
    updated_at: string | null;
    image_url: string | null;
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
    const { delete: destroy, processing } = useForm({});

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!window.confirm('Delete this source reference?')) {
            return;
        }

        destroy(`/source-references/${sourceReference.id}`);
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <Link href="/source-references" className="underline underline-offset-4">
                    Sources
                </Link>

                <form onSubmit={submit}>
                    <Button type="submit" variant="destructive" disabled={processing}>
                        Delete
                    </Button>
                </form>
            </div>

            <dl className="space-y-4">
                <FieldRow label="id">{sourceReference.id}</FieldRow>
                <FieldRow label="user_id">{sourceReference.user_id}</FieldRow>
                <FieldRow label="url">
                    <a href={sourceReference.url} target="_blank" rel="noreferrer" className="underline underline-offset-4">
                        {sourceReference.url}
                    </a>
                </FieldRow>
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
