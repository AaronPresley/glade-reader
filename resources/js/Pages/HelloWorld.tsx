import { Button } from "@/components/ui/button";

export default function HelloWorld() {
    return (
        <main className="flex min-h-screen flex-col items-start justify-center gap-4 p-8">
            <h1 className="text-2xl font-semibold">Sup World</h1>
            <Button>Base UI Button</Button>
        </main>
    );
}
