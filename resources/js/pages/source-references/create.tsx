import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type Props = {
    shareUrl?: string | null;
    addSourceReferenceUrlTemplate?: string | null;
    regenerateShareTokenUrl?: string | null;
};

export default function SourceReferencesCreate({
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

        if (!window.confirm('Regenerate the unauthenticated add link? Existing links and saved usages will stop working.')) {
            return;
        }

        regenerateShareToken(regenerateShareTokenUrl);
    }

    return (
        <div className="max-w-xl space-y-5">
            <Link href="/entries" className="underline underline-offset-4">
                Entries
            </Link>

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
