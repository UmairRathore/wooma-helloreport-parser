<?php
declare(strict_types=1);


/**
 * HelloReport (text-based PDF) → Wooma JSON Parser (NO OCR, NO INFERENCE)
 *
 * Usage:
 *   php bin/parse.php /path/to/report.pdf --user-id="UUID" --report-type-id="UUID" --property-id="UUID" --output=/path/out.json
 *
 * Notes:
 * - Extracts only text explicitly present in PDF (HelloReport is text-based).
 * - Ignores images/photo URLs.
 * - Missing fields => null (never guessed).
 *
 * Requires (composer):
 *   smalot/pdfparser
 *   ramsey/uuid
 */




namespace WoomaHelloReport;
require __DIR__ . '/../vendor/autoload.php';

use Ramsey\Uuid\Uuid;
use Smalot\PdfParser\Parser;

/* -----------------------------
 | CLI Entrypoint (bin/parse.php)
 * ----------------------------- */
if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $args = new CliArgs($argv);

    $pdfPath = $args->getPositional(0);

    if (!$pdfPath || !is_file($pdfPath)) {
        fwrite(STDERR, "ERROR: PDF path is required and must exist.\n");
        exit(1);
    }

    $userId = $args->getOption('user-id') ?? null;
    $reportTypeId = $args->getOption('report-type-id') ?? null;
    $propertyId = $args->getOption('property-id') ?? null;

    // You can keep these optional and let downstream assign IDs, but Wooma structure expects IDs.
    // We will generate placeholder UUIDs when not provided, but DO NOT invent business IDs.
    $userId = $userId ?: Uuid::uuid4()->toString();
    $reportTypeId = $reportTypeId ?: Uuid::uuid4()->toString();
    $propertyId = $propertyId ?: Uuid::uuid4()->toString();

    $output = $args->getOption('output');

    if (!$output) {
        $baseName = pathinfo($pdfPath, PATHINFO_FILENAME);
        $outputDir = __DIR__ . '/../result';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $output = $outputDir . DIRECTORY_SEPARATOR . $baseName . '.json';
    }


    $extractor = new PdfTextExtractor();
    $text = $extractor->extract($pdfPath);

    $hr = new HelloReportParser();
    $parsed = $hr->parse($text);

    $mapper = new WoomaMapper();
    $wooma = $mapper->mapToWooma($parsed, $userId, $propertyId, $reportTypeId, $pdfPath);

    $json = json_encode($wooma, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        fwrite(STDERR, "ERROR: Failed to encode JSON.\n");
        exit(1);
    }

    if ($output) {
        file_put_contents($output, $json);
        fwrite(STDOUT, "OK: Wrote JSON to {$output}\n");
    } else {
        fwrite(STDOUT, $json . PHP_EOL);
    }
    exit(0);
}

/* -----------------------------
 | CLI Args Helper
 * ----------------------------- */
final class CliArgs
{
    /** @var string[] */
    private array $argv;
    /** @var array<string, string|true> */
    private array $options = [];
    /** @var string[] */
    private array $positionals = [];

    /**
     * @param string[] $argv
     */
    public function __construct(array $argv)
    {
        $this->argv = $argv;
        $this->parse();
    }

    private function parse(): void
    {
        $args = $this->argv;
        array_shift($args); // script

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $eqPos = strpos($arg, '=');
                if ($eqPos === false) {
                    $key = ltrim($arg, '-');
                    $this->options[$key] = true;
                } else {
                    $key = ltrim(substr($arg, 0, $eqPos), '-');
                    $val = substr($arg, $eqPos + 1);
                    $this->options[$key] = trim($val, "\"'");
                }
            } else {
                $this->positionals[] = $arg;
            }
        }
    }

    public function getOption(string $key): ?string
    {
        $val = $this->options[$key] ?? null;
        if ($val === true || $val === null) return null;
        return (string) $val;
    }

    public function getPositional(int $index): ?string
    {
        return $this->positionals[$index] ?? null;
    }

}

/* -----------------------------
 | PDF Text Extractor (NO OCR)
 * ----------------------------- */
