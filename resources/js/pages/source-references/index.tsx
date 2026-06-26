import { Link } from '@inertiajs/react';

type SourceReference = {
    id: number;
    url: string;
    status: string;
    created_at: string | null;
};

type Props = {
    sourceReferences: SourceReference[];
};

export default function SourceReferencesIndex({ sourceReferences }: Props) {
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <h2 className="text-lg font-medium">Sources</h2>
                <Link href="/source-references/create" className="underline underline-offset-4">
                    Create
                </Link>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-left text-sm">
                    <thead>
                        <tr className="border-b">
                            <th className="py-2 pr-4 font-medium">URL</th>
                            <th className="py-2 pr-4 font-medium">Status</th>
                            <th className="py-2 pr-4 font-medium">Created</th>
                            <th className="py-2 font-medium">Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sourceReferences.map((sourceReference) => (
                            <tr key={sourceReference.id} className="border-b align-top">
                                <td className="max-w-xl py-2 pr-4 break-all">{sourceReference.url}</td>
                                <td className="py-2 pr-4">{sourceReference.status}</td>
                                <td className="py-2 pr-4">{sourceReference.created_at ?? ''}</td>
                                <td className="py-2">
                                    <Link
                                        href={`/source-references/${sourceReference.id}`}
                                        className="underline underline-offset-4"
                                    >
                                        Show
                                    </Link>
                                </td>
                            </tr>
                        ))}

                        {sourceReferences.length === 0 && (
                            <tr>
                                <td className="py-4" colSpan={4}>
                                    No sources.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
