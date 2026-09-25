<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * A certificate authority minted for one test and thrown away after it.
 *
 * Minted rather than checked in, for two reasons. A key committed to the
 * repository is a key anybody can sign with, and a checked-in certificate
 * expires on a date nobody will remember until the suite goes red on it.
 *
 * Nothing here touches the machine's trust store. A test that wants the
 * gateway to trust this authority points OpenSSL at {@see authorityFile()}
 * through `SSL_CERT_FILE`, which works only because the gateway's stream
 * context names no `cafile` of its own and so falls back to OpenSSL's default
 * verify paths — the variable is how those paths are chosen.
 */
final class ThrowawayCertificates
{
    private OpenSSLAsymmetricKey $authorityKey;

    private OpenSSLCertificate $authority;

    private string $authorityFile;

    /** @var list<string> */
    private array $files = [];

    public function __construct()
    {
        $this->authorityKey = self::key();

        $this->authority = $this->sign(
            commonName: 'Lynomia throwaway console CA '.bin2hex(random_bytes(4)),
            extensions: "basicConstraints = critical, CA:TRUE\nkeyUsage = critical, keyCertSign, cRLSign\n",
            key: $this->authorityKey,
            issuer: null,
            issuerKey: $this->authorityKey,
        );

        openssl_x509_export($this->authority, $pem);
        $this->authorityFile = $this->write($pem);
    }

    /**
     * The authority's certificate, alone, as a file OpenSSL can be pointed at.
     */
    public function authorityFile(): string
    {
        return $this->authorityFile;
    }

    /**
     * A certificate and key this authority vouches for, naming $subjectAltName
     * (for example `IP:127.0.0.1` or `DNS:somebody-else.test`).
     *
     * @return string A file holding the certificate followed by its key, the
     *                shape a TLS server's `local_cert` option takes.
     */
    public function issuedFor(string $subjectAltName): string
    {
        $key = self::key();

        $certificate = $this->sign(
            commonName: preg_replace('/^(IP|DNS):/', '', $subjectAltName) ?? $subjectAltName,
            extensions: "basicConstraints = CA:FALSE\nsubjectAltName = {$subjectAltName}\n",
            key: $key,
            issuer: $this->authority,
            issuerKey: $this->authorityKey,
        );

        return $this->bundle($certificate, $key);
    }

    /**
     * A certificate that names the right host and that nobody vouches for:
     * signed by its own key, as a lab cluster's is out of the box.
     */
    public function selfSignedFor(string $subjectAltName): string
    {
        $key = self::key();

        $certificate = $this->sign(
            commonName: preg_replace('/^(IP|DNS):/', '', $subjectAltName) ?? $subjectAltName,
            extensions: "basicConstraints = CA:FALSE\nsubjectAltName = {$subjectAltName}\n",
            key: $key,
            issuer: null,
            issuerKey: $key,
        );

        return $this->bundle($certificate, $key);
    }

    public function cleanUp(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        $this->files = [];
    }

    private static function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate a key: '.(string) openssl_error_string());
        }

        return $key;
    }

    private function sign(
        string $commonName,
        string $extensions,
        OpenSSLAsymmetricKey $key,
        ?OpenSSLCertificate $issuer,
        OpenSSLAsymmetricKey $issuerKey,
    ): OpenSSLCertificate {
        // openssl_csr_sign() reads x509 extensions only from a config file.
        $config = $this->write("[req]\ndistinguished_name = dn\n[dn]\n[extensions]\n".$extensions);
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'extensions'];

        $request = openssl_csr_new(['commonName' => $commonName], $key, $options);

        if (! $request instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Could not build a signing request: '.(string) openssl_error_string());
        }

        $certificate = openssl_csr_sign($request, $issuer, $issuerKey, 1, $options, random_int(1, PHP_INT_MAX));

        if ($certificate === false) {
            throw new RuntimeException('Could not sign a certificate: '.(string) openssl_error_string());
        }

        return $certificate;
    }

    private function bundle(OpenSSLCertificate $certificate, OpenSSLAsymmetricKey $key): string
    {
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return $this->write($certificatePem.$keyPem);
    }

    private function write(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'lynomia-console-tls-');

        if ($file === false || file_put_contents($file, $contents) === false) {
            throw new RuntimeException('Could not write a throwaway certificate file.');
        }

        $this->files[] = $file;

        return $file;
    }
}
