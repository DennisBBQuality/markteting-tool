<?php

namespace App\Services\Trunkrs;

use ZipArchive;

class TrunkrsReportParser
{
    public const VERSION = 'trunkrs-csv-v1';

    public const MAX_BYTES = 2_000_000;

    public const HEADERS = ['', 'Date Date', 'Merchant Name', 'Trunkrs Nr', 'Barcode', 'Status', 'Reason Code'];

    public function parse(string $bytes, string $filename): array
    {
        if (strlen($bytes) > self::MAX_BYTES || $bytes === '') {
            throw new TrunkrsException('invalid_report');
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $csv = match ($extension) {
            'csv' => $bytes,
            'zip' => $this->unzip($bytes),
            default => throw new TrunkrsException('invalid_report'),
        };

        if (! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            throw new TrunkrsException('invalid_report');
        }
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv));
            rewind($stream);
            $header = fgetcsv($stream, null, ',', '"', '');
            if ($header !== self::HEADERS) {
                throw new TrunkrsException('invalid_report');
            }
            $rows = [];
            $seen = [];
            $date = null;
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 7 || count($rows) >= 5000) {
                    throw new TrunkrsException('invalid_report');
                }
                foreach ($row as $value) {
                    if (mb_strlen($value) > 250 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                        throw new TrunkrsException('invalid_report');
                    }
                }
                [, $rowDate, $merchant, $number, $barcode, $status, $reason] = $row;
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rowDate)
                    || ! checkdate((int) substr($rowDate, 5, 2), (int) substr($rowDate, 8, 2), (int) substr($rowDate, 0, 4))
                    || ($date !== null && $date !== $rowDate)
                    || trim($merchant) === '' || trim($number) === '' || trim($barcode) === ''
                    || ! preg_match('/^[A-Z][A-Z0-9_]{1,120}$/D', $status)
                    || isset($seen[$number])) {
                    throw new TrunkrsException('invalid_report');
                }
                $seen[$number] = true;
                $date = $rowDate;
                // All statuses stay in the same collection, including cancellations.
                $rows[] = ['trunkrs_number' => $number, 'barcode' => $barcode, 'status' => $status, 'reason_code' => $reason];
            }

            // An empty CSV has no Date Date value. Do not invent a date from arrival time.
            return ['report_date' => $date, 'shipments' => $rows, 'shipment_count' => count($rows)];
        } finally {
            fclose($stream);
        }
    }

    private function unzip(string $bytes): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new TrunkrsException('configuration');
        }
        $path = tempnam(sys_get_temp_dir(), 'pitboard-trunkrs-');
        if ($path === false) {
            throw new TrunkrsException('invalid_report');
        }
        chmod($path, 0600);
        $zip = new ZipArchive;
        $opened = false;
        try {
            file_put_contents($path, $bytes);
            $opened = $zip->open($path, ZipArchive::CHECKCONS) === true;
            if (! $opened || $zip->numFiles > 10) {
                throw new TrunkrsException('invalid_report');
            }
            $csv = null;
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (! $entry || str_starts_with($entry['name'], '/') || str_contains($entry['name'], '\\')
                    || in_array('..', explode('/', $entry['name']), true) || ($entry['encryption_method'] ?? 0) !== 0) {
                    throw new TrunkrsException('invalid_report');
                }
                $size += $entry['size'];
                if ($size > self::MAX_BYTES) {
                    throw new TrunkrsException('invalid_report');
                }
                if (str_ends_with($entry['name'], '/')) {
                    continue;
                }
                if ($csv !== null || strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) !== 'csv') {
                    throw new TrunkrsException('invalid_report');
                }
                $csv = $zip->getFromIndex($i, self::MAX_BYTES + 1);
                if ($csv === false || strlen($csv) !== $entry['size']) {
                    throw new TrunkrsException('invalid_report');
                }
            }

            return $csv ?? throw new TrunkrsException('invalid_report');
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($path);
        }
    }
}
