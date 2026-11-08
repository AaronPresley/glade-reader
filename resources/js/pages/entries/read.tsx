import { Button } from '@/components/ui/button';
import Markdown, { defaultUrlTransform } from 'react-markdown';
import rehypeRaw from 'rehype-raw';
import rehypeSanitize, { defaultSchema } from 'rehype-sanitize';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type SourceReferenceSummary = {
    id: number;
    url: string;
};

type Entry = {
    id: number;
    content: string | null;
    archived_at?: string | null;
    source_reference: SourceReferenceSummary | null;
};

type Props = {
    entry: Entry;
};

export default function EntriesRead({ entry }: Props) {
    const { post, processing } = useForm({});
    const archived = Boolean(entry.archived_at);

    function toggleArchive(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post(`/entries/${entry.id}/${archived ? 'unarchive' : 'archive'}`);
    }

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-end gap-3">
                <Link href={`/entries/${entry.id}/details`} className="underline underline-offset-4">
                    Details
                </Link>
                {entry.source_reference && (
                    <>
                        <Link
                            href={`/source-references/${entry.source_reference.id}`}
                            className="underline underline-offset-4"
                        >
                            Source Reference
                        </Link>
                        <a
                            href={entry.source_reference.url}
                            target="_blank"
                            rel="noreferrer"
                            className="underline underline-offset-4"
                        >
                            Original URL
                        </a>
                    </>
                )}
                <form onSubmit={toggleArchive}>
                    <Button type="submit" variant="outline" disabled={processing}>
                        {archived ? 'Unarchive' : 'Archive'}
                    </Button>
                </form>
            </div>

            <article className="mx-auto max-w-3xl text-lg leading-8 text-gray-900 [&_a]:underline [&_a]:underline-offset-4 [&_blockquote]:border-l-4 [&_blockquote]:pl-4 [&_blockquote]:italic [&_code]:rounded [&_code]:bg-gray-100 [&_code]:px-1 [&_h1]:mb-6 [&_h1]:text-4xl [&_h1]:font-semibold [&_h2]:mt-10 [&_h2]:mb-4 [&_h2]:text-2xl [&_h2]:font-semibold [&_h3]:mt-8 [&_h3]:mb-3 [&_h3]:text-xl [&_h3]:font-semibold [&_li]:my-1 [&_ol]:my-5 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-5 [&_pre]:overflow-x-auto [&_pre]:rounded [&_pre]:bg-gray-950 [&_pre]:p-4 [&_pre]:text-sm [&_pre]:text-gray-100 [&_ul]:my-5 [&_ul]:list-disc [&_ul]:pl-6">
                <Markdown
                    rehypePlugins={[rehypeRaw, rehypeSanitize]}
                    urlTransform={(url, key) => {
                        const safeUrl = defaultUrlTransform(url);

                        // Match the sanitizer's ID prefix so footnote and other fragment links still work.
                        return key === 'href' && safeUrl.startsWith('#')
                            ? `#${defaultSchema.clobberPrefix}${safeUrl.slice(1)}`
                            : safeUrl;
                    }}
                >
                    {entry.content ?? ''}
                </Markdown>
            </article>
        </div>
    );
}
