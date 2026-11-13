<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Server;
use App\Repository\CuratorRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Plain-text notifications. A failing mail transport never breaks the action that triggered it.
 */
final class Notifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly CuratorRepository $curators,
        private readonly DirectorySettings $settings,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(DIRECTORY_MAIL_FROM)%')]
        private readonly string $from,
    ) {
    }

    public function curators(string $subject, string $body): void
    {
        foreach ($this->curators->findActive() as $curator) {
            $this->send($curator->email, $subject, $body);
        }
    }

    public function serverAdmin(Server $server, string $subject, string $body): void
    {
        if (null === $server->adminEmail) {
            return;
        }
        $this->send($server->adminEmail, $subject, $body."\n\nYou can also see this in the Aloha directory section of your server's admin settings.");
    }

    public function send(string $to, string $subject, string $body): void
    {
        $email = (new Email())
            ->from($this->from)
            ->to($to)
            ->subject('[Aloha Directory] '.str_replace(["\r", "\n"], ' ', $subject))
            ->text($body."\n\n-- \nAloha Directory · ".$this->settings->publicUrl."\n");
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Notification mail could not be sent.', ['subject' => $subject, 'exception' => $e]);
        }
    }
}
