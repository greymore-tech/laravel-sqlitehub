<?php

namespace SQLiteHub\Laravel\Tests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use SQLiteHub\Laravel\Hrana\Values;

class ValuesTest extends TestCase
{
    public function test_encode(): void
    {
        $this->assertSame(['type' => 'null'], Values::encode(null));
        $this->assertSame(['type' => 'integer', 'value' => '1'], Values::encode(true));
        $this->assertSame(['type' => 'integer', 'value' => '0'], Values::encode(false));
        $this->assertSame(['type' => 'integer', 'value' => '9223372036854775807'], Values::encode(PHP_INT_MAX));
        $this->assertSame(['type' => 'integer', 'value' => '-5'], Values::encode(-5, PDO::PARAM_INT));
        $this->assertSame(['type' => 'float', 'value' => 1.5], Values::encode(1.5));
        $this->assertSame(['type' => 'text', 'value' => 'héllo ₹'], Values::encode('héllo ₹'));
        $this->assertSame(['type' => 'blob', 'base64' => base64_encode("\xff\x00")], Values::encode("\xff\x00"));
        $this->assertSame(['type' => 'blob', 'base64' => base64_encode('abc')], Values::encode('abc', PDO::PARAM_LOB));
        $this->assertSame(['type' => 'text', 'value' => 'str'], Values::encode(new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'str';
            }
        }));

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "\x01\x02");
        rewind($stream);
        $this->assertSame(['type' => 'blob', 'base64' => base64_encode("\x01\x02")], Values::encode($stream, PDO::PARAM_LOB));
    }

    public function test_encode_rejects_other_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot bind a value of type array');
        Values::encode([1]);
    }

    public function test_decode(): void
    {
        $this->assertNull(Values::decode(['type' => 'null']));
        $this->assertSame(PHP_INT_MIN, Values::decode(['type' => 'integer', 'value' => '-9223372036854775808']));
        $this->assertSame(2.0, Values::decode(['type' => 'float', 'value' => 2]));
        $this->assertSame('x', Values::decode(['type' => 'text', 'value' => 'x']));
        $this->assertSame("\xff\x00", Values::decode(['type' => 'blob', 'base64' => base64_encode("\xff\x00")]));
        $this->assertNull(Values::decode(['type' => 'something-new']));
    }
}
