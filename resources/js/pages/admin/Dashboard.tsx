import { Head, Link } from '@inertiajs/react';

type Props = {
    metrics: {
        games: number;
        activeGames: number;
        products: number;
        activeProducts: number;
        admins: number;
    };
};

export default function AdminDashboard({ metrics }: Props) {
    const cards = [
        { label: 'Game', value: metrics.games, note: `${metrics.activeGames} aktif` },
        { label: 'Produk', value: metrics.products, note: `${metrics.activeProducts} aktif` },
        { label: 'Administrator', value: metrics.admins, note: 'Akun terdaftar' },
    ];

    return (
        <>
            <Head title="Dashboard Admin" />
            <main className="min-h-screen bg-[#0b0c0f] text-stone-100">
                <header className="border-b border-[#292d35]">
                    <div className="mx-auto flex max-w-6xl items-center justify-between px-5 py-5">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-amber-500">RazzanStore</p>
                            <p className="mt-1 text-sm text-stone-400">Control room</p>
                        </div>
                        <form method="post" action="/admin/logout">
                            <input type="hidden" name="_token" value={(document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''} />
                            <button className="border border-[#343943] px-3 py-2 text-sm text-stone-300 hover:border-amber-500">
                                Keluar
                            </button>
                        </form>
                    </div>
                </header>
                <section className="mx-auto max-w-6xl px-5 py-10">
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-sm text-stone-500">Ringkasan operasional</p>
                            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Dashboard</h1>
                        </div>
                        <Link href="/" className="text-sm text-amber-400 hover:text-amber-300">Lihat storefront →</Link>
                    </div>
                    <div className="mt-8 grid gap-4 sm:grid-cols-3">
                        {cards.map((card) => (
                            <article key={card.label} className="border border-[#2b2f38] bg-[#15171c] p-5">
                                <p className="text-sm text-stone-400">{card.label}</p>
                                <p className="mt-4 text-3xl font-semibold tabular-nums">{card.value}</p>
                                <p className="mt-2 text-xs text-stone-500">{card.note}</p>
                            </article>
                        ))}
                    </div>
                    <div className="mt-8 border border-[#2b2f38] bg-[#15171c] p-6">
                        <h2 className="text-lg font-semibold">Pengelolaan katalog</h2>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-stone-400">
                            API administrasi untuk game, field Player ID/server, produk, dan harga sudah disiapkan.
                            Antarmuka manajemen katalog akan diselesaikan pada iterasi UI berikutnya.
                        </p>
                    </div>
                </section>
            </main>
        </>
    );
}
