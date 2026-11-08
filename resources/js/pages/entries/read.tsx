import { Button } from '@/components/ui/button';
import MobileMenu from '@/components/mobile-menu';
import { DefaultReadingLayout, readingLayouts } from '@/components/reader/reading-layouts';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type SourceReferenceSummary = {
    id: number;
    url: string;
};

type Entry = {
    id: number;
    content: string | null;
    reading: {
        layout: string;
        content_url: string | null;
    };
    archived_at?: string | null;
    source_reference: SourceReferenceSummary | null;
};

type Props = {
    entry: Entry;
};

export default function EntriesRead({ entry }: Props) {
    const { post, processing } = useForm({});
    const archived = Boolean(entry.archived_at);
    const readingMinutes = Math.max(1, Math.ceil((entry.content?.trim().split(/\s+/).length ?? 0) / 230));
    const ReadingLayout = readingLayouts[entry.reading.layout] ?? DefaultReadingLayout;

    function toggleArchive(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post(`/entries/${entry.id}/${archived ? 'unarchive' : 'archive'}`);
    }

    const actions = (
        <div className="page-actions">
            <Link href={`/entries/${entry.id}/details`}>Details</Link>
            {entry.source_reference && (
                <>
                    <Link href={`/source-references/${entry.source_reference.id}`}>Source Reference</Link>
                    <a href={entry.source_reference.url} target="_blank" rel="noreferrer">
                        {entry.reading.layout === 'hacker-news' ? 'HN discussion' : 'Original URL'}
                    </a>
                </>
            )}
            <form onSubmit={toggleArchive}>
                <Button type="submit" variant="outline" disabled={processing}>
                    {archived ? 'Unarchive' : 'Archive'}
                </Button>
            </form>
        </div>
    );

    return (
        <div className="reader">
            <div className="page-toolbar mb-0 min-h-14 py-0 md:min-h-16">
                <Link href="/entries" className="hover:text-foreground">
                    <span aria-hidden="true">← </span>All entries
                </Link>
                <nav aria-label="Article actions" className="hidden md:block">
                    {actions}
                </nav>
                <MobileMenu label="Article menu">{actions}</MobileMenu>
            </div>
            <ReadingLayout
                content={entry.content}
                contentUrl={entry.reading.content_url}
                sourceUrl={entry.source_reference?.url ?? null}
                readingMinutes={readingMinutes}
                archived={archived}
            />
        </div>
    );
}
