<?php

namespace App\Support;

use App\Enums\MechanismType;
use App\Models\HrMechanism;
use App\Models\HrMechanismAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The transmittal letter PSED sends the agency with the reviewed HR mechanism and the HR checklist.
 * There are two: `approval` (recommended for approval, with a request for three signed copies) and
 * `compliance` (sent back to correct). Each is filled into its Word template in resources/templates,
 * paragraph by paragraph, so the letterhead and the formatting stay as PSED made them.
 */
class Transmittal
{
    public const TYPES = ['approval' => 'For Approval', 'compliance' => 'For Compliance'];

    /** The paragraphs of a letter that carry text, in order; both templates have the same thirteen. */
    /** The paragraphs PSED may rewrite in the editor. */
    public const EDITABLE = ['p1', 'p2', 'p3', 'thanks', 'closing'];

    private const SLOTS = ['date', 'name', 'position', 'agency', 'address', 'dear', 'p1', 'p2', 'p3', 'thanks', 'closing', 'signer', 'signer_position'];

    /** Validation rules for the fields PSED fills in, under `$prefix`. */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'date' => ['required', 'date'],
            $prefix.'addressee_name' => ['required', 'string', 'max:120'],
            $prefix.'addressee_position' => ['required', 'string', 'max:120'],
            $prefix.'addressee_address' => ['nullable', 'string', 'max:400'],
            $prefix.'salutation' => ['required', 'string', 'max:80'],
            $prefix.'subject' => ['required', 'string', 'max:200'],
            $prefix.'signatory_id' => ['required', 'integer'],
            // The wording PSED rewrote in the editor, by paragraph; what was not rewritten follows the standard wording.
            $prefix.'body' => ['nullable', 'array:'.implode(',', self::EDITABLE)],
            $prefix.'body.*' => ['array', 'max:60'],
            $prefix.'body.*.*' => ['array', 'max:2'],
            $prefix.'body.*.*.0' => ['nullable', 'string', 'max:3000'],
            $prefix.'body.*.*.1' => ['nullable', 'in:b,i,bi'],
        ];
    }

    /**
     * What the form starts with: the last head of this agency we wrote to, or a guess from the kind of agency.
     *
     * @return array{date: string, addressee_name: string, addressee_position: string, addressee_address: string, salutation: string, subject: string}
     */
    public static function defaults(HrMechanism $mechanism): array
    {
        $agency = $mechanism->agency;
        $name = trim($agency->name);

        $position = $agency->head_position ?: match (true) {
            (bool) preg_match('/^Municipal Government/i', $name), (bool) preg_match('/^City Government/i', $name) => preg_match('/^City/i', $name) ? 'City Mayor' : 'Municipal Mayor',
            (bool) preg_match('/^Provincial Government/i', $name) => 'Governor',
            (bool) preg_match('/Water District/i', $name) => 'General Manager',
            (bool) preg_match('/Schools Division Office/i', $name) => 'Schools Division Superintendent',
            (bool) preg_match('/Regional Office|Region VIII|Field Office/i', $name) => 'Regional Director',
            (bool) preg_match('/University|College/i', $name) => 'University President',
            default => '',
        };

        // "Municipal Government of Javier, Leyte" is written on two lines: the agency, then "Province of Leyte".
        $lines = preg_match('/^(.+?), (Leyte|Samar|Eastern Samar|Northern Samar|Southern Leyte|Biliran)$/', $name, $m) ? [$m[1], 'Province of '.$m[2]] : [$name];

        $lgu = preg_match('/^(Municipal|City|Provincial) Government/i', $name) === 1;
        $head = $agency->head_name ?: ($lgu ? 'HON. ' : '');

        return [
            'date' => now()->toDateString(),
            'addressee_name' => $head,
            'addressee_position' => $position,
            'addressee_address' => implode("\n", $lines),
            'salutation' => self::salutation($head, $position),
            'subject' => self::subject($mechanism),
        ];
    }

    /** The finished letter as the bytes of a .docx. */
    public static function render(HrMechanism $mechanism, string $type, array $data): string
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $template = resource_path('templates/transmittal-'.$type.'.docx');
        $copy = tempnam(sys_get_temp_dir(), 'trn');
        copy($template, $copy);

        $zip = new ZipArchive;

        if ($zip->open($copy) !== true) {
            throw new RuntimeException('The transmittal template could not be opened.');
        }

        $zip->addFromString('word/document.xml', self::fill((string) $zip->getFromName('word/document.xml'), $mechanism, $type, $data));
        $zip->close();

        $bytes = (string) file_get_contents($copy);
        @unlink($copy);

        return $bytes;
    }

    /** Writes the letter to the mechanism's documents; it replaces the transmittal made before. */
    public static function store(HrMechanism $mechanism, string $type, array $data, User $by): HrMechanismAttachment
    {
        $name = self::fileName($mechanism, $type);
        $path = 'hr-mechanisms/'.$mechanism->agency_id.'/'.$mechanism->id.'/'.now()->format('YmdHis').'-'.$name;
        Storage::disk('local')->put($path, self::render($mechanism, $type, $data));

        return $mechanism->attachments()->create(['kind' => 'transmittal', 'path' => $path, 'name' => $name, 'uploaded_by' => $by->id]);
    }

    public static function fileName(HrMechanism $mechanism, string $type): string
    {
        return 'Transmittal_'.str_replace(' ', '', self::TYPES[$type]).'_'.$mechanism->tracking_code.'.docx';
    }

    /** "2026 Revised Merit Selection Plan (MSP)": the year it was filed, "Revised" when the agency called it that, then what it is. */
    public static function subject(HrMechanism $mechanism): string
    {
        if ($mechanism->mechanism_type === MechanismType::Other) {
            return trim((string) $mechanism->title) ?: 'HR mechanism';
        }

        $revised = preg_match('/revised/i', (string) $mechanism->title) ? 'Revised ' : '';

        return trim((($mechanism->date_submitted ?? now())->year).' '.$revised.$mechanism->mechanism_type->label());
    }

    /** "Mayor Javier" for a Mayor, "Governor Tan" for a Governor, otherwise the neutral "Sir/Madam". */
    private static function salutation(string $name, string $position): string
    {
        $surname = trim(preg_replace('/\b(JR|SR|II|III|IV)\b\.?/i', '', preg_replace('/^(HON|ATTY|DR|ENGR|MR|MS|MRS)\.?(\s+|$)/i', '', trim($name)) ?? '') ?? '', " ,.");
        $surname = $surname === '' ? '' : ucfirst(mb_strtolower(collect(explode(' ', $surname))->last()));
        $title = match (true) {
            (bool) preg_match('/mayor/i', $position) => 'Mayor',
            (bool) preg_match('/governor/i', $position) => 'Governor',
            default => null,
        };

        return $title !== null && $surname !== '' ? $title.' '.$surname : 'Sir/Madam';
    }

    /** The checklist's name for the kind of agency, when it is plain which kind it is. */
    private static function sector(string $agency): string
    {
        return match (true) {
            (bool) preg_match('/Water District/i', $agency) => '',
            (bool) preg_match('/^(Municipal|City|Provincial) Government/i', $agency) => ' for Local Government Unit (LGU)',
            (bool) preg_match('/State University|State College|University of|^University|College$/i', $agency) => ' for State Universities and Colleges (SUC)',
            (bool) preg_match('/^(Department|Bureau|Commission|National|Land Transportation|Philippine|Parole|Environmental|Mines|Maritime|DOJ)/i', $agency) => ' for National Government Agencies (NGA)',
            default => '',
        };
    }

    private static function fill(string $xml, HrMechanism $mechanism, string $type, array $d): string
    {
        if (! preg_match('#^(.*?<w:body>)(.*?)(<w:sectPr.*)$#s', $xml, $parts) || str_contains($parts[2], '<w:tbl>')) {
            throw new RuntimeException('The transmittal template is not shaped as expected.');
        }

        preg_match_all('#<w:p[ >].*?</w:p>#s', $parts[2], $found);
        $paragraphs = $found[0];

        // The paragraphs with text, in order, are the slots; the empty ones between them stay as the template has them.
        $slotAt = [];
        foreach ($paragraphs as $i => $p) {
            if (preg_match('#<w:t[ >]#', $p) === 1) {
                $slotAt[$i] = self::SLOTS[count($slotAt)] ?? null;
            }
        }

        if (count($slotAt) !== count(self::SLOTS)) {
            throw new RuntimeException('The transmittal template does not have the expected '.count(self::SLOTS).' paragraphs of text.');
        }

        $text = self::slots($mechanism, $type, $d);
        $out = [];

        foreach ($paragraphs as $i => $p) {
            $slot = $slotAt[$i] ?? null;

            if ($slot === null) {
                $out[] = $p;
            } elseif ($slot === 'agency') {
                // The address lines: one paragraph each, in the look of the template's first.
                foreach ($text['address'] as $line) {
                    $out[] = self::paragraph($p, [[$line]]);
                }
            } elseif ($slot === 'address') {
                continue;
            } else {
                $out[] = self::paragraph($p, $text[$slot]);
            }
        }

        return $parts[1].implode('', $out).$parts[3];
    }

    /**
     * What each paragraph of the letter says, as runs: [text] or [text, 'b'|'i'|'bi']. The standard wording, with
     * whatever PSED rewrote in the editor (`$d['body']`) in place of it. Both the Word file and the preview use this.
     *
     * @return array<string, mixed>
     */
    public static function slots(HrMechanism $mechanism, string $type, array $d): array
    {
        $slots = self::standard($mechanism, $type, $d) + ['thanks' => [['Thank you.']], 'closing' => [['Very truly yours,']]];

        foreach (self::EDITABLE as $key) {
            if (isset($d['body'][$key]) && is_array($d['body'][$key])) {
                $slots[$key] = self::runs($d['body'][$key]);
            }
        }

        return $slots;
    }

    /**
     * Runs from the editor, made safe: text only, one line, a known style. A paragraph cleared completely stays as an empty line.
     *
     * @param  array<int, mixed>  $runs
     * @return list<array{0: string, 1?: string}>
     */
    private static function runs(array $runs): array
    {
        $clean = [];

        foreach ($runs as $run) {
            $text = is_array($run) ? preg_replace('/\s+/', ' ', (string) ($run[0] ?? '')) : '';

            if ($text === '' || $text === null) {
                continue;
            }

            $style = is_array($run) && in_array($run[1] ?? null, ['b', 'i', 'bi'], true) ? $run[1] : null;
            $clean[] = $style ? [$text, $style] : [$text];
        }

        return $clean === [] ? [['']] : $clean;
    }

    /**
     * The standard wording of the letter for this mechanism.
     *
     * @return array<string, mixed>
     */
    private static function standard(HrMechanism $mechanism, string $type, array $d): array
    {
        $subject = trim($d['subject']);
        $agency = trim($mechanism->agency->name);
        $isMsp = $mechanism->mechanism_type === MechanismType::Msp;
        $abbreviation = preg_match('/\(([^()]+)\)\s*$/', $subject, $m) ? $m[1] : ($mechanism->mechanism_type === MechanismType::Other ? 'HR mechanism' : $mechanism->mechanism_type->value);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($d['addressee_address'] ?? '')) ?: [])));

        $common = [
            'date' => [[\Illuminate\Support\Carbon::parse($d['date'])->format('j F Y')]],
            'name' => [[mb_strtoupper(trim($d['addressee_name'])), 'b']],
            'position' => [[trim($d['addressee_position'])]],
            'address' => $lines,
            'dear' => [['Dear '], [trim($d['salutation']), 'bi'], [':']],
            'signer' => [[mb_strtoupper(trim($d['signatory_name'])), 'b']],
            'signer_position' => [[trim($d['signatory_position'])]],
        ];

        if ($type === 'approval') {
            $anchored = $isMsp ? ', which was primarily anchored on the 2025 Omnibus Rules on Appointments and Other Human Resource Actions (CSC Memorandum Circular No. 08, s. 2025)' : '';
            // "Municipal Government of Javier, Leyte" is written "…, Province of Leyte" in a letter.
            $named = preg_replace('/, (Leyte|Samar|Eastern Samar|Northern Samar|Southern Leyte|Biliran)$/', ', Province of $1', $agency);

            return $common + [
                'p1' => [["This refers to your submitted {$subject}{$anchored}, for review by this Office."]],
                'p2' => [["Per evaluation of the Policies and Systems Evaluation Division (PSED) of this Office, the {$subject} submitted by the {$named} is already recommended for approval."]],
                'p3' => [["In this regard, may we request submission to this Office of three (3) printed copies of the final version of your agency’s {$subject}, signed by the Head of Agency, together with the date of signing."]],
            ];
        }

        // "Revised Merit Selection Plan" in bold, its "(MSP)" as written.
        [$title, $brackets] = preg_match('/^(.*?)(\s*\([^()]+\))$/', $subject, $m) ? [$m[1], $m[2]] : [$subject, ''];

        return $common + [
            'p1' => array_values(array_filter([['This refers to your Agency’s '], [$title, 'b'], $brackets !== '' ? [$brackets] : null, [' submitted to this office for further evaluation.']])),
            'p2' => [["This Office has made the necessary review of said {$abbreviation}, and will provide you with a copy of the same for your consideration and compliance."]],
            'p3' => [['For your guidance, kindly refer to the filled-out 2025 updated Checklist for Reviewing Human Resource Mechanisms of Government Agencies: '.$abbreviation.self::sector($agency).' and the corresponding recommendations/suggestions indicated in the highlighted paragraphs, sentences, and words in your agency’s submitted PDF file.']],
        ];
    }

    /** A paragraph of the template with its own look (alignment, spacing, font) and new words. */
    private static function paragraph(string $prototype, array $runs): string
    {
        preg_match('#^<w:p\b[^>]*>#', $prototype, $open);
        $open = preg_replace('/\sw14:(paraId|textId)="[^"]*"/', '', $open[0]);
        $properties = preg_match('#<w:pPr>.*?</w:pPr>#s', $prototype, $p) ? $p[0] : '';
        $base = preg_match('#<w:r\b[^>]*>\s*<w:rPr>(.*?)</w:rPr>#s', $prototype, $r) ? preg_replace('#<w:(b|bCs|i|iCs)/>#', '', $r[1]) : '';

        $xml = '';
        foreach ($runs as $run) {
            $style = $run[1] ?? '';
            $format = (str_contains($style, 'b') ? '<w:b/><w:bCs/>' : '').(str_contains($style, 'i') ? '<w:i/><w:iCs/>' : '');
            // Bold and italic belong right after the font in a run's properties.
            $rPr = preg_match('#<w:rFonts[^>]*/>#', $base) ? preg_replace('#(<w:rFonts[^>]*/>)#', '$1'.$format, $base, 1) : $format.$base;
            $xml .= '<w:r><w:rPr>'.$rPr.'</w:rPr><w:t xml:space="preserve">'.htmlspecialchars($run[0], ENT_XML1 | ENT_COMPAT, 'UTF-8').'</w:t></w:r>';
        }

        return $open.$properties.$xml.'</w:p>';
    }
}
