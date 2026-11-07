import { Button, buttonVariants } from '@/components/ui/button';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Props = {
    bookmarkletUrl: string;
    shareUrl?: string | null;
    addSourceReferenceUrlTemplate?: string | null;
    regenerateShareTokenUrl?: string | null;
};

export default function SourceReferencesCreate({
    bookmarkletUrl,
    shareUrl,
    addSourceReferenceUrlTemplate,
    regenerateShareTokenUrl,
}: Props) {
    const { data, setData, post, processing, errors } = useForm({
        url: '',
    });
    const { post: regenerateShareToken, processing: regeneratingShareToken } = useForm({});
    const shareUrlTemplate = addSourceReferenceUrlTemplate ?? shareUrl;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/source-references');
    }

    function submitRegenerateShareToken(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!regenerateShareTokenUrl) {
            return;
        }

        if (
            !window.confirm(
                'Regenerate the unauthenticated add link? Existing links and saved usages will stop working.',
            )
        ) {
            return;
        }

        regenerateShareToken(regenerateShareTokenUrl);
    }

    return (
        <div className="max-w-xl space-y-5">
            <Link href="/entries" className="underline underline-offset-4">
                Entries
            </Link>

            <section className="space-y-3 border-b pb-4" aria-labelledby="bookmarklet-heading">
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

            {shareUrlTemplate && (
                <div className="space-y-3 border-b pb-4">
                    <Field>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <FieldLabel htmlFor="share-url">Unauthenticated add URL</FieldLabel>

                            {regenerateShareTokenUrl && (
                                <form onSubmit={submitRegenerateShareToken}>
                                    <Button type="submit" variant="outline" disabled={regeneratingShareToken}>
                                        Regenerate
                                    </Button>
                                </form>
                            )}
                        </div>

                        <Input
                            id="share-url"
                            value={shareUrlTemplate}
                            readOnly
                            className="font-mono text-xs"
                            onFocus={(event) => event.currentTarget.select()}
                        />
                        <FieldDescription>Use from another device.</FieldDescription>
                    </Field>
                </div>
            )}

            <form onSubmit={submit}>
                <FieldSet>
                    <FieldGroup>
                        <Field data-invalid={Boolean(errors.url)}>
                            <FieldLabel htmlFor="url">URL</FieldLabel>
                            <Input
                                id="url"
                                name="url"
                                type="url"
                                value={data.url}
                                onChange={(event) => setData('url', event.target.value)}
                                aria-invalid={Boolean(errors.url)}
                            />
                            {errors.url && <FieldError>{errors.url}</FieldError>}
                        </Field>
                    </FieldGroup>

                    <Button type="submit" disabled={processing}>
                        Create
                    </Button>
                </FieldSet>
            </form>
        </div>
    );
}
