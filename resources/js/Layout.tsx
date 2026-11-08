import MobileMenu from '@/components/mobile-menu';
import { Link, usePage } from '@inertiajs/react';
import { Leaf } from 'lucide-react';
import type { PropsWithChildren } from 'react';

export default function Layout({ children }: PropsWithChildren) {
    const { auth } = usePage<{ auth: { user: { username: string } | null } }>().props;
    const { url } = usePage();
    const navigation = [
        { href: '/entries', label: 'Entries' },
        { href: '/source-references', label: 'Sources' },
        { href: '/settings/api-tokens', label: 'Settings' },
    ];
    const links = navigation.map(({ href, label }) => (
        <Link key={href} href={href} aria-current={url.startsWith(href) ? 'page' : undefined}>
            {label}
        </Link>
    ));

    return (
        <div className="flex min-h-dvh flex-col">
            <a href="#main-content" className="skip-link">
                Skip to content
            </a>
            <header className="site-header">
                <div className="site-header-inner">
                    <Link href="/" className="site-brand">
                        <Leaf aria-hidden="true" />
                        Glade Reader
                    </Link>
                    {auth.user ? (
                        <>
                            <nav aria-label="Main navigation" className="site-navigation hidden md:flex">
                                {links}
                            </nav>
                            <MobileMenu label="Main menu">
                                {links}
                                <Link method="post" href="/logout" as="button">
                                    Log out
                                </Link>
                            </MobileMenu>
                        </>
                    ) : (
                        <Link href="/login" className="text-sm text-muted-foreground">
                            Log in
                        </Link>
                    )}
                </div>
            </header>
            <main id="main-content" tabIndex={-1} className="page-content">
                {children}
            </main>
            <footer className="site-footer">
                <span>Glade Reader</span>
                {auth.user && (
                    <Link method="post" href="/logout" as="button">
                        Log out
                    </Link>
                )}
            </footer>
        </div>
    );
}
