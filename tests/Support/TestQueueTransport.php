<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * A queue for the tests that, unlike InMemoryTransport, survives the service reset between test
 * client requests, as the database queue survives between web requests. DSN: test-queue://<name>.
 *
 * @implements TransportFactoryInterface<TestQueueTransport>
 */
final class TestQueueTransport implements TransportInterface, TransportFactoryInterface, MessageCountAwareInterface
{
    /** @var array<string, array<int, Envelope>> */
    private static array $queues = [];
    private static int $nextId = 1;

    public function __construct(private readonly string $name = '')
    {
    }

    public function getMessageCount(): int
    {
        return \count(self::$queues[$this->name] ?? []);
    }

    public static function clear(): void
    {
        self::$queues = [];
    }

    public function get(): iterable
    {
        return array_values(self::$queues[$this->name] ?? []);
    }

    public function ack(Envelope $envelope): void
    {
        unset(self::$queues[$this->name][(int) $envelope->last(TransportMessageIdStamp::class)?->getId()]);
    }

    public function reject(Envelope $envelope): void
    {
        $this->ack($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        $id = self::$nextId++;
        $envelope = $envelope->with(new TransportMessageIdStamp($id));
        self::$queues[$this->name][$id] = $envelope;

        return $envelope;
    }

    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new self(substr($dsn, \strlen('test-queue://')));
    }

    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'test-queue://');
    }
}
