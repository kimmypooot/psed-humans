<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;

/**
 * Makes real digitally signed PDFs for tests: a PDF with a signature field whose /Contents is a detached CMS
 * signature of the rest of the file, made with a throwaway certificate, as a PNPKI signing tool writes it.
 */
trait SignsPdfs
{
    /** @var array<string, array{0: \OpenSSLCertificate, 1: \OpenSSLAsymmetricKey}> */
    private array $signingIdentities = [];

    /** A throwaway certificate and key for a person, issued by an organisation of the given name. */
    private function identity(string $commonName, string $issuer = 'DICT PNPKI Certificate Authority'): array
    {
        $cacheKey = $commonName.'|'.$issuer;

        if (isset($this->signingIdentities[$cacheKey])) {
            return $this->signingIdentities[$cacheKey];
        }

        $config = ['config' => $this->opensslConfig(), 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($config);
        $request = $key ? openssl_csr_new(['commonName' => $commonName, 'organizationName' => $issuer, 'countryName' => 'PH'], $key, $config) : false;
        $certificate = $request ? openssl_csr_sign($request, null, $key, 30, $config) : false;

        if (! $key || ! $request || ! $certificate) {
            $this->markTestSkipped('OpenSSL could not make a test certificate here.');
        }

        return $this->signingIdentities[$cacheKey] = [$certificate, $key];
    }

    private function opensslConfig(): ?string
    {
        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf', '/etc/ssl/openssl.cnf'] as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** The bytes of a signed PDF. `$tamper` changes the document after it was signed; `$append` adds data after the signature. */
    private function signedPdfBytes(string $commonName, string $issuer = 'DICT PNPKI Certificate Authority', bool $tamper = false, bool $append = false): string
    {
        [$certificate, $key] = $this->identity($commonName, $issuer);
        $size = 16384;
        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n2 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached "
            .'/ByteRange [0 0000000000 0000000000 0000000000] /Contents <'.str_repeat('0', $size).'> >>'."\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        $open = strpos($pdf, '<'.str_repeat('0', 16));
        $close = $open + $size + 2;
        $pdf = preg_replace('/\[0 0000000000 0000000000 0000000000\]/', sprintf('[0 %010d %010d %010d]', $open, $close, strlen($pdf) - $close), $pdf);
        $signed = substr($pdf, 0, $open).substr($pdf, $close);

        $in = tempnam(sys_get_temp_dir(), 'in');
        $out = tempnam(sys_get_temp_dir(), 'out');
        file_put_contents($in, $signed);
        openssl_cms_sign($in, $out, $certificate, $key, [], OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
        $signature = bin2hex((string) file_get_contents($out));
        @unlink($in);
        @unlink($out);

        $pdf = substr($pdf, 0, $open + 1).str_pad($signature, $size, '0').substr($pdf, $close - 1);

        if ($tamper) {
            $pdf = str_replace('/Type /Catalog', '/Type /Catalo9', $pdf);
        }

        return $append ? $pdf."\n1 0 obj\n<< /Added true >>\nendobj\n" : $pdf;
    }

    private function signedPdf(string $commonName, string $issuer = 'DICT PNPKI Certificate Authority', string $name = 'HR Checklist (signed).pdf', bool $tamper = false, bool $append = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $this->signedPdfBytes($commonName, $issuer, $tamper, $append));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }
}
