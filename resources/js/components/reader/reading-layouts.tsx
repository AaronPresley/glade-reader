import Markdown, { defaultUrlTransform } from 'react-markdown';
import rehypeRaw from 'rehype-raw';
import rehypeSanitize, { defaultSchema } from 'rehype-sanitize';

type ReadingLayoutProps = {
    content: string | null;
    contentUrl: string | null;
    sourceUrl: string | null;
    readingMinutes: number;
    archived: boolean;
};

// Site-specific presentation must not fork article safety or navigation behavior.
// Share sanitization and use the captured article's URL for relative links/images;
// using the saved HN URL here would send readers to the wrong site's resources.
function ArticleBody({ content, contentUrl }: Pick<ReadingLayoutProps, 'content' | 'contentUrl'>) {
    return (
        <article className="book-prose">
            <Markdown
                rehypePlugins={[rehypeRaw, rehypeSanitize]}
                urlTransform={(url, key) => {
                    const safeUrl = defaultUrlTransform(url);

                    // Match the sanitizer's ID prefix for footnotes and other fragments.
                    if (key === 'href' && safeUrl.startsWith('#')) {
                        return `#${defaultSchema.clobberPrefix}${safeUrl.slice(1)}`;
                    }

                    if (safeUrl && contentUrl && (key === 'href' || key === 'src')) {
                        return new URL(safeUrl, contentUrl).href;
                    }

                    return safeUrl;
                }}
            >
                {content ?? ''}
            </Markdown>
        </article>
    );
}

export function DefaultReadingLayout(props: ReadingLayoutProps) {
    return (
        <div className="book-article px-1.5 md:px-0">
            <div className="book-meta">
                {props.sourceUrl && (
                    <a href={props.sourceUrl} target="_blank" rel="noreferrer">
                        {new URL(props.sourceUrl).hostname} ↗
                    </a>
                )}
                <span>{props.readingMinutes} min read</span>
                {props.archived && <span>Archived</span>}
            </div>
            <ArticleBody {...props} />
        </div>
    );
}

// Readers need the publisher's article and a way back to the saved conversation.
// Keep the publisher/content URL separate from the original HN discussion link.
function HackerNewsReadingLayout(props: ReadingLayoutProps) {
    return (
        <div className="book-article px-1.5 md:px-0">
            <div className="book-meta">
                {props.contentUrl && (
                    <a href={props.contentUrl} target="_blank" rel="noreferrer">
                        {new URL(props.contentUrl).hostname} ↗
                    </a>
                )}
                <span>{props.readingMinutes} min read</span>
                {props.archived && <span>Archived</span>}
            </div>
            {props.sourceUrl && (
                <p className="mb-6 text-sm text-muted-foreground">
                    Saved from Hacker News.{' '}
                    <a href={props.sourceUrl} target="_blank" rel="noreferrer" className="underline underline-offset-4">
                        HN discussion ↗
                    </a>
                </p>
            )}
            <ArticleBody {...props} />
        </div>
    );
}

// Layout is selected from the entry's stored plan, independently of its parsing flow.
// New sites can reuse presentation without duplicating extraction or article rendering.
export const readingLayouts: Record<string, typeof DefaultReadingLayout> = {
    default: DefaultReadingLayout,
    'hacker-news': HackerNewsReadingLayout,
};
