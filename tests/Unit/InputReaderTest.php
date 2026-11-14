<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Validation\InputReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InputReaderTest extends TestCase
{
    public function testRawWalksDottedPaths(): void
    {
        $in = new InputReader(['a' => ['b' => ['c' => 5], 's' => 'x', 'n' => null, 'f' => false]]);

        self::assertSame(5, $in->raw('a.b.c'));
        self::assertSame(['c' => 5], $in->raw('a.b'));
        self::assertNull($in->raw('a.s.deeper'), 'cannot descend into a string');
        self::assertNull($in->raw('a.missing'));
        self::assertNull($in->raw('a.b.c.d'));
        self::assertTrue($in->has('a.f'), 'false is present');
        self::assertFalse($in->has('a.n'), 'explicit null counts as absent');
        self::assertFalse($in->has('x'));
        self::assertSame([], $in->errors(), 'reading never records errors');
    }

    public function testRequiredAndOptionalAbsence(): void
    {
        $in = new InputReader(['present' => null]);
        self::assertNull($in->string('s', 10));
        self::assertNull($in->int('i', 0, 1));
        self::assertNull($in->float('f', 0, 1));
        self::assertNull($in->bool('b'));
        self::assertNull($in->choice('c', ['x']));
        self::assertNull($in->subset('u', ['x']));
        self::assertNull($in->url('url'));
        self::assertNull($in->email('e'));
        self::assertNull($in->stringList('l', 5, 5));
        self::assertNull($in->string('present', 10));
        $errors = $in->errors();
        self::assertSame(['s', 'i', 'f', 'b', 'c', 'u', 'url', 'e', 'l', 'present'], array_keys($errors));
        foreach ($errors as $messages) {
            self::assertSame(['is required'], $messages);
        }

        $optional = new InputReader([]);
        self::assertNull($optional->string('s', 10, false));
        self::assertNull($optional->int('i', 0, 1, false));
        self::assertNull($optional->float('f', 0, 1, false));
        self::assertNull($optional->bool('b', false));
        self::assertNull($optional->choice('c', ['x'], false));
        self::assertNull($optional->subset('u', ['x'], false));
        self::assertNull($optional->url('url', false));
        self::assertNull($optional->email('e', false));
        self::assertNull($optional->stringList('l', 5, 5, false));
        self::assertSame([], $optional->errors());
    }

    #[DataProvider('nonStrings')]
    public function testStringRejectsOtherTypes(mixed $value): void
    {
        $in = new InputReader(['s' => $value]);
        self::assertNull($in->string('s', 100));
        self::assertSame(['s' => ['must be a string']], $in->errors());
    }

    public static function nonStrings(): iterable
    {
        yield 'int' => [5];
        yield 'float' => [1.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'list' => [['a']];
        yield 'object' => [['a' => 'b']];
    }

    public function testStringRejectsInvalidUtf8(): void
    {
        $in = new InputReader(['s' => "ok\xC3\x28"]);
        self::assertNull($in->string('s', 100));
        self::assertSame(['s' => ['must be valid UTF-8']], $in->errors());
    }

    public function testStringLengthIsCountedInCharactersAfterCleaning(): void
    {
        $in = new InputReader([
            'umlauts' => str_repeat('ä', 10),
            'decomposed' => str_repeat("a\u{0308}", 10), // NFC turns each pair into one character
            'padded' => '   '.str_repeat('x', 10).'   ',
            'controls' => str_repeat("x\u{202E}", 10),
            'eleven' => str_repeat('ä', 11),
        ]);

        self::assertSame(str_repeat('ä', 10), $in->string('umlauts', 10));
        self::assertSame(str_repeat('ä', 10), $in->string('decomposed', 10));
        self::assertSame(str_repeat('x', 10), $in->string('padded', 10));
        self::assertSame(str_repeat('x', 10), $in->string('controls', 10));
        self::assertNull($in->string('eleven', 10));
        self::assertSame(['eleven' => ['must have at most 10 characters']], $in->errors());
    }

    public function testStringMinimumLength(): void
    {
        $in = new InputReader(['blank' => " \t\n ", 'short' => 'ab', 'opt_blank' => '   ', 'opt_short' => 'ab', 'invisible' => "\u{FEFF}\u{202A}\u{2069}\x00"]);

        self::assertNull($in->string('blank', 10), 'whitespace only is empty');
        self::assertNull($in->string('short', 10, true, 3));
        self::assertNull($in->string('opt_blank', 10, false), 'optional and blank: absent, no error');
        $in->string('opt_short', 10, false, 3);
        self::assertNull($in->string('invisible', 10), 'only stripped characters: empty');

        $errors = $in->errors();
        self::assertSame(['must have at least 1 characters'], $errors['blank']);
        self::assertSame(['must have at least 3 characters'], $errors['short']);
        self::assertArrayNotHasKey('opt_blank', $errors);
        self::assertSame(['must have at least 3 characters'], $errors['opt_short'], 'optional but too short is still an error');
        self::assertSame(['must have at least 1 characters'], $errors['invisible']);
    }

    public function testSingleLineAndMultilineCleaning(): void
    {
        $in = new InputReader(['s' => "a\n\nb\tc", 'm' => "a\r\n\r\n\r\n\r\nb\x07\u{2066}c"]);
        self::assertSame('a b c', $in->string('s', 100));
        self::assertSame("a\n\nbc", $in->string('m', 100, true, 1, true));
    }

    #[DataProvider('ints')]
    public function testInt(mixed $value, ?int $expected, ?string $error): void
    {
        $in = new InputReader(['i' => $value]);
        self::assertSame($expected, $in->int('i', -5, 10));
        self::assertSame(null === $error ? [] : ['i' => [$error]], $in->errors());
    }

    public static function ints(): iterable
    {
        yield 'min' => [-5, -5, null];
        yield 'max' => [10, 10, null];
        yield 'below' => [-6, null, 'must be between -5 and 10'];
        yield 'above' => [11, null, 'must be between -5 and 10'];
        yield 'float with integral value' => [3.0, null, 'must be an integer'];
        yield 'numeric string' => ['3', null, 'must be an integer'];
        yield 'bool' => [true, null, 'must be an integer'];
        yield 'huge' => [\PHP_INT_MAX, null, 'must be between -5 and 10'];
    }

    #[DataProvider('floats')]
    public function testFloat(mixed $value, ?float $expected, ?string $error): void
    {
        $in = new InputReader(['f' => $value]);
        self::assertSame($expected, $in->float('f', 0.0, 1.0));
        self::assertSame(null === $error ? [] : ['f' => [$error]], $in->errors());
    }

    public static function floats(): iterable
    {
        yield 'zero int' => [0, 0.0, null];
        yield 'one int' => [1, 1.0, null];
        yield 'fraction' => [0.25, 0.25, null];
        yield 'negative' => [-0.01, null, 'must be between 0 and 1'];
        yield 'above' => [1.0001, null, 'must be between 0 and 1'];
        yield 'nan' => [\NAN, null, 'must be between 0 and 1'];
        yield 'infinity' => [\INF, null, 'must be between 0 and 1'];
        yield 'string' => ['0.5', null, 'must be a number'];
        yield 'bool' => [false, null, 'must be a number'];
    }

    public function testBoolIsStrict(): void
    {
        $in = new InputReader(['t' => true, 'f' => false, 'one' => 1, 'str' => 'true']);
        self::assertTrue($in->bool('t'));
        self::assertFalse($in->bool('f'));
        self::assertNull($in->bool('one'));
        self::assertNull($in->bool('str'));
        self::assertSame(['one' => ['must be true or false'], 'str' => ['must be true or false']], $in->errors());
    }

    public function testChoiceIsExactAndCaseSensitive(): void
    {
        $in = new InputReader(['a' => 'open', 'b' => 'Open', 'c' => ['open'], 'd' => 0]);
        self::assertSame('open', $in->choice('a', ['open', 'closed']));
        self::assertNull($in->choice('b', ['open', 'closed']));
        self::assertNull($in->choice('c', ['open', 'closed']));
        self::assertNull($in->choice('d', ['0', 'open']), 'no loose comparison of 0 with strings');
        self::assertSame(['must be one of: open, closed'], $in->errors()['b']);
        self::assertSame(['b', 'c', 'd'], array_keys($in->errors()));
    }

    public function testSubsetIsDeduplicatedAndSorted(): void
    {
        $in = new InputReader(['s' => ['video', 'dm', 'video'], 'empty' => []]);
        self::assertSame(['dm', 'video'], $in->subset('s', ['dm', 'shorts', 'video']));
        self::assertSame([], $in->subset('empty', ['dm']));
        self::assertSame([], $in->errors());
    }

    #[DataProvider('badSubsets')]
    public function testSubsetRejects(mixed $value, string $error): void
    {
        $in = new InputReader(['s' => $value]);
        self::assertNull($in->subset('s', ['dm', 'video']));
        self::assertSame(['s' => [$error]], $in->errors());
    }

    public static function badSubsets(): iterable
    {
        yield 'string' => ['dm', 'must be a list'];
        yield 'map' => [['a' => 'dm'], 'must be a list'];
        yield 'unknown member' => [['dm', 'voice'], 'may only contain: dm, video'];
        yield 'wrong case' => [['DM'], 'may only contain: dm, video'];
        yield 'non-string member' => [[1], 'may only contain: dm, video'];
    }

    #[DataProvider('urls')]
    public function testUrl(string $url, bool $valid): void
    {
        $in = new InputReader(['u' => $url]);
        $result = $in->url('u');
        if ($valid) {
            self::assertSame($url, $result);
            self::assertSame([], $in->errors());
        } else {
            self::assertNull($result, $url);
            self::assertArrayHasKey('u', $in->errors());
        }
    }

    public static function urls(): iterable
    {
        yield 'plain' => ['https://social.example.org/terms', true];
        yield 'query and fragment' => ['https://social.example.org/a?b=c#d', true];
        yield 'port' => ['https://social.example.org:8443/x', true];
        yield 'idn' => ['https://bücher.example/x', true];
        yield '2000 characters' => ['https://e.org/'.str_repeat('a', 1986), true];
        yield '2001 characters' => ['https://e.org/'.str_repeat('a', 1987), false];
        yield 'http' => ['http://social.example.org/terms', false];
        yield 'upper case scheme' => ['HTTPS://social.example.org/', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,<script>', false];
        yield 'protocol relative' => ['//social.example.org/', false];
        yield 'relative' => ['/terms', false];
        yield 'no host' => ['https:///terms', false];
        yield 'user' => ['https://user@social.example.org/', false];
        yield 'user and password' => ['https://u:p@social.example.org/', false];
        yield 'quote' => ['https://social.example.org/"onmouseover="x', false];
        yield 'angle bracket' => ['https://social.example.org/<script>', false];
        yield 'backslash' => ['https://social.example.org\\@evil.example/', false];
        yield 'backtick' => ['https://social.example.org/`', false];
        yield 'space inside' => ['https://social.example.org/a b', false];
    }

    public function testUrlCollapsesControlCharactersBeforeValidating(): void
    {
        // A newline inside a single-line string becomes a space, which a URL may not contain.
        $in = new InputReader(['u' => "https://social.example.org/a\nb"]);
        self::assertNull($in->url('u'));
        self::assertSame(['u' => ['must be an absolute https:// URL']], $in->errors());
    }

    #[DataProvider('emails')]
    public function testEmail(mixed $value, ?string $expected): void
    {
        $in = new InputReader(['e' => $value]);
        self::assertSame($expected, $in->email('e'));
        self::assertSame(null === $expected, [] !== $in->errors());
    }

    public static function emails(): iterable
    {
        yield 'plain' => ['abuse@social.example.org', 'abuse@social.example.org'];
        yield 'lowercased' => ['Abuse@Social.Example.ORG', 'abuse@social.example.org'];
        yield 'trimmed' => ['  abuse@social.example.org ', 'abuse@social.example.org'];
        yield 'no at' => ['abuse.social.example.org', null];
        yield 'two ats' => ['a@b@example.org', null];
        yield 'display name' => ['Abuse <abuse@example.org>', null];
        yield 'header injection' => ["abuse@example.org\nBcc: victim@example.org", null];
        yield 'too short' => ['a@', null];
        yield 'too long' => [str_repeat('a', 64).'@'.str_repeat('b', 186).'.org', null];
        yield 'not a string' => [42, null];
    }

    public function testStringList(): void
    {
        $in = new InputReader(['l' => ['  Be kind. ', "Line 1\r\nLine 2", 'ä']]);
        self::assertSame(['Be kind.', "Line 1\nLine 2", 'ä'], $in->stringList('l', 3, 20));
        self::assertSame([], $in->errors());
    }

    #[DataProvider('badStringLists')]
    public function testStringListRejects(mixed $value, string $path, string $error): void
    {
        $in = new InputReader(['l' => $value]);
        self::assertNull($in->stringList('l', 3, 5));
        self::assertSame([$path => [$error]], $in->errors());
    }

    public static function badStringLists(): iterable
    {
        yield 'string' => ['abc', 'l', 'must be a list'];
        yield 'map' => [['a' => 'x'], 'l', 'must be a list'];
        yield 'empty' => [[], 'l', 'must have between 1 and 3 items'];
        yield 'too many' => [['a', 'b', 'c', 'd'], 'l', 'must have between 1 and 3 items'];
        yield 'number item' => [['a', 5], 'l.1', 'must be a string'];
        yield 'invalid utf-8 item' => [["\xFF"], 'l.0', 'must be a string'];
        yield 'blank item' => [['a', '  ', 'b'], 'l.1', 'must have between 1 and 5 characters'];
        yield 'long item' => [['abcdef'], 'l.0', 'must have between 1 and 5 characters'];
    }

    public function testAllErrorsAreCollected(): void
    {
        $in = new InputReader(['a' => 1, 'b' => 'x']);
        $in->string('a', 10);
        $in->int('b', 0, 1);
        $in->error('a', 'custom');
        self::assertSame(['a' => ['must be a string', 'custom'], 'b' => ['must be an integer']], $in->errors());
    }
}
