import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        username: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/register');
    }

    return (
        <main className="p-8">
            <form onSubmit={submit} className="max-w-sm space-y-4">
                <div className="space-y-1">
                    <Label htmlFor="username" className="block text-sm font-medium">
                        Username
                    </Label>
                    <Input
                        id="username"
                        name="username"
                        value={data.username}
                        onChange={(event) => setData('username', event.target.value)}
                        aria-invalid={Boolean(errors.username)}
                    />
                    {errors.username && <p className="text-sm text-destructive">{errors.username}</p>}
                </div>

                <div className="space-y-1">
                    <Label htmlFor="email" className="block text-sm font-medium">
                        Email
                    </Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        aria-invalid={Boolean(errors.email)}
                    />
                    {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                </div>

                <div className="space-y-1">
                    <Label htmlFor="password" className="block text-sm font-medium">
                        Password
                    </Label>
                    <Input
                        id="password"
                        name="password"
                        type="password"
                        value={data.password}
                        onChange={(event) => setData('password', event.target.value)}
                        aria-invalid={Boolean(errors.password)}
                    />
                    {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                </div>

                <div className="space-y-1">
                    <Label htmlFor="password_confirmation" className="block text-sm font-medium">
                        Confirm password
                    </Label>
                    <Input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        value={data.password_confirmation}
                        onChange={(event) => setData('password_confirmation', event.target.value)}
                        aria-invalid={Boolean(errors.password_confirmation)}
                    />
                    {errors.password_confirmation && (
                        <p className="text-sm text-destructive">{errors.password_confirmation}</p>
                    )}
                </div>

                <Button type="submit" disabled={processing}>
                    Register
                </Button>
            </form>
        </main>
    );
}
