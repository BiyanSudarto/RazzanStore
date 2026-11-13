import { Head, useForm } from '@inertiajs/react';

export default function AdminLogin() {
    const form = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/admin/login');
    }

    return (
        <>
            <Head title="Admin Login" />
            <main className="flex min-h-screen items-center justify-center bg-[#0b0c0f] px-5 py-12 text-stone-100">
                <section className="w-full max-w-md">
                    <a href="/" className="text-xs font-semibold uppercase tracking-[0.24em] text-amber-500">
                        RazzanStore
                    </a>
                    <div className="mt-8 border border-[#2b2f38] bg-[#15171c] p-7 sm:p-9">
                        <p className="text-xs font-medium uppercase tracking-[0.18em] text-stone-500">
                            Restricted area
                        </p>
                        <h1 className="mt-3 text-2xl font-semibold tracking-tight">Administrator masuk</h1>
                        <p className="mt-2 text-sm leading-6 text-stone-400">
                            Gunakan akun administrator yang telah dibuat oleh pemilik sistem.
                        </p>

                        <form onSubmit={submit} className="mt-8 space-y-5">
                            <div>
                                <label htmlFor="email" className="mb-2 block text-sm text-stone-300">Email</label>
                                <input
                                    id="email"
                                    type="email"
                                    autoComplete="username"
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                    className="w-full border border-[#343943] bg-[#0f1115] px-3 py-3 text-sm outline-none transition focus:border-amber-500"
                                    required
                                />
                                {form.errors.email && <p className="mt-2 text-xs text-red-400">{form.errors.email}</p>}
                            </div>
                            <div>
                                <label htmlFor="password" className="mb-2 block text-sm text-stone-300">Password</label>
                                <input
                                    id="password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                    className="w-full border border-[#343943] bg-[#0f1115] px-3 py-3 text-sm outline-none transition focus:border-amber-500"
                                    required
                                />
                                {form.errors.password && <p className="mt-2 text-xs text-red-400">{form.errors.password}</p>}
                            </div>
                            <label className="flex items-center gap-2 text-sm text-stone-400">
                                <input
                                    type="checkbox"
                                    checked={form.data.remember}
                                    onChange={(event) => form.setData('remember', event.target.checked)}
                                    className="accent-amber-500"
                                />
                                Ingat sesi di perangkat ini
                            </label>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="w-full bg-amber-500 px-4 py-3 text-sm font-semibold text-[#17120a] transition hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {form.processing ? 'Memeriksa...' : 'Masuk ke panel'}
                            </button>
                        </form>
                        <div className="mt-7 border-t border-[#2b2f38] pt-5 text-xs leading-5 text-stone-500">
                            Akses dicatat untuk kebutuhan audit. Jangan gunakan perangkat publik untuk menyimpan sesi.
                        </div>
                    </div>
                </section>
            </main>
        </>
    );
}
