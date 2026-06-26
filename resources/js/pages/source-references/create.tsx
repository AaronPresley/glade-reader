import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldGroup, FieldLabel, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function SourceReferencesCreate() {
    const { data, setData, post, processing, errors } = useForm({
        url: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/source-references');
    }

    return (
        <div className="max-w-xl space-y-4">
            <Link href="/source-references" className="underline underline-offset-4">
                Sources
            </Link>

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
