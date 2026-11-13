import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type TrackingOrder = {
    order_code: string;
    game: string;
    product: string;
    nominal: string | null;
    player_data: Record<string, string>;
    amount: number;
    currency: string;
    status: string;
    payment_status: string;
    created_at: string;
    expires_at: string;
    paid_at: string | null;
    payment_attempt: {
        attempt_no: number;
        status: string;
        amount: number;
        currency: string;
    } | null;
};

type Props = {
    trackingToken: string;
};

const currency = (value: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);

const labels: Record<string, string> = {
    pending_payment: 'Menunggu pembayaran',
    paid: 'Pembayaran berhasil',
    failed: 'Pembayaran gagal',
    expired: 'Masa pembayaran berakhir',
    pending: 'Menunggu konfirmasi',
    unknown: 'Sedang memeriksa status pembayaran',
    reconciliation_required: 'Sedang diverifikasi',
};

export default function Tracking({ trackingToken }: Props) {
    const [order, setOrder] = useState<TrackingOrder | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let active = true;
        let timer: ReturnType<typeof setInterval> | undefined;

        const load = async () => {
            try {
                const response = await fetch(
                    `/api/v1/orders/${encodeURIComponent(trackingToken)}`,
                    {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store',
                        referrerPolicy: 'no-referrer',
                    },
                );

                if (!response.ok) {
                    throw new Error(response.status === 404
                        ? 'Nomor pelacakan tidak ditemukan.'
                        : 'Status pesanan belum dapat dimuat.');
                }

                const payload = await response.json() as { data: TrackingOrder };
                if (active) {
                    setOrder(payload.data);
                    setError('');
                    setLoading(false);
                }
            } catch (reason) {
                if (active) {
                    setError(reason instanceof Error ? reason.message : 'Terjadi kesalahan.');
                    setLoading(false);
                }
            }
        };

        void load();
        timer = setInterval(() => {
            if (order?.payment_status !== 'paid' && order?.payment_status !== 'failed'
                && order?.payment_status !== 'expired') {
                void load();
            }
        }, 5000);

        return () => {
            active = false;
            if (timer) clearInterval(timer);
        };
    }, [trackingToken, order?.payment_status]);

    const paymentState = order?.payment_attempt?.status ?? order?.payment_status ?? 'pending';
    const isPaid = order?.payment_status === 'paid';

    return (
        <>
            <Head title="Lacak Pesanan" />
            <main className="min-h-screen bg-[#0b0c0f] px-5 py-12 text-stone-100">
                <section className="mx-auto max-w-2xl">
                    <Link href="/" className="text-xs font-semibold uppercase tracking-[0.22em] text-amber-500">
                        RazzanStore
                    </Link>
                    <div className="mt-8 border border-[#2b2f38] bg-[#15171c] p-6 sm:p-8">
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500">
                            Pelacakan pesanan
                        </p>
                        <h1 className="mt-3 text-2xl font-semibold tracking-tight">Status pesanan kamu</h1>

                        {loading && !order && (
                            <p className="mt-8 text-sm text-stone-400">Memuat informasi pesanan…</p>
                        )}
                        {error && (
                            <div role="alert" className="mt-6 border border-rose-900 bg-rose-950/40 px-4 py-3 text-sm text-rose-300">
                                {error}
                            </div>
                        )}

                        {order && (
                            <>
                                <div className="mt-7 border border-[#333843] bg-[#101216] p-5">
                                    <p className="text-xs text-stone-500">Nomor pesanan</p>
                                    <p className="mt-1 font-mono text-sm text-stone-200">{order.order_code}</p>
                                    <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
                                        <span className="text-sm text-stone-400">Status pembayaran</span>
                                        <span className={`border px-3 py-1.5 text-xs font-semibold ${isPaid ? 'border-emerald-800 text-emerald-300' : 'border-amber-800 text-amber-300'}`}>
                                            {labels[order.payment_status] ?? labels[paymentState] ?? paymentState}
                                        </span>
                                    </div>
                                </div>

                                <div className="mt-7 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <p className="text-xs text-stone-500">Game</p>
                                        <p className="mt-1 text-sm font-medium">{order.game}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs text-stone-500">Produk</p>
                                        <p className="mt-1 text-sm font-medium">{order.product}</p>
                                    </div>
                                    {order.nominal && (
                                        <div>
                                            <p className="text-xs text-stone-500">Nominal</p>
                                            <p className="mt-1 text-sm font-medium">{order.nominal}</p>
                                        </div>
                                    )}
                                    <div>
                                        <p className="text-xs text-stone-500">Total</p>
                                        <p className="mt-1 text-sm font-semibold text-amber-400">{currency(order.amount)}</p>
                                    </div>
                                </div>

                                <div className="mt-7 border-t border-[#2b2f38] pt-5">
                                    <p className="text-xs text-stone-500">Data akun game</p>
                                    <dl className="mt-3 space-y-2">
                                        {Object.entries(order.player_data).map(([key, value]) => (
                                            <div key={key} className="flex flex-wrap justify-between gap-3 text-sm">
                                                <dt className="text-stone-500">{key.replaceAll('_', ' ')}</dt>
                                                <dd className="font-medium text-stone-200">{value}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                </div>

                                {order.payment_status !== 'paid' && (
                                    <p className="mt-6 text-xs leading-5 text-stone-500">
                                        Halaman ini memperbarui status secara berkala. Jangan melakukan pembayaran ulang
                                        saat status masih diperiksa. Gunakan tautan ini untuk melihat pembaruan pesanan.
                                    </p>
                                )}
                                {isPaid && (
                                    <div className="mt-6 border border-emerald-900 bg-emerald-950/30 p-4 text-sm text-emerald-300">
                                        Pembayaran telah terverifikasi. Pesanan menunggu proses fulfillment pada tahap integrasi berikutnya.
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                    <p className="mt-5 text-center text-xs text-stone-600">
                        Jangan bagikan tautan pelacakan ini kepada orang lain.
                    </p>
                </section>
            </main>
        </>
    );
}
