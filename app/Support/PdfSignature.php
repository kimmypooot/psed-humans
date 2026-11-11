<?php

namespace App\Support;

/**
 * Reads the digital signature of a PDF (the PKCS#7 / CMS signature a PNPKI signing tool writes in it) and
 * checks it: that it is there, that the file has not been changed since it was signed, who signed it and
 * who issued their certificate. Whether that issuer is DICT's PNPKI is decided by the caller.
 *
 * It uses the last signature in the file. The chain of trust is checked only when a bundle of the PNPKI
 * certificate authorities is given; without one, `trusted` is null (not checked).
 */
class PdfSignature
{
    /**
     * @return array{signed: bool, intact: ?bool, signer: ?string, issuer: ?string, trusted: ?bool, error: ?string}
     */
    public static function inspect(string $path, ?string $caBundle = null): array
    {
        $none = ['signed' => false, 'intact' => null, 'signer' => null, 'issuer' => null, 'trusted' => null, 'error' => null];
        $bytes = @file_get_contents($path);

        if ($bytes === false || ! str_starts_with($bytes, '%PDF')) {
            return $none + ['error' => 'not a PDF'];
        }

        if (! preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $bytes, $found, PREG_SET_ORDER) || $found === []) {
            return $none;
        }

        [, $start1, $length1, $start2, $length2] = array_map('intval', end($found));
        $size = strlen($bytes);

        // The signed part must be the whole file: anything added after the signature changes the document.
        $coversAll = $start1 === 0 && $start2 > $start1 + $length1 && $start2 + $length2 <= $size && trim(substr($bytes, $start2 + $length2)) === '';
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($bytes, $start1 + $length1, $start2 - ($start1 + $length1))) ?? '';
        $der = strlen($hex) % 2 === 0 && $hex !== '' ? hex2bin($hex) : false;

        if ($der === false) {
            return ['signed' => true, 'intact' => false, 'signer' => null, 'issuer' => null, 'trusted' => null, 'error' => 'the signature could not be read'];
        }

        $der = self::trimDer($der);
        $signed = substr($bytes, $start1, $length1).substr($bytes, $start2, $length2);

        $signature = tempnam(sys_get_temp_dir(), 'sig');
        $content = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($signature, $der);
        file_put_contents($content, $signed);

        try {
            $intact = $coversAll && self::verify($content, $signature, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_BINARY);
            $trusted = null;

            if ($caBundle !== null && is_file($caBundle)) {
                $trusted = $intact && self::verify($content, $signature, OPENSSL_CMS_BINARY, [$caBundle]);
            }

            [$signer, $issuer] = self::signerOf($der);

            return ['signed' => true, 'intact' => $intact, 'signer' => $signer, 'issuer' => $issuer, 'trusted' => $trusted, 'error' => null];
        } finally {
            @unlink($signature);
            @unlink($content);
        }
    }

    /** True when the detached CMS signature matches the content. */
    private static function verify(string $contentFile, string $signatureFile, int $flags, array $authorities = []): bool
    {
        try {
            // The signature of a PDF is detached: the signed bytes are the input, the signature its own file.
            return @openssl_cms_verify($contentFile, $flags | OPENSSL_CMS_DETACHED, null, $authorities, null, null, null, $signatureFile, OPENSSL_ENCODING_DER) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The common name and the issuer of the certificate that signed, from the certificates carried in the signature. @return array{0: ?string, 1: ?string} */
    private static function signerOf(string $der): array
    {
        // openssl_pkcs7_read takes the PEM text itself, not a file name.
        $pem = "-----BEGIN PKCS7-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PKCS7-----\n";
        $certs = [];

        if (! @openssl_pkcs7_read($pem, $certs) || $certs === []) {
            return [null, null];
        }

        $parsed = collect($certs)->map(fn ($c) => openssl_x509_parse($c))->filter();
        // The certificate of the person is the one that is not itself a certificate authority.
        $leaf = $parsed->first(fn ($c) => ! str_contains((string) ($c['extensions']['basicConstraints'] ?? ''), 'CA:TRUE')) ?? $parsed->first();

        if (! $leaf) {
            return [null, null];
        }

        $common = $leaf['subject']['CN'] ?? null;
        $issuer = collect($leaf['issuer'] ?? [])->map(fn ($v, $k) => $k.'='.(is_array($v) ? implode('/', $v) : $v))->implode(', ');

        return [is_array($common) ? implode(' ', $common) : $common, $issuer !== '' ? $issuer : null];
    }

    /** A signature is zero-padded in the PDF; the DER header says how long it really is. */
    private static function trimDer(string $der): string
    {
        if (strlen($der) < 4 || $der[0] !== "\x30") {
            return $der;
        }

        $first = ord($der[1]);

        if ($first < 0x80) {
            return substr($der, 0, 2 + $first);
        }

        $count = $first & 0x7F;
        $length = 0;

        for ($i = 0; $i < $count; $i++) {
            $length = ($length << 8) | ord($der[2 + $i]);
        }

        return substr($der, 0, 2 + $count + $length);
    }
}
