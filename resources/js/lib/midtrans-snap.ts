export type MidtransSnapCallbacks = {
    onSuccess?: (result: unknown) => void;
    onPending?: (result: unknown) => void;
    onError?: (result: unknown) => void;
    onClose?: () => void;
};

declare global {
    interface Window {
        snap?: {
            pay: (token: string, callbacks?: MidtransSnapCallbacks) => void;
        };
    }
}

let snapScriptPromise: Promise<void> | null = null;

export async function loadMidtransSnap(clientKey: string | null, isProduction: boolean): Promise<void> {
    if (!clientKey) {
        throw new Error('Midtrans Client Key belum dikonfigurasi di server.');
    }

    if (window.snap) {
        return;
    }

    if (snapScriptPromise) {
        return snapScriptPromise;
    }

    snapScriptPromise = new Promise<void>((resolve, reject) => {
        const existing = document.querySelector<HTMLScriptElement>('script[data-razzan-midtrans="true"]');

        if (existing) {
            existing.addEventListener('load', () => window.snap ? resolve() : reject(
                new Error('Snap.js termuat tetapi API pembayaran tidak tersedia.'),
            ), { once: true });
            existing.addEventListener('error', () => reject(new Error('Gagal memuat Snap.js.')), { once: true });
            return;
        }

        const script = document.createElement('script');
        script.src = isProduction
            ? 'https://app.midtrans.com/snap/snap.js'
            : 'https://app.sandbox.midtrans.com/snap/snap.js';
        script.async = true;
        script.dataset.clientKey = clientKey;
        script.dataset.razzanMidtrans = 'true';
        script.onload = () => window.snap
            ? resolve()
            : reject(new Error('Snap.js termuat tetapi API pembayaran tidak tersedia.'));
        script.onerror = () => reject(new Error('Gagal memuat Snap.js. Periksa koneksi internet lalu coba lagi.'));
        document.head.appendChild(script);
    }).finally(() => {
        snapScriptPromise = null;
    });

    return snapScriptPromise;
}

export async function openMidtransSnap(
    token: string,
    clientKey: string | null,
    isProduction: boolean,
    trackingUrl: string,
): Promise<void> {
    await loadMidtransSnap(clientKey, isProduction);

    if (!window.snap) {
        throw new Error('Snap.js belum siap digunakan.');
    }

    const goToTracking = () => {
        window.location.assign(trackingUrl);
    };

    window.snap.pay(token, {
        // Browser callbacks are navigation hints only. Server notification/status API
        // remains the source of truth for payment state.
        onSuccess: goToTracking,
        onPending: goToTracking,
        onError: goToTracking,
        // Closing the popup is not treated as a failed or expired payment.
        onClose: goToTracking,
    });
}