final class PdfTextExtractor
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Extract text from a *text-based* PDF. No OCR.
     */
    public function extract(string $pdfPath): string
    {
        $pdf = $this->parser->parseFile($pdfPath);
        $text = $pdf->getText();

        // Normalize whitespace
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/", " ", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        // 👇 DEBUG OUTPUT (TEMPORARY)
        $debugDir = __DIR__ . '/../debug';
        if (!is_dir($debugDir)) {
            mkdir($debugDir, 0777, true);
        }

        $base = pathinfo($pdfPath, PATHINFO_FILENAME);
        file_put_contents(
            $debugDir . '/' . $base . '.raw.txt',
            $text
        );

        return trim($text);
    }

}

/* -----------------------------
 | HelloReport Parser (Template-driven)
 * ----------------------------- */
final class HelloReportParser
{
    /**
     * Output is an intermediate normalized structure (NOT Wooma yet).
     * Missing fields => null. Never inferred.
     *
     * @return array<string, mixed>
     */
    public function parse(string $text): array
    {
        // Remove photo URLs completely (not needed; client said "not images")
        $textNoUrls = preg_replace('~https?://\S+~', '', $text) ?? $text;

        $property = $this->parsePropertyHeader($textNoUrls);
        $checklist = $this->parseChecklist($textNoUrls);
        $reportSummary = $this->parseReportSummary($textNoUrls);
        $meters = $this->parseMeters($textNoUrls);
        $keys = $this->parseKeys($textNoUrls);
        $detectors = $this->parseDetectors($textNoUrls);
        $externalAreas = $this->parseExternalAreas($textNoUrls);
        $rooms = $this->parseInspectionAreas($textNoUrls);

        return [
            'property' => $property,
            'checklist' => $checklist,
            'report_summary' => $reportSummary,
            'meters' => $meters,
            'keys' => $keys,
            'detectors' => $detectors,
            'external_areas' => $externalAreas,
            'rooms' => $rooms,
        ];
    }

    /**
     * Parses: address + appointment date + assessor (if present).
     * @return array{address: ?string, postcode:?string, city:?string, appointment_date:?string, assessor:?string}
     */
    private function parsePropertyHeader(string $text): array
    {
        // Example header often contains:
        // "Appointment Date" + "Assessor" + "Inventory / Check In for" + "2 Riverhead Gardens, Driffield, YO25 6AA"
        $appointmentDate = $this->matchOne($text, '~Appointment Date\s*\n\s*([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})~');
        $assessor = $this->matchOne($text, '~Assessor\s*\n\s*([^\n]+)~');

        // Address line is usually after "Inventory / Check In\nfor"
        $addressLine = $this->matchOne($text, '~Inventory\s*/\s*Check In\s*\n\s*for\s*\n\s*([^\n]+)~');

        [$address, $city, $postcode] = $this->splitUkAddressLine($addressLine);

        return [
            'address' => $address,
            'city' => $city,
            'postcode' => $postcode,
            'appointment_date' => $appointmentDate,
            'assessor' => $assessor,
        ];
    }

    /**
     * Minimal checklist extraction:
     * - YES/NO questions => question_answers[]
     * - Free text fields => field_answers[]
     *
     * This is template dependent; adjust regex keys as you learn real question labels from PDFs.
     *
     * @return array{question_answers: array<int, array{question_text:string, answer_option:?string, answer_text:?string}>, field_answers: array<int, array{field_label:string, answer_text:?string}>}
     */
    private function parseChecklist(string $text): array
    {
        // Common HelloReport snippet:
        // "Valid gas safety record present?\n\nNO"
        // "Smoke alarms and CO detectors present?\n\nNO"
        $q1 = $this->matchYesNoBlock($text, 'Valid gas safety record present\?');
        $q2 = $this->matchYesNoBlock($text, 'Smoke alarms and CO detectors present\?');

        $qas = [];
        if ($q1 !== null) $qas[] = $q1;
        if ($q2 !== null) $qas[] = $q2;

        // If there are other free-text fields in checklist (tenant name, landlord, etc.), add them here.
        // For now, keep deterministic: only what we can explicitly match.
        $fields = [];

        return [
            'question_answers' => $qas,
            'field_answers' => $fields,
        ];
    }

