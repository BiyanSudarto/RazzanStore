import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';

type Game = {
    id: number;
    slug: string;
    name: string;
    is_active: boolean;
    fields_count?: number;
    products_count?: number;
};

type Product = {
    id: number;
    game_id: number;
    sku: string;
    name: string;
    base_cost?: number;
    selling_price: number;
    currency: string;
    is_active: boolean;
    game?: { id: number; slug: string; name: string };
};

type Props = {
    adminRole: 'super_admin' | 'admin' | null;
};

type ApiError = Error & { validation?: Record<string, string[]> };

const money = (value: number) =>
    new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(value);

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const response = await fetch(path, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            ...options.headers,
        },
    });

    const payload = (await response.json().catch(() => ({}))) as {
        data?: T;
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (!response.ok) {
        const error = new Error(payload.message || 'Permintaan tidak dapat diproses.') as ApiError;
        error.validation = payload.errors;
        throw error;
    }

    return payload.data as T;
}

function FieldError({ errors, name }: { errors: Record<string, string[]>; name: string }) {
    const message = errors[name]?.[0];
    return message ? <p className="mt-1 text-xs text-rose-400">{message}</p> : null;
}

const inputClass =
    'w-full border border-[#353943] bg-[#0e1014] px-3 py-2.5 text-sm text-stone-100 outline-none transition placeholder:text-stone-600 focus:border-amber-500';
const labelClass = 'mb-1.5 block text-xs font-medium uppercase tracking-wide text-stone-400';
const buttonClass =
    'inline-flex items-center justify-center bg-amber-500 px-4 py-2.5 text-sm font-semibold text-[#1a1409] transition hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-50';

