import { Button } from '@/components/ui/button';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Token = {
    id: number;
    name: string;
    abilities: string[];
    created_at: string | null;
    last_used_at: string | null;
};

export default function ApiTokens({ tokens, apiUrl }: { tokens: Token[]; apiUrl: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '' });
    const { delete: revoke, processing: revoking } = useForm({});
    const { newToken } = usePage().flash as { newToken?: string };
    const [copyStatus, setCopyStatus] = useState('');

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/settings/api-tokens', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setCopyStatus('');
            },
        });
    }

    async function copyToken() {
        try {
            await navigator.clipboard.writeText(newToken!);
            setCopyStatus('Token copied.');
        } catch {
            setCopyStatus('Could not copy automatically. Select the token and copy it manually.');
        }
    }

    return (
        <div className="mx-auto w-full max-w-2xl space-y-8">
            <div className="space-y-4">
                <h1 className="page-title">API tokens</h1>
                <p className="text-sm text-muted-foreground">
                    Give your integrations access to read your sources and entries and add new URLs. Tokens cannot edit
                    or delete data or access users. Keep them private; they remain valid until revoked.
                </p>
                <p className="text-sm text-muted-foreground">
                    Older read-only tokens cannot add URLs. Generate a new token to enable URL submission.
                </p>
            </div>

            <form onSubmit={submit} className="space-y-3 rounded-lg border p-4">
                <Field data-invalid={Boolean(errors.name)}>
                    <FieldLabel htmlFor="token-name">Token name</FieldLabel>
                    <Input
                        id="token-name"
                        name="name"
                        placeholder="e.g. Personal reader"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        required
                        maxLength={255}
                        aria-invalid={Boolean(errors.name)}
                        aria-describedby={errors.name ? 'name-error' : 'name-description'}
                    />
                    {errors.name ? (
                        <FieldError id="name-error">{errors.name}</FieldError>
                    ) : (
                        <FieldDescription id="name-description">Choose a name you’ll recognize later.</FieldDescription>
                    )}
                </Field>
                <Button type="submit" disabled={processing}>
                    {processing ? 'Generating…' : 'Generate token'}
                </Button>
            </form>

            {newToken && (
                <section
                    className="space-y-3 rounded-lg border border-olive-300 bg-olive-50 p-4"
                    aria-labelledby="new-token-heading"
                >
                    <h2 id="new-token-heading" className="section-title">
                        Your new token
                    </h2>
                    <p className="text-sm">Copy it now. You won’t be able to retrieve it after leaving this page.</p>
                    <Field>
                        <FieldLabel htmlFor="new-token">Token secret</FieldLabel>
                        <Input
                            id="new-token"
                            type="password"
                            value={newToken}
                            readOnly
                            autoComplete="off"
                            onFocus={(event) => event.target.select()}
                        />
                    </Field>
                    <Button type="button" variant="outline" onClick={copyToken}>
                        Copy token
                    </Button>
                    <p role="status" className="text-sm">
                        {copyStatus}
                    </p>
                </section>
            )}

            <section className="space-y-3" aria-labelledby="active-tokens-heading">
                <h2 id="active-tokens-heading" className="section-title">
                    Active tokens
                </h2>
                {tokens.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No tokens yet. Generate one to get started.</p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {tokens.map((token) => (
                            <li key={token.id} className="flex items-center justify-between gap-4 p-4">
                                <div className="min-w-0 space-y-1">
                                    <p className="break-words font-medium">{token.name}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {token.abilities.includes('sources:create') || token.abilities.includes('*')
                                            ? 'Read + add URLs'
                                            : 'Read-only'}{' '}
                                        · Created {token.created_at ? new Date(token.created_at).toLocaleString() : '—'}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Last used{' '}
                                        {token.last_used_at ? new Date(token.last_used_at).toLocaleString() : 'never'}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    disabled={revoking}
                                    aria-label={`Revoke ${token.name}`}
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                `Revoke “${token.name}”? Any integration using it will lose access immediately.`,
                                            )
                                        ) {
                                            revoke(`/settings/api-tokens/${token.id}`, { preserveScroll: true });
                                        }
                                    }}
                                >
                                    Revoke
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            <section className="space-y-3 border-t pt-5" aria-labelledby="usage-heading">
                <h2 id="usage-heading" className="section-title">
                    Using the API
                </h2>
                <p className="text-sm text-muted-foreground">
                    Send your token as a Bearer token. Lists return 25 records per page; follow the pagination links for
                    more.
                </p>
                <pre className="break-all whitespace-pre-wrap rounded-lg bg-muted p-3 text-xs">
                    <code>{`curl -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json" ${apiUrl}/entries`}</code>
                </pre>
                <ul className="space-y-1 text-sm [overflow-wrap:anywhere]">
                    <li>
                        <code>GET /api/entries</code> and <code>/api/entries/&#123;id&#125;</code>
                    </li>
                    <li>
                        <code>GET /api/source-references</code> and <code>/api/source-references/&#123;id&#125;</code>
                    </li>
                    <li>
                        <code>POST /api/source-references</code> with JSON{' '}
                        <code>{'{"url":"https://example.com/article"}'}</code>
                    </li>
                </ul>
                <p className="text-sm text-muted-foreground">
                    Adding a URL requires the <code>sources:create</code> permission and returns the new source with
                    status 201. Processing runs in the background; use the source’s ID to check its progress.
                </p>
                <p className="text-sm text-muted-foreground">
                    Entries include Markdown in <code>content</code> and rendered HTML in <code>content_html</code>.
                    Sources include captured HTML in <code>raw_content</code>. Source HTML is untrusted; don’t embed it
                    without sanitizing or sandboxing it.
                </p>
            </section>
        </div>
    );
}
