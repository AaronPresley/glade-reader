import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldGroup, FieldLabel, FieldSet } from '@/components/ui/field';
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
        <div className="auth-page">
            <div className="space-y-3">
                <h1 className="page-title">A place to read.</h1>
                <p className="font-heading text-lg text-muted-foreground">
                    Create your account and make yourself at home.
                </p>
            </div>
            <form onSubmit={submit} className="space-y-4">
                <FieldSet>
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
                            {errors.password_confirmation && <FieldError>{errors.password_confirmation}</FieldError>}
                        </Field>
                    </FieldGroup>

                    <Button type="submit" disabled={processing}>
                        Register
                    </Button>
                </FieldSet>
            </form>
        </div>
    );
}
