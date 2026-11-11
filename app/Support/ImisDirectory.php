<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The CSC RO VIII employees as IMIS lists them (GET {IMIS_API_URL}/api/v1/cares/employees, bearer
 * IMIS_API_TOKEN): active accounts only. Kept for ten minutes so the combobox opens at once, and the
 * last good answer is kept for longer, so a moment when IMIS does not answer does not stop PSED. With
 * IMIS not configured and nothing kept, the list is empty and officers can still be typed in.
 */
class ImisDirectory
{
    /**
     * Everyone, by last name then first name.
     *
     * @return list<array{id: int, label: string, name: string, honorific: ?string, position: ?string}>
     */
    public static function employees(): array
    {
        $url = config('services.imis.api_url');
        $token = config('services.imis.api_token');

        if (! $url || ! $token) {
            return [];
        }

        $fresh = Cache::get('imis.directory');

        if (is_array($fresh)) {
            return $fresh;
        }

        $list = self::fetch(rtrim($url, '/'), $token);

        if ($list === null) {
            return Cache::get('imis.directory.last_good', []);
        }

        Cache::put('imis.directory', $list, now()->addMinutes(10));
        Cache::put('imis.directory.last_good', $list, now()->addDays(14));

        return $list;
    }

    /** One employee by IMIS id, or null. */
    public static function find(int $id): ?array
    {
        return collect(self::employees())->firstWhere('id', $id);
    }

    /**
     * The people who sign a transmittal: the Director IV and the Director III. Director IV first.
     * `printed` is the name as it appears above the position: "ATTY. GINA A. CRUCIO".
     *
     * @return list<array{id: int, label: string, name: string, printed: string, position: string}>
     */
    public static function signatories(): array
    {
        return collect(self::employees())
            ->filter(fn ($e) => preg_match('/^Director (III|IV)$/i', trim((string) $e['position'])) === 1)
            ->map(fn ($e) => [
                'id' => $e['id'],
                'label' => $e['label'],
                'name' => $e['name'],
                'printed' => mb_strtoupper(trim(($e['honorific'] ?: config('hrmm.honorifics.'.$e['id'], '')).' '.$e['name'])),
                'position' => trim($e['position']),
            ])
            ->sortBy(fn ($e) => strcasecmp($e['position'], 'Director IV') === 0 ? 0 : 1)
            ->values()
            ->all();
    }

    /** One signatory by IMIS id, or null. */
    public static function signatory(int $id): ?array
    {
        return collect(self::signatories())->firstWhere('id', $id);
    }

    /** @return list<array<string, mixed>>|null null when IMIS did not give a usable answer */
    private static function fetch(string $url, string $token): ?array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->timeout(8)->get($url.'/api/v1/cares/employees');
        } catch (Throwable $e) {
            Log::warning('IMIS directory unavailable', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('IMIS directory refused', ['status' => $response->status()]);

            return null;
        }

        return collect($response->json('data', []))
            ->filter(fn ($e) => is_int($e['id'] ?? null) && trim((string) ($e['last_name'] ?? '')) !== '')
            ->map(fn ($e) => [
                'id' => $e['id'],
                'label' => self::label($e),
                'name' => self::name($e),
                'honorific' => ($e['honorific'] ?? null) ?: null,
                'position' => $e['position'] ?? null,
            ])
            ->sortBy(fn ($e) => mb_strtolower($e['label']))
            ->values()
            ->all();
    }

    /** "Amor, Rowell M." : last name, first name, middle initial. */
    public static function label(array $e): string
    {
        $given = trim(trim((string) $e['first_name']).' '.self::initial($e['middle_name'] ?? null));

        return trim(trim((string) $e['last_name']).', '.$given.' '.trim((string) ($e['name_extension'] ?? '')), ' ,');
    }

    /** "Rowell M. Amor" : how the officer's name is kept and shown. */
    public static function name(array $e): string
    {
        return trim(implode(' ', array_filter([
            trim((string) $e['first_name']),
            self::initial($e['middle_name'] ?? null),
            trim((string) $e['last_name']),
            trim((string) ($e['name_extension'] ?? '')),
        ])));
    }

    private static function initial(?string $middle): string
    {
        $middle = trim((string) $middle);

        return $middle === '' ? '' : mb_strtoupper(mb_substr($middle, 0, 1)).'.';
    }
}
