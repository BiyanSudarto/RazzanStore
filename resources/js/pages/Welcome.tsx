export default function Welcome() {
    return (
        <main className="min-h-screen bg-[#0B0C0F] px-6 py-16 text-stone-100">
            <section className="mx-auto max-w-5xl">
                <p className="text-xs font-semibold uppercase tracking-[0.24em] text-amber-500">
                    RazzanStore
                </p>
                <h1 className="mt-5 max-w-3xl text-4xl font-semibold tracking-tight sm:text-5xl">
                    Top-up game yang jelas, cepat, dan aman.
                </h1>
                <p className="mt-5 max-w-2xl text-base leading-7 text-stone-400">
                    Fondasi aplikasi sedang disiapkan. Katalog, checkout, pembayaran,
                    dan pelacakan pesanan akan hadir setelah modul masing-masing selesai diuji.
                </p>
                <div className="mt-8 h-px max-w-xl bg-gradient-to-r from-amber-500/70 to-transparent" />
            </section>
        </main>
    );
}
