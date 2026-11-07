import { Button, buttonVariants } from '@/components/ui/button';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type Props = {
    bookmarkletUrl: string;
};

export default function SourceReferencesCreate({ bookmarkletUrl }: Props) {
    const { data, setData, post, processing, errors } = useForm({ url: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/source-references');
    }

    return (
        <div className="max-w-xl space-y-5">
            <Link href="/entries" className="underline underline-offset-4">
                Entries
            </Link>

            <section className="space-y-3" aria-labelledby="add-url-heading">
                <h2 id="add-url-heading" className="text-lg font-medium">
                    Add URL
                </h2>
                <form onSubmit={submit} className="space-y-3">
                    <Field data-invalid={Boolean(errors.url)}>
                        <FieldLabel htmlFor="url">URL</FieldLabel>
                        <Input
                            id="url"
                            name="url"
                            type="url"
                            placeholder="https://example.com/article"
                            value={data.url}
                            onChange={(event) => setData('url', event.target.value)}
                            required
                            maxLength={2048}
                            aria-invalid={Boolean(errors.url)}
                            aria-describedby={errors.url ? 'url-error' : 'url-description'}
                        />
                        {errors.url ? (
                            <FieldError id="url-error">{errors.url}</FieldError>
                        ) : (
                            <FieldDescription id="url-description">
                                Paste a link to save it to Glade for processing.
                            </FieldDescription>
                        )}
                    </Field>
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Adding…' : 'Add URL'}
                    </Button>
                </form>
            </section>

            <section className="space-y-3 border-t pt-5" aria-labelledby="bookmarklet-heading">
                <h2 id="bookmarklet-heading" className="font-medium">
                    Set up bookmarklet
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
