import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

export default function Layout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-dvh flex flex-col">
            <header className="border-b flex flex-row justify-between items-end px-4 py-3 bg-olive-100">
                <h1>
                    <Link href="/">Glade Reader</Link>
                </h1>
                <nav>
                    <ul className="flex gap-3">
                        <li>
                            <Link href="/entries">Entries</Link>
                        </li>
                        <li>
                            <Link href="/source-references">Sources</Link>
                        </li>
                    </ul>
                </nav>
            </header>
            <main className="flex-1 flex flex-col px-4 py-3">{children}</main>
            <footer className="px-4 py-3 bg-gray-200 text-gray-600">
                <Link method="post" href="/logout">
                    Logout
                </Link>
            </footer>
        </div>
    );
}
