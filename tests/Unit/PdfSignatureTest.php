<?php

namespace Tests\Unit;

use App\Support\PdfSignature;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\SignsPdfs;

class PdfSignatureTest extends TestCase
{
    use SignsPdfs;

    private function inspect(string $bytes, ?string $bundle = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ins');
        file_put_contents($path, $bytes);

        try {
            return PdfSignature::inspect($path, $bundle);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_signed_pdf_is_read_and_found_intact(): void
    {
        $result = $this->inspect($this->signedPdfBytes('BANELLO PABUA GABON'));

        $this->assertTrue($result['signed']);
        $this->assertTrue($result['intact']);
        $this->assertSame('BANELLO PABUA GABON', $result['signer']);
        $this->assertStringContainsString('DICT PNPKI Certificate Authority', $result['issuer']);
        $this->assertNull($result['trusted'], 'no certificate authority bundle was given, so the chain is not checked');
    }

    public function test_a_pdf_changed_after_signing_is_not_intact(): void
    {
        $changed = $this->inspect($this->signedPdfBytes('BANELLO PABUA GABON', tamper: true));
        $this->assertTrue($changed['signed']);
        $this->assertFalse($changed['intact']);

        $added = $this->inspect($this->signedPdfBytes('BANELLO PABUA GABON', append: true));
        $this->assertTrue($added['signed']);
        $this->assertFalse($added['intact'], 'something added after the signature is a change to the document');
    }

    public function test_a_pdf_without_a_signature_or_a_file_that_is_not_a_pdf_is_not_signed(): void
    {
        $this->assertFalse($this->inspect("%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF\n")['signed']);
        $this->assertFalse($this->inspect('just some text')['signed']);
    }
}
