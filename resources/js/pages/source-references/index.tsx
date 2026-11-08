import { buttonVariants } from '@/components/ui/button';
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
        <div className="space-y-8">
            <div className="flex items-center justify-between gap-4">
                <h1 className="page-title">Sources</h1>
                <Link href="/source-references/create" className={buttonVariants()}>
                    Add URL
                </Link>
            </div>

            <div className="overflow-x-auto">
                <table className="data-table">
                    <thead>
                        <tr className="border-b">
                            <th className="pr-4">URL</th>
                            <th className="hidden pr-4 md:table-cell">Status</th>
                            <th className="hidden pr-4 md:table-cell">Created</th>
                            <th>Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        {sourceReferences.map((sourceReference) => (
                            <tr key={sourceReference.id} className="border-b align-top">
                                <td className="max-w-xl pr-4 break-all">
                                    {sourceReference.url}
                                    <span className="mt-2 block text-xs text-muted-foreground md:hidden">
                                        {sourceReference.status}
                                    </span>
                                    <span className="mt-1 block text-xs text-muted-foreground md:hidden">
                                        {sourceReference.created_at ?? ''}
                                    </span>
                                </td>
                                <td className="hidden pr-4 text-xs text-muted-foreground md:table-cell">
                                    {sourceReference.status}
                                </td>
                                <td className="hidden pr-4 text-xs text-muted-foreground md:table-cell">
                                    {sourceReference.created_at ?? ''}
                                </td>
                                <td>
                                    <Link
                                        href={`/source-references/${sourceReference.id}`}
                                        className="inline-flex min-h-11 items-center underline underline-offset-4 md:min-h-0"
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
