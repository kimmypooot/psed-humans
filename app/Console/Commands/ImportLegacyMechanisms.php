<?php

namespace App\Console\Commands;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Models\Agency;
use App\Models\FieldOffice;
use App\Models\HrMechanism;
use App\Support\ActionOfficers;
use App\Support\AgencyMapper;
use Carbon\Carbon;
use Database\Seeders\FieldOfficeSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

#[Signature('mechanisms:import-legacy {file : The 2019-2026 HR Mechanism Monitoring workbook (.xlsx)} {--fresh : Remove the earlier import first} {--dry-run : Count what would be imported and save nothing}')]
#[Description('Imports the per Field Office sheets of the HR mechanism monitoring workbook as agencies and mechanisms')]
class ImportLegacyMechanisms extends Command
{
    /** Header text (upper case, first match wins) => the field it fills. Where a header repeats, the first column is used. */
    private const HEADERS = [
        'agency' => 'NAME OF AGENCY',
        'mechanism' => 'HR MECHANISM',
        'copies' => 'NO. OF COPIES',
        'processor' => 'PROCESSOR',
        'received' => 'DATE RECEIVED',
        'processed' => 'DATE PROCESSED',
        'evaluated' => 'DATE EVALUATED BY PSED STAFF',
        'reviewed' => 'DATE REVIEWED BY PSED CHIEF',
        'to_rd' => 'DATE SUBMITTED TO ARD',
        'approved' => 'DATE APPROVED',
        'returned' => 'DATE RETURNED',
        'status' => 'STATUS',
        'receipt' => 'RECEIPT DETAILS',
        'remarks' => 'REMARKS',
        'location' => 'LOCATION',
    ];

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        ini_set('memory_limit', '2G');
        (new FieldOfficeSeeder)->run();

        if ($this->option('fresh') && ! $this->option('dry-run')) {
            HrMechanism::where('is_legacy', true)->delete();
        } elseif (HrMechanism::where('is_legacy', true)->exists() && ! $this->option('dry-run')) {
            $this->error('The workbook was already imported. Use --fresh to import it again.');

            return self::FAILURE;
        }

        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(array_keys(FieldOfficeSeeder::OFFICES));
        $book = $reader->load($file);

        $totals = ['agencies' => 0, 'mechanisms' => 0, 'skipped' => 0];

        DB::beginTransaction();

