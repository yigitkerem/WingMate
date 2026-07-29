import { Form, Head } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    return (
        <>
            <Head title="Register" />
            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                <Label
                                    htmlFor="name"
                                    className="font-bold text-slate-700"
                                >
                                    Name
                                </Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    name="name"
                                    placeholder="Full name"
                                    className="h-11 border-slate-200 bg-slate-50 text-slate-950 focus-visible:border-red-800 focus-visible:ring-red-800/15"
                                />
                                <InputError
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label
                                    htmlFor="email"
                                    className="font-bold text-slate-700"
                                >
                                    Email address
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={2}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                    className="h-11 border-slate-200 bg-slate-50 text-slate-950 focus-visible:border-red-800 focus-visible:ring-red-800/15"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label
                                    htmlFor="password"
                                    className="font-bold text-slate-700"
                                >
                                    Password
                                </Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                    className="h-11 border-slate-200 bg-slate-50 text-slate-950 focus-visible:border-red-800 focus-visible:ring-red-800/15"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label
                                    htmlFor="password_confirmation"
                                    className="font-bold text-slate-700"
                                >
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder="Confirm password"
                                    passwordrules={passwordRules}
                                    className="h-11 border-slate-200 bg-slate-50 text-slate-950 focus-visible:border-red-800 focus-visible:ring-red-800/15"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 h-11 w-full bg-linear-to-br from-red-700 to-red-950 font-bold text-white shadow-none hover:from-red-800 hover:to-red-950"
                                tabIndex={5}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                {!processing && <UserPlus className="size-4" />}
                                Create account
                            </Button>
                        </div>

                        <div className="text-center text-sm font-medium text-slate-500">
                            Already have an account?{' '}
                            <TextLink
                                href={login()}
                                tabIndex={6}
                                className="font-bold text-red-800 decoration-red-200 hover:text-red-950"
                            >
                                Log in
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    title: 'Join the journey',
    description: 'Create a passenger profile for tickets and trip history.',
};
