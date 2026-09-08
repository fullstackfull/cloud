<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Services;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DomainName;

/**
 * What a record may say, before anything is asked of a provider.
 *
 * **The platform validates rather than forwarding.** A provider will accept a
 * good deal that this refuses, and will reject things with a message written
 * for whoever wrote the provider's API rather than for a customer. Neither is
 * a reason to let a value through: an AAAA holding an IPv4 address, a CNAME at
 * the apex or a CAA with a tag nobody implements are all published happily by
 * something and then serve nothing, which the customer discovers as an outage.
 *
 * Everything here is a pure function of the value. Rules that need to look at
 * other rows — the CNAME-must-stand-alone rule, duplicates, per-zone ceilings
 * — live in the actions, because they are questions about a zone rather than
 * about a record.
 */
final readonly class DnsRecordRules
{
    /**
     * A TXT value the platform will store. Long enough for the DKIM keys and
     * SPF records this is mostly used for, short enough that a zone cannot be
     * used as a filesystem.
     */
    private const int MAX_TXT = 2048;

    private const int MAX_CAA_VALUE = 255;

    /** The tags a resolver actually implements. */
    private const array CAA_TAGS = ['issue', 'issuewild', 'iodef'];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws DnsRefusedException
     * @throws InvalidDnsRecordException
     * @throws InvalidDomainNameException
     */
    public function assert(DnsRecordType $type, string $name, string $content, ?int $priority, array $data, string $zone): void
    {
        $zoneName = DomainName::fromString($zone);

        // Wildcards are a record's business and never a zone's, which is why
        // the flag is set here and not where the zone was parsed.
        $recordName = DomainName::fromString($name, allowWildcard: true);

        if (! $recordName->isWithin($zoneName)) {
            throw DnsRefusedException::nameOutsideZone($recordName->value(), $zoneName->value());
        }

        match ($type) {
            DnsRecordType::A => $this->assertIpv4($content),
            DnsRecordType::AAAA => $this->assertIpv6($content),
            DnsRecordType::CNAME => $this->assertTarget($type, $content, $recordName, $zoneName),
            DnsRecordType::MX => $this->assertMailExchange($content, $priority),
            DnsRecordType::TXT => $this->assertText($content),
            DnsRecordType::CAA => $this->assertCertificateAuthority($data),
        };
    }

    /**
     * @throws InvalidDnsRecordException
     */
    private function assertIpv4(string $content): void
    {
        if (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw InvalidDnsRecordException::contentIsNot(DnsRecordType::A, $content, 'an IPv4 address');
        }

        /*
         * Refused because they cannot mean what the customer thinks. A name
         * resolving to 127.0.0.1 resolves to *the visitor's own machine*, and
         * publishing one is a well-known way to turn a customer's domain into
         * a tool for attacking the people who visit it. The link-local range
         * carries the cloud metadata endpoint, which is worse.
         *
         * Private ranges go too, and that one is a judgement rather than a
         * rule of the protocol: a name in a public zone pointing at 10.0.0.5
         * resolves to whatever happens to be at 10.0.0.5 on the *visitor's*
         * network, which is the mechanism DNS rebinding is built on. A
         * deployment that genuinely serves a private network from a public
         * zone is a thing this platform does not sell.
         */
        if (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE) === false) {
            throw InvalidDnsRecordException::contentIsNot(
                DnsRecordType::A,
                $content,
                'an address that can be reached from the internet',
            );
        }
    }

    /**
     * @throws InvalidDnsRecordException
     */
    private function assertIpv6(string $content): void
    {
        if (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw InvalidDnsRecordException::contentIsNot(DnsRecordType::AAAA, $content, 'an IPv6 address');
        }

        if (filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_PRIV_RANGE) === false) {
            throw InvalidDnsRecordException::contentIsNot(
                DnsRecordType::AAAA,
                $content,
                'an address that can be reached from the internet',
            );
        }
    }

    /**
     * @throws DnsRefusedException
     * @throws InvalidDnsRecordException
     * @throws InvalidDomainNameException
     */
    private function assertTarget(DnsRecordType $type, string $content, DomainName $record, DomainName $zone): void
    {
        if ($record->equals($zone)) {
            throw DnsRefusedException::cnameAtApex($zone->value());
        }

        $this->assertHostname($type, $content);
    }

    /**
     * @throws InvalidDnsRecordException
     */
    private function assertMailExchange(string $content, ?int $priority): void
    {
        if ($priority === null) {
            throw InvalidDnsRecordException::missingPriority($content);
        }

        /*
         * An address here is the mistake people make most often, and it is not
         * a harmless one: RFC 5321 requires a name, so mail servers that follow
         * it will not deliver, and the customer sees mail working from some
         * senders and not others.
         */
        if (filter_var($content, FILTER_VALIDATE_IP) !== false) {
            throw InvalidDnsRecordException::contentIsNot(
                DnsRecordType::MX,
                $content,
                'a host name — mail exchangers are named, not addressed',
            );
        }

        $this->assertHostname(DnsRecordType::MX, $content);
    }

    /**
     * @throws InvalidDnsRecordException
     */
    private function assertHostname(DnsRecordType $type, string $content): void
    {
        try {
            DomainName::fromString($content);
        } catch (InvalidDomainNameException $e) {
            throw InvalidDnsRecordException::contentIsNot($type, $content, 'a host name ('.$e->getMessage().')');
        }
    }

    /**
     * @throws InvalidDnsRecordException
     */
    private function assertText(string $content): void
    {
        if (strlen($content) > self::MAX_TXT) {
            throw InvalidDnsRecordException::contentIsNot(
                DnsRecordType::TXT,
                '…',
                sprintf('at most %d characters', self::MAX_TXT),
            );
        }

        // A newline in a TXT value is either a paste accident or an attempt to
        // write a second record; providers differ on which, and neither is
        // what the customer meant.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $content) === 1) {
            throw InvalidDnsRecordException::contentIsNot(
                DnsRecordType::TXT,
                '…',
                'free of control characters and line breaks',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidDnsRecordException
     */
    private function assertCertificateAuthority(array $data): void
    {
        $flags = $data['flags'] ?? null;
        $tag = $data['tag'] ?? null;
        $value = $data['value'] ?? null;

        if (! is_int($flags) || $flags < 0 || $flags > 255) {
            throw InvalidDnsRecordException::contentIsNot(DnsRecordType::CAA, (string) json_encode($flags), 'flags between 0 and 255');
        }

        if (! is_string($tag) || ! in_array($tag, self::CAA_TAGS, strict: true)) {
            throw InvalidDnsRecordException::contentIsNot(
                DnsRecordType::CAA,
                is_string($tag) ? $tag : '',
                'one of '.implode(', ', self::CAA_TAGS),
            );
        }

        if (! is_string($value) || trim($value) === '' || strlen($value) > self::MAX_CAA_VALUE) {
            throw InvalidDnsRecordException::contentIsNot(
                DnsRecordType::CAA,
                is_string($value) ? $value : '',
                sprintf('a value of 1 to %d characters', self::MAX_CAA_VALUE),
            );
        }
    }
}