    /**
     * @return array{areas: array<int, array{name:string, condition:?string, cleanliness:?string}>}
     */
    private function parseReportSummary(string $text): array
    {
        // Detect "Report Summary" table block, then parse area rows.
        $block = $this->sliceBetween($text, "Report Summary", "Meters");
        if ($block === null) {
            return ['areas' => []];
        }

        // Rows look like:
        // "Entrance/Hallway Good Good ..."
        // We'll parse first 3 columns: name, condition, cleanliness.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn($l) => $l !== ''));
        $areas = [];

        foreach ($lines as $line) {
            // Skip header lines
            if (stripos($line, 'Inspection Areas') !== false) continue;
            if (stripos($line, 'Condition') !== false && stripos($line, 'Cleanliness') !== false) continue;

            // Try: "<Name> <Condition> <Cleanliness>"
            if (preg_match('~^(.+?)\s+(Excellent|Good|Fair|Poor|Unacceptable)\s+(Excellent|Good|Fair|Poor|Unacceptable)\b~i', $line, $m)) {
                $areas[] = [
                    'name' => trim($m[1]),
                    'condition' => $this->normalizeRating($m[2]),
                    'cleanliness' => $this->normalizeRating($m[3]),
                ];
            }
        }

        return ['areas' => $areas];
    }

