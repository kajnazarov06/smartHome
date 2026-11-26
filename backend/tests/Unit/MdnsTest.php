<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Devices\Discovery\DnsMessage;
use App\Devices\Discovery\MdnsBrowser;
use App\Devices\Discovery\SubnetProber;
use PHPUnit\Framework\TestCase;

class MdnsTest extends TestCase
{
    public function test_query_packet_layout(): void
    {
        $packet = DnsMessage::query('_smartclock._tcp.local');

        $this->assertSame(pack('n6', 0, 0, 1, 0, 0, 0), substr($packet, 0, 12));
        $this->assertSame("\x0b_smartclock\x04_tcp\x05local\x00", substr($packet, 12, -4));
        $this->assertSame(pack('n2', 12, 0x8001), substr($packet, -4));
    }

    public function test_parses_a_typical_esp32_response_with_name_compression(): void
    {
        $packet = $this->responsePacket();
        $records = DnsMessage::parseRecords($packet);

        $this->assertCount(4, $records);

        $devices = (new MdnsBrowser)->assemble('_smartclock._tcp.local', $records);

        $this->assertCount(1, $devices);
        $this->assertSame('clock-ab12', $devices[0]->id);
        $this->assertSame('192.168.1.50', $devices[0]->host);
        $this->assertSame('clock-ab12.local', $devices[0]->hostname);
        $this->assertSame('1.0.2', $devices[0]->fw);
        $this->assertFalse($devices[0]->paired);
        $this->assertSame('ClockStation-AB12', $devices[0]->name);
    }

    public function test_falls_back_to_responder_address_without_a_record(): void
    {
        $records = array_values(array_filter(
            DnsMessage::parseRecords($this->responsePacket()),
            fn ($r) => $r['type'] !== DnsMessage::TYPE_A,
        ));

        $devices = (new MdnsBrowser)->assemble('_smartclock._tcp.local', $records, [
            'clockstation-ab12._smartclock._tcp.local' => '192.168.1.99',
        ]);

        $this->assertSame('192.168.1.99', $devices[0]->host);
    }

    public function test_garbage_does_not_throw(): void
    {
        $this->assertSame([], DnsMessage::parseRecords('short'));
        $this->assertIsArray(DnsMessage::parseRecords(pack('n6', 0, 0x8400, 0, 3, 0, 0)."\xc0\xff\xc0\x0c"));
    }

    public function test_subnet_hosts(): void
    {
        $hosts = SubnetProber::hosts('192.168.1.77/24');

        $this->assertCount(254, $hosts);
        $this->assertSame('192.168.1.1', $hosts[0]);
        $this->assertSame('192.168.1.254', $hosts[253]);
        $this->assertSame(['10.0.0.5'], SubnetProber::hosts('10.0.0.5/32'));

        $this->expectException(\InvalidArgumentException::class);
        SubnetProber::hosts('10.0.0.0/16');
    }

    /** PTR + SRV + TXT + A answer as an ESP32 mDNS responder would send it. */
    private function responsePacket(): string
    {
        $header = pack('n6', 0, 0x8400, 0, 4, 0, 0);
        $service = DnsMessage::encodeName('_smartclock._tcp.local'); // at offset 12
        $serviceOffset = 12;

        $packet = $header;
        // PTR: _smartclock._tcp.local -> ClockStation-AB12.<ptr to service>
        $instance = "\x11ClockStation-AB12".pack('n', 0xC000 | $serviceOffset);
        $packet .= $service.pack('nnNn', DnsMessage::TYPE_PTR, 1, 120, strlen($instance)).$instance;
        $instanceOffset = 12 + strlen($service) + 10;

        // SRV: <ptr to instance> -> clock-ab12.local:80
        $target = "\x0aclock-ab12".pack('n', 0xC000 | ($serviceOffset + 17)); // "local" label inside the service name
        $srv = pack('nnn', 0, 0, 80).$target;
        $packet .= pack('n', 0xC000 | $instanceOffset).pack('nnNn', DnsMessage::TYPE_SRV, 0x8001, 120, strlen($srv)).$srv;
        $targetOffset = strlen($packet) - strlen($target);

        // TXT
        $txt = '';
        foreach (['id=clock-ab12', 'type=clock', 'fw=1.0.2', 'paired=0'] as $entry) {
            $txt .= chr(strlen($entry)).$entry;
        }
        $packet .= pack('n', 0xC000 | $instanceOffset).pack('nnNn', DnsMessage::TYPE_TXT, 0x8001, 120, strlen($txt)).$txt;

        // A: <ptr to target> -> 192.168.1.50
        $packet .= pack('n', 0xC000 | $targetOffset).pack('nnNn', DnsMessage::TYPE_A, 0x8001, 120, 4).inet_pton('192.168.1.50');

        return $packet;
    }
}
