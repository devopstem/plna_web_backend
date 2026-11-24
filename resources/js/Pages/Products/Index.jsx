import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowPathIcon,
    CheckBadgeIcon,
    CheckIcon,
    MagnifyingGlassIcon,
    PencilSquareIcon,
    PlusIcon,
    TrashIcon,
} from '@heroicons/react/24/outline';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import { useCan } from '@/lib/permissions';
import { fireConfirm, fireError, fireSuccess } from '@/lib/swal';
import clsx from 'clsx';

export default function Index({
    products,
    filters,
    trashedCount = 0,
    uncheckedCount = 0,
}) {
    const { flash } = usePage().props;
    const can = useCan();
    const canCreate = can('create-products');
    const canEdit = can('edit-products');
    const canDelete = can('delete-products');
    const [search, setSearch] = useState(filters.search || '');
    const [suggestions, setSuggestions] = useState([]);
    const [suggestOpen, setSuggestOpen] = useState(false);
    const [suggestLoading, setSuggestLoading] = useState(false);
    const searchWrapRef = useRef(null);
    const suggestAbortRef = useRef(null);
    const trashed = Boolean(filters.trashed);
    const uncheckedOnly = Boolean(filters.unchecked);

    useEffect(() => {
        if (flash?.success) {
            fireSuccess(flash.success);
        }
        if (flash?.error) {
            fireError(flash.error);
        }
    }, [flash]);

    useEffect(() => {
        setSearch(filters.search || '');
    }, [filters.search]);

    useEffect(() => {
        const onDocClick = (event) => {
            if (!searchWrapRef.current?.contains(event.target)) {
                setSuggestOpen(false);
            }
        };

        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, []);

    // Live suggestions (lightweight JSON).
    useEffect(() => {
        const q = search.trim();

        if (q.length < 2) {
            setSuggestions([]);
            setSuggestLoading(false);
            if (suggestAbortRef.current) {
                suggestAbortRef.current.abort();
            }
            return undefined;
        }

        const timer = window.setTimeout(async () => {
            if (suggestAbortRef.current) {
                suggestAbortRef.current.abort();
            }

            const controller = new AbortController();
            suggestAbortRef.current = controller;
            setSuggestLoading(true);

            try {
                const { data } = await axios.get(route('products.search'), {
                    params: { q },
                    signal: controller.signal,
                });
                setSuggestions(Array.isArray(data?.data) ? data.data : []);
                setSuggestOpen(true);
            } catch (err) {
                if (err?.code === 'ERR_CANCELED') {
                    return;
                }
                setSuggestions([]);
            } finally {
                setSuggestLoading(false);
            }
        }, 250);

        return () => window.clearTimeout(timer);
    }, [search]);

    // Debounced table reload (Inertia) — only name/barcode search.
    useEffect(() => {
        const q = search.trim();
        const current = (filters.search || '').trim();

        if (q === current) {
            return undefined;
        }

        // Empty clears filter; otherwise wait for 2+ chars.
        if (q !== '' && q.length < 2) {
            return undefined;
        }

        const timer = window.setTimeout(() => {
            applyFilters({ search: q || undefined });
        }, 350);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const applyFilters = (overrides = {}) => {
        router.get(
            route('products.index'),
            {
                search: search.trim() || undefined,
                trashed: trashed ? 1 : undefined,
                unchecked: uncheckedOnly ? 1 : undefined,
                ...overrides,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const handleSearchSubmit = (e) => {
        e.preventDefault();
        setSuggestOpen(false);
        applyFilters({ search: search.trim() || undefined });
    };

    const pickSuggestion = (product) => {
        setSearch(product.barcode || product.name || '');
        setSuggestOpen(false);
        applyFilters({ search: product.barcode || product.name || undefined });
    };

    const switchTab = (toTrashed) => {
        applyFilters({
            trashed: toTrashed ? 1 : undefined,
            unchecked: toTrashed ? undefined : uncheckedOnly ? 1 : undefined,
            search: search.trim() || undefined,
        });
    };

    const toggleUncheckedFilter = () => {
        applyFilters({
            unchecked: uncheckedOnly ? undefined : 1,
            trashed: undefined,
        });
    };

    const handleApproveAll = async () => {
        if (uncheckedCount < 1) {
            fireSuccess('Непроверенных товаров нет.');
            return;
        }

        const confirmed = await fireConfirm(
            'Подтвердить все непроверенные?',
            `Будет отмечено как проверенные: ${uncheckedCount} товар(ов). Обычно это делают после импорта.`,
            'Подтвердить все',
        );

        if (!confirmed) {
            return;
        }

        router.post(
            route('products.approve-unchecked'),
            {},
            {
                preserveScroll: true,
                onError: () =>
                    fireError('Не удалось подтвердить непроверенные товары.'),
            },
        );
    };

    const handleApproveOne = async (product) => {
        const confirmed = await fireConfirm(
            'Отметить как проверенный?',
            `«${product.name}» будет отмечен как проверенный.`,
            'Подтвердить',
        );

        if (!confirmed) {
            return;
        }

        router.post(
            route('products.approve', product.id),
            {},
            {
                preserveScroll: true,
                onError: () => fireError('Не удалось подтвердить товар.'),
            },
        );
    };

    const handleDelete = async (product) => {
        const confirmed = await fireConfirm(
            'Удалить товар?',
            `«${product.name}» будет перемещён в корзину.`,
        );

        if (!confirmed) {
            return;
        }

        router.delete(route('products.destroy', product.id), {
            preserveScroll: true,
            onError: () => fireError('Не удалось удалить товар.'),
        });
    };

    const handleRestore = async (product) => {
        const confirmed = await fireConfirm(
            'Восстановить товар?',
            `«${product.name}» снова появится в списке.`,
            'Восстановить',
        );

        if (!confirmed) {
            return;
        }

        router.post(
            route('products.restore', product.id),
            {},
            {
                preserveScroll: true,
                onError: () => fireError('Не удалось восстановить товар.'),
            },
        );
    };

    const handleForceDelete = async (product) => {
        const confirmed = await fireConfirm(
            'Удалить безвозвратно?',
            `«${product.name}» будет удалён навсегда.`,
            'Удалить навсегда',
        );

        if (!confirmed) {
            return;
        }

        router.delete(route('products.force-delete', product.id), {
            preserveScroll: true,
            onError: () => fireError('Не удалось удалить товар.'),
        });
    };

    return (
        <AdminLayout
            header={
                <h1 className="text-xl font-semibold leading-tight text-white">
                    Товары
                </h1>
            }
        >
            <Head title="Товары" />

            <div className="space-y-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex gap-1 rounded-lg bg-[#152033] p-1 shadow-sm ring-1 ring-slate-800">
                        <button
                            type="button"
                            onClick={() => switchTab(false)}
                            className={clsx(
                                'rounded-md px-3 py-1.5 text-sm font-medium transition',
                                !trashed
                                    ? 'bg-indigo-600 text-white'
                                    : 'text-slate-300 hover:bg-slate-800',
                            )}
                        >
                            Активные
                        </button>
                        {(trashedCount > 0 || trashed) && canDelete && (
                            <button
                                type="button"
                                onClick={() => switchTab(true)}
                                className={clsx(
                                    'rounded-md px-3 py-1.5 text-sm font-medium transition',
                                    trashed
                                        ? 'bg-indigo-600 text-white'
                                        : 'text-slate-300 hover:bg-slate-800',
                                )}
                            >
                                Корзина
                                {trashedCount > 0 && (
                                    <span className="ml-1.5 inline-flex min-w-5 items-center justify-center rounded-full bg-white/20 px-1.5 text-xs">
                                        {trashedCount}
                                    </span>
                                )}
                            </button>
                        )}
                    </div>

                    {!trashed && (
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            {canEdit && uncheckedCount > 0 ? (
                                <button
                                    type="button"
                                    onClick={handleApproveAll}
                                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-2 text-sm font-semibold text-emerald-300 shadow-sm transition hover:bg-emerald-500/20"
                                >
                                    <CheckBadgeIcon
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    />
                                    Подтвердить все ({uncheckedCount})
                                </button>
                            ) : null}

                            {!trashed && canCreate ? (
                                <Link
                                    href={route('products.create')}
                                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500"
                                >
                                    <PlusIcon
                                        className="h-5 w-5"
                                        aria-hidden="true"
                                    />
                                    Добавить
                                </Link>
                            ) : null}
                        </div>
                    )}
                </div>

                {!trashed && canEdit && uncheckedCount > 0 ? (
                    <div className="flex flex-col gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100 sm:flex-row sm:items-center sm:justify-between">
                        <p>
                            Непроверенных товаров:{' '}
                            <span className="font-semibold text-white">
                                {uncheckedCount}
                            </span>
                            . После импорта можно подтвердить все сразу.
                        </p>
                        <button
                            type="button"
                            onClick={toggleUncheckedFilter}
                            className={clsx(
                                'shrink-0 rounded-md px-3 py-1.5 text-xs font-semibold transition',
                                uncheckedOnly
                                    ? 'bg-amber-400 text-slate-900'
                                    : 'bg-amber-500/20 text-amber-100 hover:bg-amber-500/30',
                            )}
                        >
                            {uncheckedOnly
                                ? 'Показать все'
                                : 'Только непроверенные'}
                        </button>
                    </div>
                ) : null}

                <div className="rounded-xl bg-[#152033] p-4 shadow-sm ring-1 ring-slate-800">
                    <form
                        ref={searchWrapRef}
                        onSubmit={handleSearchSubmit}
                        className="relative max-w-2xl"
                    >
                        <label
                            htmlFor="search"
                            className="mb-1 block text-sm font-medium text-slate-200"
                        >
                            Живой поиск
                        </label>
                        <div className="relative">
                            <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
                            <input
                                id="search"
                                type="search"
                                value={search}
                                onChange={(e) => {
                                    setSearch(e.target.value);
                                    setSuggestOpen(true);
                                }}
                                onFocus={() => {
                                    if (suggestions.length > 0) {
                                        setSuggestOpen(true);
                                    }
                                }}
                                placeholder="Начните вводить название или штрихкод…"
                                autoComplete="off"
                                className="block w-full rounded-lg border-slate-700 py-2.5 pl-10 pr-3 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                            />
                        </div>
                        <p className="mt-1.5 text-xs text-slate-500">
                            От 2 символов — подсказки и фильтр таблицы. Цифры
                            ищут по штрихкоду быстрее.
                        </p>

                        {suggestOpen && search.trim().length >= 2 && (
                            <div className="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-xl border border-slate-700 bg-[#0f172a] py-1 shadow-xl">
                                {suggestLoading && (
                                    <p className="px-3 py-2 text-sm text-slate-400">
                                        Поиск…
                                    </p>
                                )}
                                {!suggestLoading &&
                                    suggestions.length === 0 && (
                                        <p className="px-3 py-2 text-sm text-slate-400">
                                            Ничего не найдено
                                        </p>
                                    )}
                                {!suggestLoading &&
                                    suggestions.map((item) => (
                                        <button
                                            key={item.id}
                                            type="button"
                                            onClick={() => pickSuggestion(item)}
                                            className="flex w-full flex-col px-3 py-2 text-left hover:bg-indigo-500/15"
                                        >
                                            <span className="truncate text-sm font-medium text-white">
                                                {item.name}
                                            </span>
                                            <span className="font-mono text-xs text-slate-400">
                                                {item.barcode}
                                                {item.width_mm
                                                    ? ` · ${item.width_mm} мм`
                                                    : ''}
                                            </span>
                                        </button>
                                    ))}
                            </div>
                        )}
                    </form>
                </div>

                <div className="overflow-hidden rounded-xl bg-[#152033] shadow-sm ring-1 ring-slate-800">
                    <div className="overflow-x-auto">
                        <table className="min-w-full table-fixed divide-y divide-slate-800">
                            <thead className="bg-[#1a2740]">
                                <tr>
                                    <th className="w-[9rem] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Штрихкод
                                    </th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Название
                                    </th>
                                    <th className="w-[5.5rem] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Объём
                                    </th>
                                    <th className="w-[8.5rem] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Упаковка
                                    </th>
                                    <th className="w-[7.5rem] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Статус
                                    </th>
                                    <th className="w-[9rem] px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-400">
                                        Действия
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800 bg-[#152033]">
                                {products.data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-10 text-center text-sm text-slate-400"
                                        >
                                            {trashed
                                                ? 'Корзина пуста'
                                                : 'Товары не найдены'}
                                        </td>
                                    </tr>
                                ) : (
                                    products.data.map((product) => (
                                        <tr
                                            key={product.id}
                                            className="hover:bg-slate-800/80"
                                        >
                                            <td className="whitespace-nowrap px-4 py-3 font-mono text-sm text-slate-200">
                                                {product.barcode}
                                            </td>
                                            <td className="max-w-0 px-4 py-3 text-sm font-medium text-white">
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <span
                                                        className="min-w-0 truncate"
                                                        title={product.name || ''}
                                                    >
                                                        {product.name}
                                                    </span>
                                                    {product.is_private ? (
                                                        <span className="shrink-0 inline-flex items-center rounded-full bg-violet-500/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-300 ring-1 ring-inset ring-violet-400/30">
                                                            Свой
                                                        </span>
                                                    ) : (
                                                        <span className="shrink-0 inline-flex items-center rounded-full bg-sky-500/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-300 ring-1 ring-inset ring-sky-400/25">
                                                            Глоб.
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-sm text-slate-300">
                                                {product.volume_ml
                                                    ? `${product.volume_ml} мл`
                                                    : '—'}
                                            </td>
                                            <td className="max-w-0 px-4 py-3 text-sm text-slate-300">
                                                <span
                                                    className="block truncate"
                                                    title={product.package_type || ''}
                                                >
                                                    {product.package_type || '—'}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-sm">
                                                <span
                                                    className={clsx(
                                                        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset',
                                                        product.checked
                                                            ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
                                                            : 'bg-amber-50 text-amber-700 ring-amber-600/20',
                                                    )}
                                                >
                                                    {product.checked
                                                        ? 'Проверен'
                                                        : 'Не проверен'}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap px-4 py-3 text-right text-sm">
                                                <div className="flex items-center justify-end gap-1">
                                                    {trashed ? (
                                                        <>
                                                            {canDelete && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    handleRestore(
                                                                        product,
                                                                    )
                                                                }
                                                                className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-emerald-700 hover:bg-emerald-50"
                                                            >
                                                                <ArrowPathIcon className="h-4 w-4" />
                                                                <span className="hidden sm:inline">
                                                                    Восстановить
                                                                </span>
                                                            </button>
                                                            )}
                                                            {canDelete && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    handleForceDelete(
                                                                        product,
                                                                    )
                                                                }
                                                                className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-red-400 hover:bg-red-500/10"
                                                            >
                                                                <TrashIcon className="h-4 w-4" />
                                                                <span className="hidden sm:inline">
                                                                    Удалить
                                                                </span>
                                                            </button>
                                                            )}
                                                        </>
                                                    ) : (
                                                        <>
                                                            {canEdit &&
                                                                !product.checked && (
                                                                    <button
                                                                        type="button"
                                                                        onClick={() =>
                                                                            handleApproveOne(
                                                                                product,
                                                                            )
                                                                        }
                                                                        className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-emerald-300 hover:bg-emerald-500/15"
                                                                        title="Отметить как проверенный"
                                                                    >
                                                                        <CheckIcon className="h-4 w-4" />
                                                                        <span className="hidden sm:inline">
                                                                            Подтвердить
                                                                        </span>
                                                                    </button>
                                                                )}
                                                            {canEdit && (
                                                            <Link
                                                                href={route(
                                                                    'products.edit',
                                                                    product.id,
                                                                )}
                                                                className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-indigo-300 hover:bg-indigo-500/15"
                                                            >
                                                                <PencilSquareIcon className="h-4 w-4" />
                                                                <span className="hidden sm:inline">
                                                                    Изменить
                                                                </span>
                                                            </Link>
                                                            )}
                                                            {canDelete && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    handleDelete(
                                                                        product,
                                                                    )
                                                                }
                                                                className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-red-400 hover:bg-red-500/10"
                                                            >
                                                                <TrashIcon className="h-4 w-4" />
                                                                <span className="hidden sm:inline">
                                                                    Удалить
                                                                </span>
                                                            </button>
                                                            )}
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {products.last_page > 1 && (
                        <div className="flex items-center justify-between border-t border-slate-800 px-4 py-3">
                            <p className="text-sm text-slate-400">
                                Стр. {products.current_page} из{' '}
                                {products.last_page}
                            </p>
                            <div className="flex gap-2">
                                {products.prev_page_url && (
                                    <Link
                                        href={products.prev_page_url}
                                        className="rounded-lg border border-slate-700 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800"
                                        preserveScroll
                                    >
                                        Назад
                                    </Link>
                                )}
                                {products.next_page_url && (
                                    <Link
                                        href={products.next_page_url}
                                        className="rounded-lg border border-slate-700 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800"
                                        preserveScroll
                                    >
                                        Далее
                                    </Link>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
