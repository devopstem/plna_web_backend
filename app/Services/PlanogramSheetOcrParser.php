<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Extracts barcode / facings / shelf level hints from A4 planogram OCR text.
 * Works with Tesseract output or pasted text from the sheet.
 */
class PlanogramSheetOcrParser
{
    /**
     * @return list<array{level_number: int|null, barcode: string, product_name_ocr: string|null, facings: int}>
     */
    public function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split('/\n+/', $text) ?: [];

        $currentLevel = null;
        $items = [];
        $seen = [];

        foreach ($lines as $rawLine) {
            $line = trim(preg_replace('/\s+/', ' ', $rawLine) ?? '');

            if ($line === '') {
                continue;
            }

            if (preg_match('/(?:полк[аие]|уровень|shelf|ур\.?)\s*[#№:]?\s*(\d{1,2})/ui', $line, $m)) {
                $currentLevel = (int) $m[1];
            } elseif (preg_match('/^(\d{1,2})\s*[.\)]\s*$/u', $line, $m)) {
                $currentLevel = (int) $m[1];
            }

            if (! preg_match_all('/\b(\d{8,14})\b/', $line, $barcodeMatches)) {
                continue;
            }

            $facings = 1;
            if (preg_match('/ф\.?\s*[x×*]?\s*(\d{1,2})/ui', $line, $fm)) {
                $facings = max(1, (int) $fm[1]);
            } elseif (preg_match('/(?:facing|facings|фейс)\s*[:=]?\s*(\d{1,2})/ui', $line, $fm)) {
                $facings = max(1, (int) $fm[1]);
            }

            $name = $line;
            foreach ($barcodeMatches[1] as $barcode) {
                $name = str_replace($barcode, ' ', $name);
            }
            $name = preg_replace('/ф\.?\s*[x×*]?\s*\d{1,2}/ui', ' ', $name) ?? $name;
            $name = preg_replace('/№\s*\d+/u', ' ', $name) ?? $name;
            $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
            if (mb_strlen($name) < 2) {
                $name = null;
            }

            foreach ($barcodeMatches[1] as $barcode) {
                $key = $barcode.'|'.($currentLevel ?? 'x');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $items[] = [
                    'level_number' => $currentLevel,
                    'barcode' => $barcode,
                    'product_name_ocr' => $name,
                    'facings' => $facings,
                ];
            }
        }

        // If OCR never printed shelf labels, try to infer from "block" order later in matching.
        return $items;
    }

    public function recognizeImage(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $binary = trim((string) shell_exec('command -v tesseract 2>/dev/null'));

        if ($binary === '') {
            return null;
        }

        $process = new Process([
            $binary,
            $absolutePath,
            'stdout',
            '-l',
            'rus+eng',
            '--psm',
            '6',
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $text = trim($process->getOutput());

        return $text !== '' ? $text : null;
    }
}
