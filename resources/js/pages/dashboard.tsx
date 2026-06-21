import { usePage } from '@inertiajs/react';

type PageProps = {
    auth?: {
        user?: {
            username?: string | null;
        } | null;
    };
};

export default function Dashboard() {
    const { auth } = usePage<PageProps>().props;
    const username = auth?.user?.username ?? '';

    return (
        <main className="p-8">
            <p>{username}</p>
        </main>
    );
}