        try {
            foreach (FieldOfficeSeeder::OFFICES as $sheetName => $_) {
                $sheet = $book->getSheetByName($sheetName);

                if ($sheet === null) {
                    $this->warn("No sheet named {$sheetName}; skipped.");

                    continue;
                }

                $office = FieldOffice::where('code', $sheetName)->firstOrFail();
                $result = $this->importSheet($sheet, $office);
                $this->line(sprintf('%-12s %4d mechanisms, %3d agencies, %3d rows skipped', $sheetName, $result['mechanisms'], $result['agencies'], $result['skipped']));

                foreach ($totals as $key => $_n) {
                    $totals[$key] += $result[$key];
                }
            }

            $this->option('dry-run') ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'Dry run: ' : 'Imported ')."{$totals['mechanisms']} mechanisms for {$totals['agencies']} agencies ({$totals['skipped']} empty rows skipped).");

        return self::SUCCESS;
    }

    /** @return array{agencies: int, mechanisms: int, skipped: int} */
    private function importSheet(Worksheet $sheet, FieldOffice $office): array
    {
        $columns = $this->columns($sheet);
        $highest = $sheet->getHighestDataRow();
        $result = ['agencies' => 0, 'mechanisms' => 0, 'skipped' => 0];
        $sector = null;
        $agency = null;
        $sequence = HrMechanism::where('is_legacy', true)->count();

        for ($row = 4; $row <= $highest; $row++) {
            $get = fn (string $key) => isset($columns[$key]) ? $sheet->getCell([$columns[$key], $row])->getValue() : null;

            $name = $this->text($get('agency'));
            $title = $this->text($get('mechanism'));

            // A line such as "NGA'S" or "LGU" only says which sector the agencies below belong to.
            if ($name !== null && $title === null && preg_match("/^(NGA|LGU|SUC|GOCC)'?S?$/i", $name, $m)) {
                $sector = strtoupper($m[1]);

                continue;
            }

            // The signature block at the foot of a sheet ("Action Officer", "Administrative Aide V") is not a filing.
            if ($name !== null && AgencyMapper::isNotAnAgency($name)) {
                $agency = null;
                $result['skipped']++;

                continue;
            }

            if ($name !== null) {
                // The official agency when the workbook's short form is known ("MGO ALLEN, NORTHERN SAMAR", "NAVAL W.D."),
                // otherwise an agency of its own as written.
                $agency = AgencyMapper::resolve($name, $office->id) ?? Agency::firstOrCreate(
                    ['name' => $name, 'field_office_id' => $office->id],
                    ['sector' => $sector],
                );
                $result['agencies'] += $agency->wasRecentlyCreated ? 1 : 0;
            }

            $dates = [];
            foreach (['received', 'processed', 'evaluated', 'reviewed', 'to_rd', 'approved', 'returned'] as $key) {
                $dates[$key] = $this->date($get($key));
            }

            // A line with an agency but nothing filed yet is only a placeholder.
            if ($agency === null || ($title === null && array_filter($dates) === [])) {
                $result['skipped']++;

                continue;
            }

            $status = MechanismStatus::fromLegacy($this->text($get('status')), $dates['approved'] !== null, $dates['returned'] !== null);
            $copies = $this->text($get('copies'));

            $mechanism = HrMechanism::create([
                'agency_id' => $agency->id,
                'tracking_code' => sprintf('HRM-LEG-%05d', ++$sequence),
                'mechanism_type' => MechanismType::guess($title),
                'title' => $title,
                'copies' => $copies !== null ? mb_substr($copies, 0, 20) : null,
                'status' => $status,
                'processor' => mb_substr((string) $this->text($get('processor')), 0, 20) ?: null,
                'date_submitted' => $dates['received'],
                'date_processed' => $dates['processed'],
                'date_evaluated' => $dates['evaluated'],
                'date_reviewed' => $dates['reviewed'],
                'date_returned' => $dates['returned'],
                'date_submitted_to_rd' => $dates['to_rd'],
                'date_approved' => $dates['approved'],
                'remarks' => $this->text($get('remarks')),
                'receipt_details' => $this->text($get('receipt')) !== null ? mb_substr((string) $this->text($get('receipt')), 0, 255) : null,
                'location' => $this->text($get('location')) !== null ? mb_substr((string) $this->text($get('location')), 0, 255) : null,
                'is_legacy' => true,
            ]);

            // The cell can name two officers ("GQC & RMA"); the column above keeps only the first 20 characters.
            ActionOfficers::sync($mechanism, $this->text($get('processor')));

            $result['mechanisms']++;
        }

        return $result;
    }

    /**
     * Finds each field's column from the header row (row 3), because the sheets differ in layout.
     *
     * @return array<string, int>
     */
    private function columns(Worksheet $sheet): array
    {
        $found = [];
        $last = Coordinate::columnIndexFromString($sheet->getHighestDataColumn(3));

        for ($col = 1; $col <= $last; $col++) {
            $header = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $sheet->getCell([$col, 3])->getValue())));

            if ($header === '') {
                continue;
            }

            foreach (self::HEADERS as $key => $needle) {
                // "STATUS" must also match "STATUS OF DOCUMENT"; "PROCESSOR" matches "PROCESSOR/ EVALUATOR".
                if (! isset($found[$key]) && str_starts_with($header, $needle)) {
                    $found[$key] = $col;
                }
            }
        }

        return $found;
    }

    private function text(mixed $value): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $value));

        return $text === '' || in_array(mb_strtoupper($text), ['N/A', 'NA', '-'], true) ? null : $text;
    }

    /** Excel serial numbers and the text dates people typed ("4/18/2022", "19-Aug-21"); anything else is left out. */
    private function date(mixed $value): ?Carbon
    {
        $date = $this->parseDate($value);

        // Typos such as the year 0221 or 2201 are common in the workbook: a date outside these years is left out.
        return $date !== null && $date->year >= 2000 && $date->year <= now()->year + 1 ? $date : null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $serial = (float) $value;

                return $serial > 20000 && $serial < 80000 ? Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->startOfDay() : null;
            }

            $text = trim((string) $value);

            if (! preg_match('/\d{1,2}[\/\-][A-Za-z0-9]{1,3}[\/\-]\d{2,4}|[A-Za-z]+ \d{1,2}, \d{4}/', $text)) {
                return null;
            }

            return Carbon::parse($text)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
