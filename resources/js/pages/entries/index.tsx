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
    source_reference_id?: number | string | null;
    source_reference_url?: string | null;
    source_url?: string | null;
    source_reference?: SourceReferenceSummary | null;
    sourceReference?: SourceReferenceSummary | null;
};

type Props = {
    entries: Entry[];
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

export default function EntriesIndex({ entries }: Props) {
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <h2 className="text-lg font-medium">Entries</h2>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr className="border-b">
                            <th className="py-2 pr-4 font-medium">Type</th>
                            <th className="py-2 pr-4 font-medium">Status</th>
                            <th className="py-2 pr-4 font-medium">Source URL</th>
                            <th className="py-2 pr-4 font-medium">Updated</th>
                            <th className="py-2 font-medium">Links</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map((entry) => (
                            <tr key={entry.id} className="border-b align-top">
                                <td className="py-2 pr-4">{entry.type}</td>
                                <td className="py-2 pr-4">{entry.status ?? ''}</td>
                                <td className="max-w-xl py-2 pr-4 break-all">{getSourceUrl(entry)}</td>
                                <td className="py-2 pr-4">{entry.updated_at ?? ''}</td>
                                <td className="py-2">
                                    <Link href={`/entries/${entry.id}`} className="underline underline-offset-4">
                                        Show
                                    </Link>
                                    {canRead(entry) ? (
                                        <Link
                                            href={`/entries/${entry.id}/read`}
                                            className="ml-3 underline underline-offset-4"
                                        >
                                            Read
                                        </Link>
                                    ) : (
                                        <span className="ml-3 text-gray-400">Read</span>
                                    )}
                                </td>
                            </tr>
                        ))}

                        {entries.length === 0 && (
                            <tr>
                                <td className="py-4" colSpan={5}>
                                    No entries.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
