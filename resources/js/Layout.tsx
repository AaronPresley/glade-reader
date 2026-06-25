import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

export default function Layout({ children }: PropsWithChildren) {
    return (
        <div className="min-h-dvh flex flex-col">
            <header className="border-b flex flex-row justify-between items-end px-4 py-3 bg-olive-100">
                <h1>Glade Reader</h1>
                <nav>
                    <ul>
                        <li>
                            <Link>Imports</Link>
                        </li>
                    </ul>
                </nav>
            </header>
            <main className="flex-1 flex flex-col px-4 py-3">{children}</main>
            <footer>
                <Link method="post" href="/logout">
                    Logout
                </Link>
            </footer>
        </div>
    );
}
