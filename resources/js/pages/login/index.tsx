import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldGroup, FieldLabel, FieldSet } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        username: '',
        password: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        post('/login');
    }

    return (
        <main>
            <form onSubmit={submit}>
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
                    </FieldGroup>

                    <Button type="submit" disabled={processing}>
                        Login
                    </Button>
                </FieldSet>
            </form>
        </main>
    );
}