    /**
     * @return array<int, array{energy_type:?string, date:?string, reading:?string, location:?string, serial_number:?string, meter_type:?string, name:?string}>
     */
    /**
     * IMPORTANT FIX:
     * The PDF text contains "Meters" multiple times (e.g., contents/header + real section).
     * We must pick the "Meters -> Keys" block that actually contains the table header/data.
     *
     * @return array<int, array{energy_type:?string, date:?string, reading:?string, location:?string, serial_number:?string, meter_type:?string, name:?string}>
     */
    private function parseMeters(string $text): array
    {
        $block = $this->sliceBetweenBest(
            $text,
            "Meters",
            "Keys",
            // must contain table header OR at least one known fuel token + a date-ish pattern
            '~Energy Type|Electricity|Gas|Water~i'
        );

        if ($block === null) return [];

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $block)),
            static fn ($l) => $l !== ''
        ));

        $meters = [];

        foreach ($lines as $line) {
            // Skip header-ish lines
            if (stripos($line, 'Energy Type') !== false) continue;
            if (preg_match('~^15\s+January\s+\d{4}~i', $line)) continue; // footer lines sometimes get merged
            if (preg_match('~page\s+\d+\s+of\s+\d+~i', $line)) continue;

            /**
             * FULL TABLE FORMAT (when present in extracted text):
             * Electricity 20 Jan 2026 03598 Hall cupboard Tariff
             * Gas 20 Jan 2026 12345 Utility cupboard Standard
             *
             * Notes:
             * - location may have spaces
             * - meter_type is typically last token (Tariff/Standard/Smart/etc)
             */
            if (preg_match(
                '~^(Electricity|Gas|Water)\s+'
                . '([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})\s+'
                . '([0-9]+(?:\.[0-9]+)?)\s+'
                . '(.+?)\s+'
                . '(Tariff|Standard|Smart)\b~i',
                $line,
                $m
            )) {
                $energy = ucfirst(strtolower($m[1]));
                $meters[] = [
                    'energy_type'   => $energy,
                    'date'          => $m[2],
                    'reading'       => $m[3],
                    'location'      => trim($m[4]),
                    'serial_number' => null,
                    'meter_type'    => $m[5] ?? null,
                    'name'          => $energy . ' Meter',
                ];
                continue;
            }

            /**
             * SIMPLE FORMAT (your report3.raw.txt has this):
             * Electricity 15 Jan 2026 30877.57
             * Gas 15 Jan 2026 21141.77
             */
            if (preg_match(
                '~^(Electricity|Gas|Water)\s+'
                . '([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})\s+'
                . '([0-9]+(?:\.[0-9]+)?)$~i',
                $line,
                $m
            )) {
                $energy = ucfirst(strtolower($m[1]));
                $meters[] = [
                    'energy_type'   => $energy,
                    'date'          => $m[2],
                    'reading'       => $m[3],
                    'location'      => null,
                    'serial_number' => null,
                    'meter_type'    => null,
                    'name'          => $energy . ' Meter',
                ];
            }
        }

        return $meters;
    }

    /**
     * Find the correct section block when headings repeat (contents/header vs real section).
     * We scan all occurrences of $start and choose the first candidate block that matches $mustContainRegex.
     */
    private function sliceBetweenBest(string $text, string $start, string $end, ?string $mustContainRegex = null): ?string
    {
        $offset = 0;

        while (($s = stripos($text, $start, $offset)) !== false) {
            $e = stripos($text, $end, $s + strlen($start));
            if ($e === false) {
                $offset = $s + strlen($start);
                continue;
            }

            $candidate = trim(substr(
                $text,
                $s + strlen($start),
                $e - ($s + strlen($start))
            ));

            if ($candidate === '') {
                $offset = $s + strlen($start);
                continue;
            }

            if ($mustContainRegex === null || preg_match($mustContainRegex, $candidate)) {
                return $candidate;
            }

            $offset = $s + strlen($start);
        }

        return null;
    }


    /**
     * Keys section in many HelloReport PDFs is often sparse in text and photo-driven.
     * We do NOT infer. We only return what we can explicitly read.
     *
     * @return array<int, array{name:?string, description:?string, note:?string, no_of_keys:?int}>
     */
    private function parseKeys(string $text): array
    {
        $block = $this->sliceBetweenBest(
            $text,
            'Keys',
            'Detectors',
            '/General key/i'
        );
        if ($block === null) return [];

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $block))
        ));

        // remove header line(s)
        $lines = array_values(array_filter($lines, fn ($l) =>
            stripos($l, 'General key') === false
        ));

        if (count($lines) === 0) return [];

        return [[
            'name'        => 'Property Keys',
            'description' => null,
            'note'        => implode(' ', $lines),
            'no_of_keys'  => null,
        ]];
    }


    /**
     * @return array<int, array{name:?string, location:?string, note:?string, tested:?string}>
     */
    private function parseDetectors(string $text): array
    {
        $block = $this->sliceBetweenBest(
            $text,
            'Detectors',
            'External Areas',
            '/Key\s+type\s+Location\s+of\s+the\s+detector/i'
        );

        if ($block === null) return [];

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $block)),
            fn ($l) => $l !== ''
        ));

        $detectors = [];
        $currentType = null;

        foreach ($lines as $line) {

            // Stop at general section
            if (stripos($line, 'General detector details') !== false) {
                break;
            }

            // Skip headers
            if (stripos($line, 'Key type') !== false) continue;

            // Skip footer noise
            if (preg_match('~page\s+\d+\s+of\s+\d+~i', $line)) continue;

            /**
             * FULL ROW
             * Smoke alarm Second floor landing Yes
             * Co detector Dining Room Yes
             */
            if (preg_match(
                '~^(Co detector|Smoke alarm)\s+(.+?)\s+(Yes|No)$~i',
                $line,
                $m
            )) {
                $currentType = $m[1];

                $detectors[] = [
                    'name'     => $currentType,
                    'location' => $m[2],
                    'note'     => null,
                        'tested'   => strtoupper($m[3]),
                ];
                continue;
            }

            /**
             * CONTINUATION ROW
             * Top floor bedroom Yes
             * Living room Yes
             */
            if ($currentType && preg_match(
                    '~^(.+?)\s+(Yes|No)$~i',
                    $line,
                    $m
                )) {
                $detectors[] = [
                    'name'     => $currentType,
                    'location' => $m[1],
                    'note'     => null,
                    'tested'   => strtoupper($m[2]),
                ];
            }
        }

        return $detectors;
    }

    /**
     * @return array{description:?string}
     */
    private function parseExternalAreas(string $text): array
    {
        $block = $this->sliceBetween($text, "External Areas", "Inspection Areas");
        if ($block === null) return ['description' => null];

        $desc = $this->matchOne($block, '~Description\s*\n\s*([\s\S]+)~');
        return ['description' => $desc ? trim($desc) : null];
    }

    /**
     * Parse rooms under "Inspection Areas" and each area’s General Overview block.
     *
     * @return array<int, array{
     *   name:string,
     *   condition:?string,
     *   cleanliness:?string,
     *   description:?string,
     *   defects:?string
     * }>
     */
    private function parseInspectionAreas(string $text): array
    {
        $startPos = stripos($text, "Inspection Areas");
        if ($startPos === false) return [];
        $inspection = substr($text, $startPos);

        // Pattern: "1: Entrance/Hallway" then content until next "\n\d+: " or end
        preg_match_all('~\n(\d+:\s*[^\n]+)\n~', $inspection, $matches, PREG_OFFSET_CAPTURE);
        if (empty($matches[1])) return [];

        $rooms = [];
        $count = count($matches[1]);

        for ($i = 0; $i < $count; $i++) {
            $title = trim($matches[1][$i][0]);
            $offset = $matches[1][$i][1];

            $nextOffset = ($i + 1 < $count) ? $matches[1][$i + 1][1] : strlen($inspection);
            $chunk = substr($inspection, $offset, $nextOffset - $offset);

            // Room name: remove leading "N:" prefix
            $name = preg_replace('~^\d+:\s*~', '', $title) ?? $title;

            // Extract condition/cleanliness from the "Name Date Condition Cleanliness Description" line if present
            // Often: "X:1 General Overview 15 Jan 2026 Good Good ..."
            $condition = null;
            $cleanliness = null;
            if (preg_match('~\b(Excellent|Good|Fair|Poor|Unacceptable)\b\s+\b(Excellent|Good|Fair|Poor|Unacceptable)\b~i', $chunk, $m)) {
                $condition = $this->normalizeRating($m[1]);
                $cleanliness = $this->normalizeRating($m[2]);
            }

            // Description block: after "Description" heading until "Defects" (if exists)
            $desc = null;
            $defects = null;

            $descBlock = $this->sliceBetween($chunk, "Description", "Defects");
            if ($descBlock !== null) {
                $desc = trim($descBlock);
            } else {
                // Some PDFs list description inline without headings; avoid guessing.
                $desc = null;
            }

            // Defects: everything after "Defects"
            $defectsBlock = $this->sliceAfter($chunk, "Defects");
            if ($defectsBlock !== null) {
                $defects = trim($defectsBlock);
                // Sometimes defects repeat photo captions; already removed URLs above.
                $defects = $defects !== '' ? $defects : null;
            }

            $rooms[] = [
                'name' => $name,
                'condition' => $condition,
                'cleanliness' => $cleanliness,
                'description' => $desc,
                'defects' => $defects,
            ];
        }

        return $rooms;
    }

    /* -----------------------------
     | Helpers
     * ----------------------------- */

    private function matchOne(string $text, string $regex): ?string
    {
        if (preg_match($regex, $text, $m)) {
            return trim((string) $m[1]);
        }
        return null;
    }

    /**
     * @return array{question_text:string, answer_option:?string, answer_text:?string}|null
     */
    private function matchYesNoBlock(string $text, string $questionRegexEscaped): ?array
    {
        // We capture:
        // Question line
        // Next non-empty line as YES/NO
        $pattern = '~(' . $questionRegexEscaped . ')\s*\n\s*\n\s*(YES|NO)\b~i';
        if (!preg_match($pattern, $text, $m)) return null;

        return [
            'question_text' => trim($m[1]),
            'answer_option' => strtoupper($m[2]),
            'answer_text' => null,
        ];
    }

    private function normalizeRating(string $value): ?string
    {
        $v = strtolower(trim($value));
        return match ($v) {
            'excellent' => 'EXCELLENT',
            'good' => 'GOOD',
            'fair' => 'FAIR',
            'poor' => 'POOR',
            'unacceptable' => 'UNACCEPTABLE',
            default => null,
        };
    }

    /**
     * Attempt to split: "2 Riverhead Gardens, Driffield, YO25 6AA"
     * @return array{0:?string,1:?string,2:?string}
     */
    private function splitUkAddressLine(?string $line): array
    {
        if ($line === null) return [null, null, null];
        $parts = array_map('trim', explode(',', $line));

        // Deterministic rules:
        // - postcode is last token if it matches UK-ish pattern.
        // - city is second token when present.
        $postcode = null;
        $city = null;
        $address = null;

        if (count($parts) === 1) {
            $address = $parts[0];
            return [$address ?: null, null, null];
        }

        // Last part might be postcode
        $last = $parts[count($parts) - 1] ?? '';
        if (preg_match('~\b[A-Z]{1,2}\d{1,2}[A-Z]?\s*\d[A-Z]{2}\b~i', $last)) {
            $postcode = strtoupper(trim($last));
            array_pop($parts);
        }

        if (count($parts) >= 2) {
            $city = $parts[count($parts) - 1];
            array_pop($parts);
        }

        $address = implode(', ', $parts);

        return [
            $address !== '' ? $address : null,
            $city !== '' ? $city : null,
            $postcode,
        ];
    }

    private function sliceBetween(string $text, string $start, string $end): ?string
    {
        $s = stripos($text, $start);
        if ($s === false) return null;
        $e = stripos($text, $end, $s + strlen($start));
        if ($e === false) return trim(substr($text, $s + strlen($start)));
        return trim(substr($text, $s + strlen($start), $e - ($s + strlen($start))));
    }

    private function sliceAfter(string $text, string $start): ?string
    {
        $s = stripos($text, $start);
        if ($s === false) return null;
        return trim(substr($text, $s + strlen($start)));
    }
}

