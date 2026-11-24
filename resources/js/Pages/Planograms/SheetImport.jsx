import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    CheckCircleIcon,
    DocumentArrowUpIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline';
import { useEffect, useMemo, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import { useCan } from '@/lib/permissions';
import { fireConfirm, fireError, fireSuccess } from '@/lib/swal';
import clsx from 'clsx';

const selectClass =
    'w-full rounded-lg border border-slate-700 bg-[#0e172b] px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40';

const inputClass =
    'w-full rounded-lg border border-slate-700 bg-[#0e172b] px-2.5 py-1.5 text-sm text-white focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/40';

function statusBadge(status) {
    const map = {
        ok: 'bg-emerald-500/15 text-emerald-300 ring-emerald-500/30',
        missing_product: 'bg-rose-500/15 text-rose-300 ring-rose-500/30',
        missing_level: 'bg-amber-500/15 text-amber-200 ring-amber-500/30',
        skipped: 'bg-slate-500/20 text-slate-300 ring-slate-500/30',
        committed: 'bg-sky-500/15 text-sky-300 ring-sky-500/30',
        pending: 'bg-slate-500/20 text-slate-300 ring-slate-500/30',
    };
    const labels = {
        ok: 'OK',
        missing_product: 'Нет в каталоге',
        missing_level: 'Нет полки',
        skipped: 'Пропуск',
        committed: 'Записано',
        pending: 'Ожидает',
    };

    return (
        <span
            className={clsx(
                'inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset',
                map[status] || map.pending,
            )}
        >
            {labels[status] || status}
        </span>
    );
}

export default function SheetImport({
    stores = [],
    tree = [],
    filters = {},
    tesseractAvailable = false,
    import: sheetImport = null,
}) {
    const { flash } = usePage().props;
    const can = useCan();
    const canEdit = can('create-planograms', 'edit-planograms', 'manage-planograms');

    const [storeId, setStoreId] = useState(filters.store_id || stores[0]?.id || '');
    const [departmentId, setDepartmentId] = useState('');
    const [equipmentKey, setEquipmentKey] = useState('');

    useEffect(() => {
        if (flash?.success) fireSuccess(flash.success);
        if (flash?.error) fireError(flash.error);
    }, [flash]);

    useEffect(() => {
        setStoreId(filters.store_id || stores[0]?.id || '');
    }, [filters.store_id, stores]);

    const departments = useMemo(() => tree || [], [tree]);

    useEffect(() => {
        if (!departments.length) {
            setDepartmentId('');
            return;
        }
        const fromFilter =
            departments.find(
                (d) =>
                    d.shelves?.some((s) => s.id === filters.shelf_id) ||
                    d.coolers?.some((s) => s.id === filters.cooler_id) ||
                    d.stands?.some((s) => s.id === filters.stand_id),
            )?.id || departments[0].id;
        setDepartmentId(fromFilter);
    }, [departments, filters.shelf_id, filters.cooler_id, filters.stand_id]);

    const equipmentOptions = useMemo(() => {
        const dept = departments.find((d) => d.id === departmentId);
        if (!dept) return [];
        return [
            ...(dept.shelves || []).map((s) => ({
                key: `shelf:${s.id}`,
                type: 'shelf',
                id: s.id,
                label: `Стеллаж · ${s.code} (${s.levels_count || 0} ур.)`,
            })),
            ...(dept.coolers || []).map((s) => ({
                key: `cooler:${s.id}`,
                type: 'cooler',
                id: s.id,
                label: `Холодильник · ${s.code} (${s.levels_count || 0} ур.)`,
            })),
            ...(dept.stands || []).map((s) => ({
                key: `stand:${s.id}`,
                type: 'stand',
                id: s.id,
                label: `Стойка · ${s.code} (${s.levels_count || 0} ур.)`,
            })),
        ];
    }, [departments, departmentId]);

    useEffect(() => {
        if (!equipmentOptions.length) {
            setEquipmentKey('');
            return;
        }
        const pref =
            (filters.shelf_id && `shelf:${filters.shelf_id}`) ||
            (filters.cooler_id && `cooler:${filters.cooler_id}`) ||
            (filters.stand_id && `stand:${filters.stand_id}`) ||
            '';
        setEquipmentKey(
            equipmentOptions.some((o) => o.key === pref)
                ? pref
                : equipmentOptions[0].key,
        );
    }, [equipmentOptions, filters.shelf_id, filters.cooler_id, filters.stand_id]);

    const selectedEquipment = equipmentOptions.find((o) => o.key === equipmentKey);

    const form = useForm({
        store_id: storeId,
        equipment_type: selectedEquipment?.type || '',
        equipment_id: selectedEquipment?.id || '',
        ocr_text: '',
        image: null,
    });

    useEffect(() => {
        form.setData({
            ...form.data,
            store_id: storeId,
            equipment_type: selectedEquipment?.type || '',
            equipment_id: selectedEquipment?.id || '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [storeId, selectedEquipment?.type, selectedEquipment?.id]);

    const changeStore = (id) => {
        setStoreId(id);
        router.get(
            route('planograms.sheet-import.create'),
            { store_id: id },
            { preserveState: true, replace: true },
        );
    };

    const submitCreate = (e) => {
        e.preventDefault();
        if (!canEdit) return;
        form.post(route('planograms.sheet-import.store'), {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    const reparse = (e) => {
        e.preventDefault();
        router.post(
            route('planograms.sheet-import.reparse', sheetImport.id),
            { ocr_text: form.data.ocr_text || sheetImport.raw_ocr_text || '' },
            { preserveScroll: true },
        );
    };

    const saveRow = (row, patch) => {
        router.patch(route('planograms.sheet-import.rows.update', row.id), patch, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const commit = async (replaceExisting) => {
        const ok = await fireConfirm(
            replaceExisting
                ? 'Заменить текущую планограмму?'
                : 'Добавить товары на полки?',
            replaceExisting
                ? 'Существующие placements на этом оборудовании будут удалены, затем записаны строки OK.'
                : 'Строки со статусом OK будут добавлены к текущей расстановке.',
            'Записать в БД',
        );
        if (!ok) return;

        router.post(
            route('planograms.sheet-import.commit', sheetImport.id),
            { replace_existing: replaceExisting ? 1 : 0 },
            { preserveScroll: true },
        );
    };

    const okCount =
        sheetImport?.rows?.filter((r) => r.status === 'ok').length || 0;

    return (
        <AdminLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold text-white">
                            Импорт планограммы с листа A4
                        </h1>
                        <p className="mt-1 text-sm text-slate-400">
                            Сначала тот же выбор, что в «Планограммы»: магазин →
                            отдел → стеллаж. Потом лист → проверка → запись.
                        </p>
                    </div>
                    <Link
                        href={route('planograms.index', {
                            store_id: storeId || undefined,
                        })}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800"
                    >
                        <ArrowLeftIcon className="h-4 w-4" />
                        К планограммам
                    </Link>
                </div>
            }
        >
            <Head title="Импорт листа планограммы" />

            {!sheetImport ? (
                <form
                    onSubmit={submitCreate}
                    className="space-y-5 rounded-xl bg-[#152033] p-5 shadow-sm ring-1 ring-slate-800"
                >
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <label className="mb-1 block text-sm text-slate-300">
                                Магазин
                            </label>
                            <select
                                className={selectClass}
                                value={storeId}
                                onChange={(e) => changeStore(e.target.value)}
                            >
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                        {s.city ? ` — ${s.city}` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-sm text-slate-300">
                                Отдел
                            </label>
                            <select
                                className={selectClass}
                                value={departmentId}
                                onChange={(e) => setDepartmentId(e.target.value)}
                            >
                                {departments.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-sm text-slate-300">
                                Оборудование
                            </label>
                            <select
                                className={selectClass}
                                value={equipmentKey}
                                onChange={(e) => setEquipmentKey(e.target.value)}
                            >
                                {equipmentOptions.map((o) => (
                                    <option key={o.key} value={o.key}>
                                        {o.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    <div className="rounded-lg border border-slate-700 bg-[#0e172b] px-4 py-3 text-sm text-slate-300">
                        {tesseractAvailable ? (
                            <p>
                                Tesseract найден — фото можно распознать на
                                сервере (rus+eng). Всё равно проверьте строки.
                            </p>
                        ) : (
                            <p className="flex gap-2">
                                <ExclamationTriangleIcon className="h-5 w-5 shrink-0 text-amber-400" />
                                Tesseract не установлен. Загрузите фото для
                                архива и{' '}
                                <strong className="text-white">
                                    вставьте OCR-текст
                                </strong>{' '}
                                (Google Lens / Keep / любой OCR) — парсер вытащит
                                штрихкоды и фейсинги.
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="mb-1 block text-sm text-slate-300">
                            Фото / PDF листа A4
                        </label>
                        <label className="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-700 bg-[#0e172b] px-6 py-8 hover:border-indigo-500/50">
                            <DocumentArrowUpIcon className="mb-2 h-8 w-8 text-indigo-400" />
                            <span className="text-sm text-slate-200">
                                {form.data.image?.name ||
                                    'Выберите файл (до 20 МБ)'}
                            </span>
                            <input
                                type="file"
                                accept="image/*,.pdf"
                                className="hidden"
                                onChange={(e) =>
                                    form.setData(
                                        'image',
                                        e.target.files?.[0] || null,
                                    )
                                }
                            />
                        </label>
                        {form.errors.image && (
                            <p className="mt-1 text-sm text-rose-400">
                                {form.errors.image}
                            </p>
                        )}
                    </div>

                    <div>
                        <label className="mb-1 block text-sm text-slate-300">
                            OCR-текст с листа (рекомендуется)
                        </label>
                        <textarea
                            rows={8}
                            className={selectClass}
                            value={form.data.ocr_text}
                            onChange={(e) =>
                                form.setData('ocr_text', e.target.value)
                            }
                            placeholder="Вставьте распознанный текст: штрихкоды, названия, ф. 3, номера полок…"
                        />
                    </div>

                    <div className="flex justify-end">
                        <button
                            type="submit"
                            disabled={form.processing || !canEdit || !selectedEquipment}
                            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60"
                        >
                            {form.processing
                                ? 'Обработка…'
                                : 'Распознать и проверить'}
                        </button>
                    </div>
                </form>
            ) : (
                <div className="space-y-5">
                    <div className="rounded-xl bg-[#152033] p-5 ring-1 ring-slate-800">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p className="text-sm text-slate-400">
                                    Цель записи (как в Планограммах)
                                </p>
                                <p className="mt-1 text-lg font-semibold text-white">
                                    {sheetImport.store_name} ·{' '}
                                    {sheetImport.equipment_label}
                                </p>
                                <p className="mt-2 text-sm text-slate-400">
                                    Всего строк: {sheetImport.rows_total} · OK:{' '}
                                    {sheetImport.rows_matched} · записано:{' '}
                                    {sheetImport.rows_committed}
                                </p>
                                {sheetImport.message ? (
                                    <p className="mt-2 text-sm text-amber-300">
                                        {sheetImport.message}
                                    </p>
                                ) : null}
                            </div>
                            {sheetImport.status !== 'committed' && canEdit ? (
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => commit(false)}
                                        disabled={okCount < 1}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                                    >
                                        <CheckCircleIcon className="h-4 w-4" />
                                        Добавить OK ({okCount})
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => commit(true)}
                                        disabled={okCount < 1}
                                        className="rounded-lg border border-rose-500/40 px-3 py-2 text-sm font-semibold text-rose-200 hover:bg-rose-500/10 disabled:opacity-50"
                                    >
                                        Заменить планограмму
                                    </button>
                                </div>
                            ) : null}
                        </div>

                        {sheetImport.image_url ? (
                            <img
                                src={sheetImport.image_url}
                                alt="Лист планограммы"
                                className="mt-4 max-h-64 rounded-lg border border-slate-700 object-contain"
                            />
                        ) : null}
                    </div>

                    <form
                        onSubmit={reparse}
                        className="rounded-xl bg-[#152033] p-4 ring-1 ring-slate-800"
                    >
                        <label className="mb-1 block text-sm text-slate-300">
                            OCR-текст (можно поправить и пересобрать строки)
                        </label>
                        <textarea
                            rows={5}
                            className={selectClass}
                            defaultValue={sheetImport.raw_ocr_text || ''}
                            onChange={(e) =>
                                form.setData('ocr_text', e.target.value)
                            }
                        />
                        {canEdit && sheetImport.status !== 'committed' ? (
                            <button
                                type="submit"
                                className="mt-3 rounded-lg border border-slate-600 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-800"
                            >
                                Пересобрать строки
                            </button>
                        ) : null}
                    </form>

                    <div className="overflow-hidden rounded-xl ring-1 ring-slate-800">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-800 text-sm">
                                <thead className="bg-slate-900 text-left text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th className="px-3 py-2.5">#</th>
                                        <th className="px-3 py-2.5">Полка</th>
                                        <th className="px-3 py-2.5">Штрихкод</th>
                                        <th className="px-3 py-2.5">Название OCR / каталог</th>
                                        <th className="px-3 py-2.5">Фейс</th>
                                        <th className="px-3 py-2.5">Статус</th>
                                        <th className="px-3 py-2.5" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800 bg-[#152033]">
                                    {(sheetImport.rows || []).map((row) => (
                                        <tr key={row.id} className="align-top text-slate-200">
                                            <td className="px-3 py-2.5 text-slate-500">
                                                {row.sort_order}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <select
                                                    className={inputClass}
                                                    disabled={
                                                        !canEdit ||
                                                        sheetImport.status ===
                                                            'committed'
                                                    }
                                                    value={row.level_number || ''}
                                                    onChange={(e) =>
                                                        saveRow(row, {
                                                            level_number:
                                                                e.target.value ||
                                                                null,
                                                        })
                                                    }
                                                >
                                                    <option value="">—</option>
                                                    {(sheetImport.levels || []).map(
                                                        (l) => (
                                                            <option
                                                                key={l.id}
                                                                value={
                                                                    l.level_number
                                                                }
                                                            >
                                                                {l.level_number}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <input
                                                    className={clsx(
                                                        inputClass,
                                                        'font-mono',
                                                    )}
                                                    disabled={
                                                        !canEdit ||
                                                        sheetImport.status ===
                                                            'committed'
                                                    }
                                                    defaultValue={row.barcode || ''}
                                                    onBlur={(e) => {
                                                        if (
                                                            e.target.value !==
                                                            (row.barcode || '')
                                                        ) {
                                                            saveRow(row, {
                                                                barcode:
                                                                    e.target
                                                                        .value,
                                                            });
                                                        }
                                                    }}
                                                />
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <p className="text-xs text-slate-400">
                                                    OCR: {row.product_name_ocr || '—'}
                                                </p>
                                                <p className="font-medium text-white">
                                                    {row.product_name ||
                                                        '— не найден —'}
                                                </p>
                                                {row.note ? (
                                                    <p className="mt-1 text-xs text-amber-300">
                                                        {row.note}
                                                    </p>
                                                ) : null}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <input
                                                    type="number"
                                                    min={1}
                                                    max={99}
                                                    className={inputClass}
                                                    disabled={
                                                        !canEdit ||
                                                        sheetImport.status ===
                                                            'committed'
                                                    }
                                                    defaultValue={row.facings}
                                                    onBlur={(e) => {
                                                        const v = Number(
                                                            e.target.value,
                                                        );
                                                        if (
                                                            v &&
                                                            v !== row.facings
                                                        ) {
                                                            saveRow(row, {
                                                                facings: v,
                                                            });
                                                        }
                                                    }}
                                                />
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {statusBadge(row.status)}
                                            </td>
                                            <td className="px-3 py-2.5 text-right">
                                                {canEdit &&
                                                sheetImport.status !==
                                                    'committed' &&
                                                row.status !== 'skipped' ? (
                                                    <button
                                                        type="button"
                                                        className="text-xs text-slate-400 hover:text-rose-300"
                                                        onClick={() =>
                                                            saveRow(row, {
                                                                status: 'skipped',
                                                            })
                                                        }
                                                    >
                                                        Пропуск
                                                    </button>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
