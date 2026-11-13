<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\PendingEnrolment;
use App\Message\VerifyEnrolment;
use App\Service\ApiProblem;
use App\Service\ServerLifecycle;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs ServerLifecycle::verify() in a worker, so slow or hostile domains never hold a web worker.
 * The outcome is stored on the pending enrolment, where the server polls it.
 */
#[AsMessageHandler]
final class VerifyEnrolmentHandler
{
    public function __construct(
        private readonly ServerLifecycle $lifecycle,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(VerifyEnrolment $message): void
    {
        try {
            $this->lifecycle->verify($message->domain, $message->keyId);
            $this->em->flush();
        } catch (ApiProblem $e) {
            $this->fail($message, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Domain verification failed unexpectedly.', ['domain' => $message->domain, 'exception' => $e]);
            $this->fail($message, 'internal_error', 'The verification could not be completed; please try again later.');
        }
    }

    /**
     * Plain SQL, so the outcome is recorded even when the failure closed the entity manager.
     */
    private function fail(VerifyEnrolment $message, string $code, string $text): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE pending_enrolment SET verify_status = ?, verify_error = ?, verify_message = ?, verify_finished_at = ? WHERE domain = ? AND key_id = ?',
            [PendingEnrolment::VERIFY_FAILED, mb_substr($code, 0, 64), mb_substr($text, 0, 500), $this->clock->now()->format('Y-m-d H:i:s'), $message->domain, $message->keyId],
        );
    }
}
