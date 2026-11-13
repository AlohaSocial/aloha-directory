<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\FunctionalTestCase;
use App\Tests\Support\TestKeys;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The JavaScript reference verifier for app developers must accept exactly what the directory publishes.
 */
final class ReferenceVerifierTest extends FunctionalTestCase
{
    public function testNodeVerifierAcceptsPublishedLists(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if (null === $node) {
            self::markTestSkipped('Node.js is not installed.');
        }
        $this->installKeySet();
        $this->listServer($this->enrolServer('social.example.org'));
        $this->tick();

        $root = static::getContainer()->getParameter('kernel.project_dir');
        $dir = $root.'/var/test-output/lists/v1/';
        $args = [$node, $root.'/tools/verify-list.mjs', '--root', TestKeys::ROOT_KID.'='.TestKeys::encode(TestKeys::root()[1]), '--keys', $dir.'keys.json', '--now', $this->clock->now()->format(\DATE_ATOM)];

        foreach (['servers', 'search'] as $list) {
            $process = new Process([...$args, '--list', $dir.$list.'.json', '--name', $list]);
            $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertStringStartsWith('OK '.$list.'.json', $process->getOutput());
        }

        // A wrong root key must fail.
        $process = new Process([$node, $root.'/tools/verify-list.mjs', '--root', 'x='.TestKeys::encode(TestKeys::random()[1]), '--keys', $dir.'keys.json', '--list', $dir.'servers.json']);
        $process->run();
        self::assertFalse($process->isSuccessful());
    }
}
