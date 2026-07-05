import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

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
    title: string;
    entries: Entry[];
    emptyMessage: string;
    archivingEntryId: number | null;
    onArchive: (entry: Entry) => void;
};

function EntriesTable({ title, entries, emptyMessage, archivingEntryId, onArchive }: EntriesTableProps) {
    return (
        <section className="space-y-2">
            <h3 className="text-base font-medium">{title}</h3>
            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr className="border-b">
                            <th className="py-2 pr-4 font-medium">Type</th>
                            <th className="py-2 pr-4 font-medium">Status</th>
                            <th className="py-2 pr-4 font-medium">Source URL</th>
                            <th className="py-2 pr-4 font-medium">Updated</th>
                            <th className="py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map((entry) => {
                            const archived = Boolean(entry.archived_at);

                            return (
                                <tr key={entry.id} className="border-b align-top">
                                    <td className="py-2 pr-4">{entry.type}</td>
                                    <td className="py-2 pr-4">{entry.status ?? ''}</td>
                                    <td className="max-w-xl py-2 pr-4 break-all">{getSourceUrl(entry)}</td>
                                    <td className="py-2 pr-4">{entry.updated_at ?? ''}</td>
                                    <td className="py-2">
                                        <div className="flex flex-wrap items-center gap-3">
                                            {canRead(entry) ? (
                                                <Link href={`/entries/${entry.id}`} className="underline underline-offset-4">
                                                    Read
                                                </Link>
                                            ) : (
                                                <span className="text-gray-400">Read</span>
                                            )}

                                            <Link
                                                href={`/entries/${entry.id}/details`}
                                                className="underline underline-offset-4"
                                            >
                                                Details
                                            </Link>

                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={archived || archivingEntryId === entry.id}
                                                onClick={() => onArchive(entry)}
                                            >
                                                {archived ? 'Archived' : 'Archive'}
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            );
                        })}

                        {entries.length === 0 && (
                            <tr>
                                <td className="py-4" colSpan={5}>
                                    {emptyMessage}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export default function EntriesIndex({ unarchivedEntries, archivedEntries, sourceProcessing }: Props) {
    const [archivingEntryId, setArchivingEntryId] = useState<number | null>(null);

    function archiveEntry(entry: Entry) {
        if (!window.confirm('Archive this entry?')) {
            return;
        }

        setArchivingEntryId(entry.id);

        router.post(
            `/entries/${entry.id}/archive`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setArchivingEntryId(null),
            },
        );
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between gap-4">
                <h2 className="text-lg font-medium">Entries</h2>
                <Link href="/source-references/create" className="underline underline-offset-4">
                    Create
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

            <EntriesTable
                title="Unarchived"
                entries={unarchivedEntries}
                emptyMessage="No unarchived entries."
                archivingEntryId={archivingEntryId}
                onArchive={archiveEntry}
            />

            <EntriesTable
                title="Archived"
                entries={archivedEntries}
                emptyMessage="No archived entries."
                archivingEntryId={archivingEntryId}
                onArchive={archiveEntry}
            />
        </div>
    );
}
