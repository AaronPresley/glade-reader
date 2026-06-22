import { Link, usePage } from '@inertiajs/react';

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

            <Link method='post' href='/logout'>Logout</Link>
            <p>{username}</p>
        </main>
    );
}
