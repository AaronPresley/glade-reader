import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

type Props = {
    captureUrl: string | null;
    errorMessage: string | null;
};

export default function Bookmarklet({ captureUrl, errorMessage }: Props) {
    const submitted = useRef(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const error = errorMessage ?? saveError;

    function save() {
        if (!captureUrl) {
            return;
        }

        setSaveError(null);
        router.post(
            captureUrl,
            {},
            {
                onHttpException: () => {
                    setSaveError('Unable to confirm the save. Check Sources before trying again.');
                    return false;
                },
                onNetworkError: () => {
                    setSaveError('Unable to confirm the save. Check your connection and Sources before trying again.');
                    return false;
                },
            },
        );
    }

    useEffect(() => {
        if (!submitted.current) {
            submitted.current = true;
            save();
        }
    }, []);

    return (
        <div className="mx-auto max-w-xl space-y-6">
            <Head title="Save to Glade">
                <meta name="referrer" content="no-referrer" />
            </Head>

            <h1 className="page-title">{error ? 'Unable to save to Glade' : 'Saving to Glade…'}</h1>
            {error ? (
                <>
                    <p role="alert">{error}</p>
                    <div className="flex items-center gap-4">
                        <Link href="/source-references" className="underline underline-offset-4">
                            Sources
                        </Link>
                        {captureUrl && <Button onClick={save}>Try again</Button>}
                    </div>
                </>
            ) : (
                <p role="status">You’ll return to the original page once it’s saved and queued for processing.</p>
            )}
        </div>
    );
}
