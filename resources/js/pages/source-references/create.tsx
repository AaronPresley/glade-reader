import { buttonVariants } from '@/components/ui/button';
import { Link } from '@inertiajs/react';

type Props = {
    bookmarkletUrl: string;
};

export default function SourceReferencesCreate({ bookmarkletUrl }: Props) {
    return (
        <div className="max-w-xl space-y-5">
            <Link href="/entries" className="underline underline-offset-4">
                Entries
            </Link>

            <section className="space-y-3" aria-labelledby="bookmarklet-heading">
                <h2 id="bookmarklet-heading" className="font-medium">
                    Save from your browser
                </h2>
                <a
                    ref={(link) => {
                        // React blocks javascript: href props; this native link is intentionally a bookmarklet.
                        link?.setAttribute(
                            'href',
                            `javascript:void(function(){if(!/^https?:$/.test(location.protocol)){alert('Glade can only save HTTP or HTTPS pages.');return;}location.assign(${JSON.stringify(bookmarkletUrl)}+'&url='+encodeURIComponent(location.href));}())`,
                        );
                    }}
                    draggable
                    onClick={(event) => event.preventDefault()}
                    className={buttonVariants({ variant: 'outline' })}
                    title="Drag this link to your bookmarks bar"
                >
                    Save to Glade
                </a>
                <p className="text-sm text-muted-foreground">
                    Drag this link to your bookmarks bar. Click the bookmark on any page to save it to Glade, then
                    return to that page. If you’re signed out, you’ll log in first.
                </p>
                <p className="text-sm text-muted-foreground">
                    This bookmark is personal to your account. Don’t share it.
                </p>
            </section>
        </div>
    );
}