export default function AdminCatalog({ adminRole }: Props) {
    const isSuperAdmin = adminRole === 'super_admin';
    const [games, setGames] = useState<Game[]>([]);
    const [products, setProducts] = useState<Product[]>([]);
    const [selectedGame, setSelectedGame] = useState('');
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [notice, setNotice] = useState('');
    const [errorMessage, setErrorMessage] = useState('');
    const [validationErrors, setValidationErrors] = useState<Record<string, string[]>>({});
    const [gameForm, setGameForm] = useState({ name: '', slug: '', description: '' });
    const [fieldForm, setFieldForm] = useState({
        field_key: 'player_id',
        label: 'Player ID',
        input_type: 'text',
        is_required: true,
        options: '',
    });
    const [productForm, setProductForm] = useState({
        game_id: '',
        sku: '',
        provider_sku: '',
        name: '',
        base_cost: '',
        selling_price: '',
        nominal_label: '',
    });
    const [priceDrafts, setPriceDrafts] = useState<Record<number, { base_cost: string; selling_price: string; reason: string }>>({});

    const loadData = useCallback(async () => {
        setLoading(true);
        setErrorMessage('');
        try {
            const [gameResponse, productResponse] = await Promise.all([
                request<{ data: { data: Game[] } }>('/admin/api/games?per_page=100'),
                request<{ data: { data: Product[] } }>('/admin/api/products?per_page=100'),
            ]);

            const gameRows = gameResponse.data.data;
            setGames(gameRows);
            setProducts(productResponse.data.data);
            setSelectedGame((current) => current || String(gameRows[0]?.id ?? ''));
            setProductForm((current) => ({
                ...current,
                game_id: current.game_id || String(gameRows[0]?.id ?? ''),
            }));
        } catch (error) {
            setErrorMessage(error instanceof Error ? error.message : 'Gagal memuat katalog.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void loadData();
    }, [loadData]);

    async function submit(
        event: FormEvent<HTMLFormElement>,
        path: string,
        method: string,
        body: unknown,
        successMessage: string,
        afterSuccess?: () => void,
    ) {
        event.preventDefault();
        setSubmitting(true);
        setNotice('');
        setErrorMessage('');
        setValidationErrors({});

        try {
            await request(path, {
                method,
                body: JSON.stringify(body),
            });
            setNotice(successMessage);
            afterSuccess?.();
            await loadData();
        } catch (error) {
            const apiError = error as ApiError;
            setErrorMessage(apiError.message || 'Gagal menyimpan perubahan.');
            setValidationErrors(apiError.validation ?? {});
        } finally {
            setSubmitting(false);
        }
    }

    async function deactivate(product: Product) {
        if (!window.confirm(`Nonaktifkan produk ${product.name}?`)) return;
        setSubmitting(true);
        setNotice('');
        setErrorMessage('');
        try {
            await request(`/admin/api/products/${product.id}/deactivate`, { method: 'POST' });
            setNotice(`Produk ${product.name} dinonaktifkan.`);
            await loadData();
        } catch (error) {
            setErrorMessage(error instanceof Error ? error.message : 'Gagal menonaktifkan produk.');
        } finally {
            setSubmitting(false);
        }
    }

    const selectedGameRecord = games.find((game) => String(game.id) === selectedGame);

    return (
        <>
            <Head title="Katalog" />
            <main className="min-h-screen bg-[#0b0c0f] text-stone-100">
                <header className="border-b border-[#292d35]">
                    <div className="mx-auto flex max-w-7xl items-center justify-between px-5 py-5">
                        <div>
                            <a href="/admin" className="text-xs font-semibold uppercase tracking-[0.2em] text-amber-500">
                                RazzanStore
                            </a>
                            <p className="mt-1 text-sm text-stone-500">Admin / Katalog</p>
                        </div>
                        <div className="flex items-center gap-3">
                            <span className="hidden border border-[#333843] px-2.5 py-1 text-[11px] uppercase tracking-wide text-stone-400 sm:inline">
                                {isSuperAdmin ? 'Super Admin' : 'Admin'}
                            </span>
                            <a href="/admin" className="text-sm text-stone-300 hover:text-amber-400">Dashboard</a>
                            <form method="post" action="/admin/logout">
                                <input type="hidden" name="_token" value={document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''} />
                                <button className="border border-[#343943] px-3 py-2 text-sm text-stone-300 hover:border-amber-500">
                                    Keluar
                                </button>
                            </form>
                        </div>
                    </div>
                </header>

                <section className="mx-auto max-w-7xl px-5 py-9">
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-sm text-stone-500">Master data storefront</p>
                            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Katalog game & produk</h1>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-stone-400">
                                Kelola game, input Player ID/server, dan produk. Perubahan harga serta biaya modal hanya tersedia untuk Super Admin.
                            </p>
                        </div>
                        <button type="button" onClick={() => void loadData()} className="border border-[#343943] px-3 py-2.5 text-sm text-stone-300 hover:border-amber-500">
                            Muat ulang
                        </button>
                    </div>

                    {notice && <div role="status" className="mt-6 border border-emerald-900 bg-emerald-950/50 px-4 py-3 text-sm text-emerald-300">{notice}</div>}
                    {errorMessage && <div role="alert" className="mt-6 border border-rose-900 bg-rose-950/40 px-4 py-3 text-sm text-rose-300">{errorMessage}</div>}

                    <div className="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(360px,0.9fr)]">
                        <section className="border border-[#2b2f38] bg-[#15171c] p-5 sm:p-6">
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold">Game terdaftar</h2>
                                    <p className="mt-1 text-xs text-stone-500">{games.length} game ditemukan</p>
                                </div>
                                <span className="text-xs text-stone-500">Daftar utama</span>
                            </div>

                            {loading ? (
                                <p className="py-10 text-sm text-stone-500">Memuat katalog…</p>
                            ) : games.length === 0 ? (
                                <p className="py-10 text-sm text-stone-500">Belum ada game. Tambahkan game pertama di panel sebelah.</p>
                            ) : (
                                <div className="mt-5 divide-y divide-[#292d35]">
                                    {games.map((game) => (
                                        <button
                                            type="button"
                                            key={game.id}
                                            onClick={() => setSelectedGame(String(game.id))}
                                            className={`flex w-full items-center justify-between gap-4 py-4 text-left transition ${selectedGame === String(game.id) ? 'border-l-2 border-amber-500 pl-3' : 'pl-4 hover:bg-[#1b1e24]'}`}
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-semibold">{game.name}</span>
                                                <span className="mt-1 block text-xs text-stone-500">{game.slug} · {game.fields_count ?? 0} field · {game.products_count ?? 0} produk</span>
                                            </span>
                                            <span className={`shrink-0 text-[10px] uppercase tracking-wide ${game.is_active ? 'text-emerald-400' : 'text-stone-500'}`}>
                                                {game.is_active ? 'Aktif' : 'Nonaktif'}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}

                            <div className="mt-7 border-t border-[#292d35] pt-6">
                                <h3 className="text-sm font-semibold">Tambah game</h3>
                                <form
                                    className="mt-4 grid gap-3 sm:grid-cols-2"
                                    onSubmit={(event) => void submit(event, '/admin/api/games', 'POST', {
                                        ...gameForm,
                                        is_active: true,
                                    }, 'Game berhasil dibuat.', () => setGameForm({ name: '', slug: '', description: '' }))}
                                >
                                    <div>
                                        <label className={labelClass} htmlFor="game-name">Nama game</label>
                                        <input id="game-name" className={inputClass} value={gameForm.name} onChange={(e) => setGameForm({ ...gameForm, name: e.target.value })} required maxLength={140} />
                                        <FieldError errors={validationErrors} name="name" />
                                    </div>
                                    <div>
                                        <label className={labelClass} htmlFor="game-slug">Slug</label>
                                        <input id="game-slug" className={inputClass} value={gameForm.slug} onChange={(e) => setGameForm({ ...gameForm, slug: e.target.value.toLowerCase() })} placeholder="mobile-legends" required maxLength={100} pattern="[a-z0-9]+(?:-[a-z0-9]+)*" />
                                        <FieldError errors={validationErrors} name="slug" />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <label className={labelClass} htmlFor="game-description">Deskripsi (opsional)</label>
                                        <textarea id="game-description" className={inputClass} rows={2} value={gameForm.description} onChange={(e) => setGameForm({ ...gameForm, description: e.target.value })} maxLength={5000} />
                                    </div>
                                    <div className="sm:col-span-2">
                                        <button className={buttonClass} disabled={submitting}>Simpan game</button>
                                    </div>
                                </form>
                            </div>
                        </section>

                        <div className="space-y-6">
                            <section className="border border-[#2b2f38] bg-[#15171c] p-5 sm:p-6">
                                <h2 className="text-lg font-semibold">Input Player ID / server</h2>
                                <p className="mt-1 text-xs text-stone-500">
                                    {selectedGameRecord ? `Konfigurasi untuk ${selectedGameRecord.name}` : 'Pilih game terlebih dahulu.'}
                                </p>
                                <form
                                    className="mt-5 space-y-3"
                                    onSubmit={(event) => void submit(event, `/admin/api/games/${selectedGame}/fields`, 'POST', {
                                        field_key: fieldForm.field_key,
                                        label: fieldForm.label,
                                        input_type: fieldForm.input_type,
                                        is_required: fieldForm.is_required,
                                        ...(fieldForm.input_type === 'select' ? { options: fieldForm.options.split(',').map((v) => v.trim()).filter(Boolean) } : {}),
                                    }, 'Field berhasil ditambahkan.', () => setFieldForm({ field_key: 'player_id', label: 'Player ID', input_type: 'text', is_required: true, options: '' }))}
                                >
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label className={labelClass} htmlFor="field-key">Field key</label>
                                            <input id="field-key" className={inputClass} value={fieldForm.field_key} onChange={(e) => setFieldForm({ ...fieldForm, field_key: e.target.value.toLowerCase() })} required pattern="[a-z][a-z0-9_]*" />
                                        </div>
                                        <div>
                                            <label className={labelClass} htmlFor="field-label">Label</label>
                                            <input id="field-label" className={inputClass} value={fieldForm.label} onChange={(e) => setFieldForm({ ...fieldForm, label: e.target.value })} required />
                                        </div>
                                    </div>
                                    <div>
                                        <label className={labelClass} htmlFor="field-type">Jenis input</label>
                                        <select id="field-type" className={inputClass} value={fieldForm.input_type} onChange={(e) => setFieldForm({ ...fieldForm, input_type: e.target.value })}>
                                            <option value="text">Teks</option>
                                            <option value="number">Angka</option>
                                            <option value="select">Pilihan (select)</option>
                                        </select>
                                    </div>
                                    {fieldForm.input_type === 'select' && (
                                        <div>
                                            <label className={labelClass} htmlFor="field-options">Pilihan, pisahkan dengan koma</label>
                                            <input id="field-options" className={inputClass} value={fieldForm.options} onChange={(e) => setFieldForm({ ...fieldForm, options: e.target.value })} placeholder="Asia, Europe" required />
                                        </div>
                                    )}
                                    <label className="flex items-center gap-2 text-sm text-stone-400">
                                        <input type="checkbox" checked={fieldForm.is_required} onChange={(e) => setFieldForm({ ...fieldForm, is_required: e.target.checked })} className="accent-amber-500" />
                                        Wajib diisi pelanggan
                                    </label>
                                    <button className={buttonClass} disabled={submitting || !selectedGame}>Tambah field</button>
                                </form>
                            </section>

                            {isSuperAdmin && (
                                <section className="border border-[#2b2f38] bg-[#15171c] p-5 sm:p-6">
                                    <div>
                                        <h2 className="text-lg font-semibold">Tambah produk</h2>
                                        <p className="mt-1 text-xs text-stone-500">Hanya Super Admin yang dapat membuat produk dan menetapkan harga.</p>
                                    </div>
                                    <form
                                        className="mt-5 grid gap-3 sm:grid-cols-2"
                                        onSubmit={(event) => void submit(event, '/admin/api/products', 'POST', {
                                            ...productForm,
                                            base_cost: Number(productForm.base_cost),
                                            selling_price: Number(productForm.selling_price),
                                            game_id: Number(productForm.game_id),
                                            is_active: true,
                                        }, 'Produk berhasil ditambahkan.', () => setProductForm({ game_id: selectedGame, sku: '', provider_sku: '', name: '', base_cost: '', selling_price: '', nominal_label: '' }))}
                                    >
                                        <div className="sm:col-span-2">
                                            <label className={labelClass} htmlFor="product-game">Game</label>
                                            <select id="product-game" className={inputClass} value={productForm.game_id} onChange={(e) => setProductForm({ ...productForm, game_id: e.target.value })} required>
                                                <option value="">Pilih game</option>
                                                {games.map((game) => <option key={game.id} value={game.id}>{game.name}</option>)}
                                            </select>
                                        </div>
                                        <div>
                                            <label className={labelClass} htmlFor="product-sku">SKU internal</label>
                                            <input id="product-sku" className={inputClass} value={productForm.sku} onChange={(e) => setProductForm({ ...productForm, sku: e.target.value.toUpperCase() })} required />
                                        </div>
                                        <div>
                                            <label className={labelClass} htmlFor="provider-sku">SKU provider</label>
                                            <input id="provider-sku" className={inputClass} value={productForm.provider_sku} onChange={(e) => setProductForm({ ...productForm, provider_sku: e.target.value })} maxLength={120} />
                                        </div>
                                        <div>
                                            <label className={labelClass} htmlFor="product-name">Nama produk</label>
                                            <input id="product-name" className={inputClass} value={productForm.name} onChange={(e) => setProductForm({ ...productForm, name: e.target.value })} required />
                                        </div>
                                        <div>
                                            <label className={labelClass} htmlFor="base-cost">Biaya modal (IDR)</label>
                                            <input id="base-cost" type="number" min="0" step="1" className={inputClass} value={productForm.base_cost} onChange={(e) => setProductForm({ ...productForm, base_cost: e.target.value })} required />
                                        </div>
                                        <div>
                                            <label className={labelClass} htmlFor="selling-price">Harga jual (IDR)</label>
                                            <input id="selling-price" type="number" min="0" step="1" className={inputClass} value={productForm.selling_price} onChange={(e) => setProductForm({ ...productForm, selling_price: e.target.value })} required />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <label className={labelClass} htmlFor="nominal-label">Label nominal (opsional)</label>
                                            <input id="nominal-label" className={inputClass} value={productForm.nominal_label} onChange={(e) => setProductForm({ ...productForm, nominal_label: e.target.value })} />
                                        </div>
                                        <div className="sm:col-span-2"><button className={buttonClass} disabled={submitting || games.length === 0}>Simpan produk</button></div>
                                    </form>
                                </section>
                            )}
                        </div>
                    </div>

                    <section className="mt-6 border border-[#2b2f38] bg-[#15171c] p-5 sm:p-6">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold">Daftar produk</h2>
                                <p className="mt-1 text-xs text-stone-500">{products.length} produk dimuat</p>
                            </div>
                            <span className="text-xs text-stone-500">{isSuperAdmin ? 'Tampilan Super Admin' : 'Harga modal disembunyikan'}</span>
                        </div>
                        {loading ? (
                            <p className="py-8 text-sm text-stone-500">Memuat produk…</p>
                        ) : products.length === 0 ? (
                            <p className="py-8 text-sm text-stone-500">Belum ada produk di katalog.</p>
                        ) : (
                            <div className="mt-5 overflow-x-auto">
                                <table className="w-full min-w-[680px] text-left text-sm">
                                    <thead className="border-b border-[#30343d] text-[11px] uppercase tracking-wide text-stone-500">
                                        <tr>
                                            <th className="pb-3 pr-4 font-medium">Produk</th>
                                            <th className="pb-3 pr-4 font-medium">Game</th>
                                            {isSuperAdmin && <th className="pb-3 pr-4 font-medium">Modal</th>}
                                            <th className="pb-3 pr-4 font-medium">Harga jual</th>
                                            <th className="pb-3 pr-4 font-medium">Status</th>
                                            <th className="pb-3 font-medium">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-[#292d35]">
                                        {products.map((product) => (
                                            <tr key={product.id} className="align-top">
                                                <td className="py-4 pr-4">
                                                    <span className="block font-medium text-stone-200">{product.name}</span>
                                                    <span className="mt-1 block text-xs text-stone-500">{product.sku}</span>
                                                </td>
                                                <td className="py-4 pr-4 text-stone-400">{product.game?.name ?? games.find((game) => game.id === product.game_id)?.name ?? '—'}</td>
                                                {isSuperAdmin && <td className="py-4 pr-4 tabular-nums text-stone-300">{money(product.base_cost ?? 0)}</td>}
                                                <td className="py-4 pr-4 tabular-nums text-stone-200">{money(product.selling_price)}</td>
                                                <td className="py-4 pr-4">
                                                    <span className={product.is_active ? 'text-xs text-emerald-400' : 'text-xs text-stone-500'}>{product.is_active ? 'Aktif' : 'Nonaktif'}</span>
                                                </td>
                                                <td className="py-4">
                                                    <div className="flex flex-wrap gap-2">
                                                        {isSuperAdmin && product.is_active && (
                                                            <button
                                                                type="button"
                                                                onClick={() => setPriceDrafts((drafts) => ({
                                                                    ...drafts,
                                                                    [product.id]: drafts[product.id] ?? {
                                                                        base_cost: String(product.base_cost ?? 0),
                                                                        selling_price: String(product.selling_price),
                                                                        reason: '',
                                                                    },
                                                                }))}
                                                                className="border border-[#44403c] px-2.5 py-1.5 text-xs text-amber-300 hover:border-amber-500"
                                                            >
                                                                Ubah harga
                                                            </button>
                                                        )}
                                                        {product.is_active && (
                                                            <button type="button" disabled={submitting} onClick={() => void deactivate(product)} className="border border-[#44403c] px-2.5 py-1.5 text-xs text-rose-300 hover:border-rose-500">
                                                                Nonaktifkan
                                                            </button>
                                                        )}
                                                    </div>
                                                    {isSuperAdmin && priceDrafts[product.id] && (
                                                        <form
                                                            className="mt-3 grid gap-2 rounded border border-[#373b44] bg-[#101216] p-3"
                                                            onSubmit={(event) => void submit(event, `/admin/api/products/${product.id}/price`, 'PATCH', {
                                                                base_cost: Number(priceDrafts[product.id].base_cost),
                                                                selling_price: Number(priceDrafts[product.id].selling_price),
                                                                reason: priceDrafts[product.id].reason || undefined,
                                                            }, 'Harga dan histori berhasil diperbarui.', () => setPriceDrafts((drafts) => {
                                                                const next = { ...drafts };
                                                                delete next[product.id];
                                                                return next;
                                                            }))}
                                                        >
                                                            <label className="text-[11px] text-stone-500">Biaya modal (IDR)</label>
                                                            <input className={inputClass} type="number" min="0" step="1" value={priceDrafts[product.id].base_cost} onChange={(e) => setPriceDrafts((drafts) => ({ ...drafts, [product.id]: { ...drafts[product.id], base_cost: e.target.value } }))} />
                                                            <label className="text-[11px] text-stone-500">Harga jual (IDR)</label>
                                                            <input className={inputClass} type="number" min="0" step="1" value={priceDrafts[product.id].selling_price} onChange={(e) => setPriceDrafts((drafts) => ({ ...drafts, [product.id]: { ...drafts[product.id], selling_price: e.target.value } }))} />
                                                            <label className="text-[11px] text-stone-500">Alasan perubahan</label>
                                                            <input className={inputClass} value={priceDrafts[product.id].reason} onChange={(e) => setPriceDrafts((drafts) => ({ ...drafts, [product.id]: { ...drafts[product.id], reason: e.target.value } }))} maxLength={255} />
                                                            <div className="flex gap-2">
                                                                <button className={buttonClass} disabled={submitting}>Simpan harga</button>
                                                                <button type="button" onClick={() => setPriceDrafts((drafts) => { const next = { ...drafts }; delete next[product.id]; return next; })} className="border border-[#343943] px-3 py-2 text-xs text-stone-400">Batal</button>
                                                            </div>
                                                        </form>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </section>
            </main>
        </>
    );
}
