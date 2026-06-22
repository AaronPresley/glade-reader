import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
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
                <FieldSet>
                    <FieldLegend>Sup first user.</FieldLegend>
                    <FieldGroup>
                        <Field data-invalid={Boolean(errors.username)}>
                            <FieldLabel htmlFor="username">Username</FieldLabel>
                            <Input
                                id="username"
                                name="username"
                                value={data.username}
                                onChange={(event) => setData('username', event.target.value)}
                                aria-invalid={Boolean(errors.username)}
                            />
                            {errors.username && <FieldError>{errors.username}</FieldError>}
                        </Field>

                        <Field data-invalid={Boolean(errors.email)}>
                            <FieldLabel htmlFor="email">Email</FieldLabel>
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                aria-invalid={Boolean(errors.email)}
                            />
                            {errors.email && <FieldError>{errors.email}</FieldError>}
                        </Field>

                        <Field data-invalid={Boolean(errors.password)}>
                            <FieldLabel htmlFor="password">Password</FieldLabel>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                value={data.password}
                                onChange={(event) => setData('password', event.target.value)}
                                aria-invalid={Boolean(errors.password)}
                            />
                            {errors.password && <FieldError>{errors.password}</FieldError>}
                        </Field>

                        <Field data-invalid={Boolean(errors.password_confirmation)}>
                            <FieldLabel htmlFor="password_confirmation">Confirm password</FieldLabel>
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                value={data.password_confirmation}
                                onChange={(event) => setData('password_confirmation', event.target.value)}
                                aria-invalid={Boolean(errors.password_confirmation)}
                            />
                            {errors.password_confirmation && (
                                <FieldError>{errors.password_confirmation}</FieldError>
                            )}
                        </Field>
                    </FieldGroup>

                    <Button type="submit" disabled={processing}>
                        Register
                    </Button>
                </FieldSet>
            </form>
        </main>
    );
}
