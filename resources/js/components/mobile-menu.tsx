import { Button } from '@/components/ui/button';
import { Collapsible } from '@base-ui/react/collapsible';
import { usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';

export default function MobileMenu({ label, children }: { label: string; children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement>(null);
    const { url } = usePage();

    useEffect(() => setOpen(false), [url]);

    return (
        <Collapsible.Root
            open={open}
            onOpenChange={setOpen}
            className="contents md:hidden"
            onKeyDown={(event) => {
                if (event.key === 'Escape' && open) {
                    setOpen(false);
                    trigger.current?.focus();
                }
            }}
        >
            <Collapsible.Trigger
                ref={trigger}
                render={<Button variant="ghost" size="icon" className="ml-auto size-11" />}
                aria-label={label}
            >
                {open ? <X aria-hidden="true" /> : <Menu aria-hidden="true" />}
            </Collapsible.Trigger>
            <Collapsible.Panel className="mobile-menu-panel basis-full">
                <nav aria-label={label}>{children}</nav>
            </Collapsible.Panel>
        </Collapsible.Root>
    );
}