/* -----------------------------
 | Wooma Mapper (strict schema)
 * ----------------------------- */

final class WoomaMapper
{
    public function mapToWooma(
        array $parsed,
        string $userId,
        string $propertyId,
        string $reportTypeId
    ): array {
        $reportId = Uuid::uuid4()->toString();

        return [
            'property' => [
                'user_id' => $userId,
                'address' => $parsed['property']['address'] ?? null,
                'postcode' => $parsed['property']['postcode'] ?? null,
                'city' => $parsed['property']['city'] ?? null,

                'reports' => [
                    [
                        'id' => $reportId,
                        'property_id' => $propertyId,
                        'report_type_id' => $reportTypeId,
                        'status' => 'IN_PROGRESS',
                        'completion_percentage' => null,
                        'completion_date' => null,
                        'pdf_url' => null,
                        'pdf_generated_at' => null,
                        'is_paid' => false,
                        'payment_date' => null,

                        'rooms' => $this->mapRooms($reportId, $parsed['rooms'] ?? []),
                        'meters' => $this->mapMeters($reportId, $parsed['meters'] ?? []),
                        'keys' => $this->mapKeys($reportId, $parsed['keys'] ?? []),
                        'detectors' => $this->mapDetectors($reportId, $parsed['detectors'] ?? []),
                        'report_checklists' => $this->mapChecklists($reportId, $parsed['checklist'] ?? []),
                    ],
                ],
            ],
        ];
    }

