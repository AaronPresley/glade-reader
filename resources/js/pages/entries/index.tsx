import { Button, buttonVariants } from '@/components/ui/button';
import { Link } from '@inertiajs/react';

type SourceReferenceSummary = {
    id?: number | string | null;
    url?: string | null;
};

type Entry = {
    id: number;
    type: string;
    status?: string | null;
    updated_at: string | null;
    archived_at?: string | null;
    source_reference_id?: number | string | null;
    source_reference_url?: string | null;
    source_url?: string | null;
    source_reference?: SourceReferenceSummary | null;
    sourceReference?: SourceReferenceSummary | null;
};

type SourceProcessing = {
    processing: number;
    failed: number;
};

type Props = {
    unarchivedEntries: Entry[];
    archivedEntries: Entry[];
    sourceProcessing: SourceProcessing | null;
};

function getSourceUrl(entry: Entry): string {
    return (
        entry.source_reference_url ??
        entry.source_url ??
        entry.source_reference?.url ??
        entry.sourceReference?.url ??
        ''
    );
}

function canRead(entry: Entry): boolean {
    return entry.status === 'COMPLETED';
}

function linkLabel(count: number): string {
    return count === 1 ? 'link' : 'links';
}

function sourceProcessingMessage(sourceProcessing: SourceProcessing): string {
    if (sourceProcessing.processing > 0 && sourceProcessing.failed > 0) {
        return `Processing ${sourceProcessing.processing} ${linkLabel(sourceProcessing.processing)}, ${sourceProcessing.failed} ${linkLabel(sourceProcessing.failed)} have failed`;
    }

    if (sourceProcessing.failed > 0) {
        return `${sourceProcessing.failed} ${linkLabel(sourceProcessing.failed)} have failed processing`;
    }

    return `Processing ${sourceProcessing.processing} ${linkLabel(sourceProcessing.processing)}...`;
}

type EntriesTableProps = {
    entries: Entry[];
};

function EntriesTable({ entries }: EntriesTableProps) {
    return (
        <section>
            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr className="border-b">
                            <th className="py-2 pr-4 font-medium">Status</th>
                            <th className="py-2 pr-4 font-medium">Source URL</th>
                            <th className="py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map((entry) => (
                            <tr key={entry.id} className="border-b align-top">
                                <td className="py-2 pr-4">{entry.status ?? ''}</td>
                                <td className="max-w-xl py-2 pr-4 break-all">{getSourceUrl(entry)}</td>
                                <td className="py-2">
                                    {canRead(entry) ? (
                                        <Link href={`/entries/${entry.id}`} className={buttonVariants({ size: 'sm' })}>
                                            Read
                                        </Link>
                                    ) : (
                                        <Button type="button" size="sm" disabled>
                                            Read
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}

                        {entries.length === 0 && (
                            <tr>
                                <td className="py-4" colSpan={3}>
                                    No entries.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function ArchivedEntriesList({ entries }: { entries: Entry[] }) {
    return (
        <section className="space-y-2">
            <h3 className="text-base font-medium">Archived</h3>
            {entries.length > 0 ? (
                <ul className="divide-y text-sm">
                    {entries.map((entry) => (
                        <li key={entry.id}>
                            <Link
                                href={`/entries/${entry.id}`}
                                className="block max-w-3xl break-all py-2 underline underline-offset-4"
                            >
                                {getSourceUrl(entry) || `Entry ${entry.id}`}
                            </Link>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="py-2 text-sm">No archived entries.</p>
            )}
        </section>
    );
}

export default function EntriesIndex({ unarchivedEntries, archivedEntries, sourceProcessing }: Props) {
    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between gap-4">
                <h2 className="text-lg font-medium">Entries</h2>
                <Link href="/source-references/create" className={buttonVariants()}>
                    Add URL
                </Link>
            </div>

            {sourceProcessing && (
                <section className="flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm">
                    <p>{sourceProcessingMessage(sourceProcessing)}</p>
                    <Link href="/source-references" className="underline underline-offset-4">
                        View
                    </Link>
                </section>
            )}

            <EntriesTable entries={unarchivedEntries} />

            <ArchivedEntriesList entries={archivedEntries} />
        </div>
    );
}
