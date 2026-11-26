<?php

declare(strict_types=1);

namespace App\Devices\Discovery;

use InvalidArgumentException;

/**
 * Minimal DNS wire-format encoder/decoder: just enough for mDNS service browsing
 * (PTR, SRV, TXT, A, AAAA records, with name compression).
 */
final class DnsMessage
{
    public const TYPE_A = 1;

    public const TYPE_PTR = 12;

    public const TYPE_TXT = 16;

    public const TYPE_AAAA = 28;

    public const TYPE_SRV = 33;

    private const CLASS_IN = 1;

    private const UNICAST_RESPONSE = 0x8000; // "QU" bit in the question class

    /** Builds a query packet. With $unicastResponse the responder answers us directly. */
    public static function query(string $name, int $type = self::TYPE_PTR, bool $unicastResponse = true): string
    {
        $header = pack('n6', 0, 0, 1, 0, 0, 0); // id, flags, qd, an, ns, ar
        $class = self::CLASS_IN | ($unicastResponse ? self::UNICAST_RESPONSE : 0);

        return $header.self::encodeName($name).pack('n2', $type, $class);
    }

    public static function encodeName(string $name): string
    {
        $out = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            if (strlen($label) > 63) {
                throw new InvalidArgumentException("DNS label too long: {$label}");
            }
            $out .= chr(strlen($label)).$label;
        }

        return $out."\0";
    }

    /**
     * Parses a response packet into a flat list of resource records (answers + authority + additional).
     *
     * @return list<array{name:string,type:int,ttl:int,data:mixed}>
     */
    public static function parseRecords(string $packet): array
    {
        if (strlen($packet) < 12) {
            return [];
        }

        $counts = unpack('nid/nflags/nqd/nan/nns/nar', $packet);
        $offset = 12;

        try {
            for ($i = 0; $i < $counts['qd']; $i++) {
                self::readName($packet, $offset);
                $offset += 4;
            }

            $records = [];
            $total = $counts['an'] + $counts['ns'] + $counts['ar'];
            for ($i = 0; $i < $total; $i++) {
                $name = self::readName($packet, $offset);
                $meta = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
                if ($meta === false) {
                    break;
                }
                $offset += 10;
                $rdataOffset = $offset;
                $offset += $meta['length'];
                if ($offset > strlen($packet)) {
                    break;
                }

                $records[] = [
                    'name' => strtolower($name),
                    'type' => $meta['type'],
                    'ttl' => $meta['ttl'],
                    'data' => self::decodeRdata($packet, $meta['type'], $rdataOffset, $meta['length']),
                ];
            }
        } catch (InvalidArgumentException) {
            return $records ?? [];
        }

        return $records;
    }

    private static function decodeRdata(string $packet, int $type, int $offset, int $length): mixed
    {
        $rdata = substr($packet, $offset, $length);

        switch ($type) {
            case self::TYPE_A:
                return $length === 4 ? inet_ntop($rdata) : null;
            case self::TYPE_AAAA:
                return $length === 16 ? inet_ntop($rdata) : null;
            case self::TYPE_PTR:
                $o = $offset;

                return self::readName($packet, $o); // original case: the instance label is shown to users
            case self::TYPE_SRV:
                $srv = unpack('npriority/nweight/nport', $rdata);
                $o = $offset + 6;

                return ['port' => $srv['port'], 'target' => strtolower(self::readName($packet, $o))];
            case self::TYPE_TXT:
                $txt = [];
                $i = 0;
                while ($i < $length) {
                    $len = ord($rdata[$i]);
                    $entry = substr($rdata, $i + 1, $len);
                    $i += $len + 1;
                    if ($entry === '') {
                        continue;
                    }
                    [$key, $value] = array_pad(explode('=', $entry, 2), 2, '');
                    $txt[strtolower($key)] = $value;
                }

                return $txt;
            default:
                return null;
        }
    }

    /** Reads a (possibly compressed) domain name, advancing $offset past it. */
    private static function readName(string $packet, int &$offset): string
    {
        $labels = [];
        $jumped = false;
        $cursor = $offset;
        $hops = 0;
        $length = strlen($packet);

        while (true) {
            if ($cursor >= $length) {
                throw new InvalidArgumentException('Truncated DNS name');
            }
            $len = ord($packet[$cursor]);

            if (($len & 0xC0) === 0xC0) { // compression pointer
                if ($cursor + 1 >= $length || ++$hops > 32) {
                    throw new InvalidArgumentException('Bad DNS compression pointer');
                }
                $pointer = (($len & 0x3F) << 8) | ord($packet[$cursor + 1]);
                if (! $jumped) {
                    $offset = $cursor + 2;
                }
                $jumped = true;
                $cursor = $pointer;

                continue;
            }

            if ($len === 0) {
                if (! $jumped) {
                    $offset = $cursor + 1;
                }

                return implode('.', $labels);
            }

            $labels[] = substr($packet, $cursor + 1, $len);
            $cursor += $len + 1;
        }
    }
}