    /* ---------------- ROOMS ---------------- */

    private function mapRooms(string $reportId, array $rooms): array
    {
        $out = [];

        foreach ($rooms as $room) {
            $roomId = Uuid::uuid4()->toString();

            $out[] = [
                'report_id' => $reportId,
                'name' => $room['name'] ?? null,
                'items' => [
                    [
                        'room_id' => $roomId,
                        'name' => 'General Overview',
                        'general_condition' => $room['condition'] ?? null,
                        'general_cleanliness' => $room['cleanliness'] ?? null,
                        'description' => $room['description'] ?? null,
                        'note' => $room['defects'] ?? null,
                    ],
                ],
            ];
        }

        return $out;
    }

    /* ---------------- METERS ---------------- */

    private function mapMeters(string $reportId, array $meters): array
    {
        return array_map(fn ($m) => [
            'report_id' => $reportId,
            'name' => $m['name'] ?? null,
            'reading' => $m['reading'] ?? null,
            'location' => $m['location'] ?? null,
            'serial_number' => $m['serial_number'] ?? null,
        ], $meters);
    }

    /* ---------------- KEYS ---------------- */

    private function mapKeys(string $reportId, array $keys): array
    {
        return array_map(fn ($k) => [
            'report_id' => $reportId,
            'name' => $k['name'] ?? null,
            'description' => $k['description'] ?? null,
            'note' => $k['note'] ?? null,
            'no_of_keys' => $k['no_of_keys'] ?? null,
        ], $keys);
    }

    /* ---------------- DETECTORS ---------------- */

    private function mapDetectors(string $reportId, array $detectors): array
    {
        return array_map(fn ($d) => [
            'report_id' => $reportId,
            'name' => $d['name'] ?? null,
            'location' => $d['location'] ?? null,
            'note' => $d['note'] ?? null,
            'tested'    => $d['tested'] ?? null,
        ], $detectors);
    }

    /* ---------------- CHECKLISTS ---------------- */

    private function mapChecklists(string $reportId, array $checklist): array
    {
        if (empty($checklist)) return [];

        $reportChecklistId = Uuid::uuid4()->toString();

        return [[
            'report_id' => $reportId,
            'checklist_id' => null,

            'question_answers' => array_map(fn ($qa) => [
                'report_checklist_id' => $reportChecklistId,
                'checklist_question_id' => null,
                'answer_option' => $qa['answer_option'] ?? null,
                'answer_text' => $qa['answer_text'] ?? null,
            ], $checklist['question_answers'] ?? []),

            'field_answers' => array_map(fn ($fa) => [
                'report_checklist_id' => $reportChecklistId,
                'checklist_field_id' => null,
                'answer_text' => $fa['answer_text'] ?? null,
            ], $checklist['field_answers'] ?? []),
        ]];
    }
}
