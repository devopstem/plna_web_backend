import { Head, useForm } from '@inertiajs/react';
import { DevicePhoneMobileIcon } from '@heroicons/react/24/outline';

export default function MobileOnly({ email, roleLabel = 'Мерчандайзер' }) {
    const { post, processing } = useForm({});

    const logout = (event) => {
        event.preventDefault();
        post(route('logout'));
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-[#0e172b] px-4">
            <Head title="Только API / мобильное приложение" />

            <div className="w-full max-w-md rounded-2xl border border-slate-700 bg-[#152033] p-8 text-center shadow-xl">
                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-500/15 text-indigo-300">
                    <DevicePhoneMobileIcon className="h-7 w-7" />
                </div>
                <h1 className="mt-5 text-xl font-semibold text-white">
                    Доступ только через API / мобильное приложение
                </h1>
                <p className="mt-3 text-sm leading-relaxed text-slate-300">
                    Роль «{roleLabel}» не имеет доступа к веб-панели PLNA.
                    Используйте API или мобильное приложение.
                </p>
                {email ? (
                    <p className="mt-2 text-xs text-slate-500">{email}</p>
                ) : null}
                <form onSubmit={logout} className="mt-8">
                    <button
                        type="submit"
                        disabled={processing}
                        className="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
                    >
                        {processing ? 'Выход…' : 'Выйти'}
                    </button>
                </form>
            </div>
        </div>
    );
}
