<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Complaint;
use App\Enum\ServerState;
use App\Net\DomainName;
use App\Repository\ServerRepository;
use App\Validation\InputReader;
use App\Validation\ValidationFailed;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Complaints from anyone about a listed server (concept 7.5). Shared by the API and the /report page.
 */
final class ComplaintIntake
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ServerRepository $servers,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly DirectorySettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed> $input domain, category, text, contact_email (optional)
     */
    public function submit(array $input): Complaint
    {
        $in = new InputReader($input);
        $domainInput = $in->string('domain', 253);
        $category = $in->choice('category', array_keys(Complaint::CATEGORIES));
        $text = $in->string('text', 5000, true, 20, true);
        $email = $in->email('contact_email', false);

        $server = null;
        if (null !== $domainInput) {
            try {
                $server = $this->servers->findOneByDomain(DomainName::normalize($domainInput));
            } catch (\InvalidArgumentException) {
            }
            if (null === $server || !\in_array($server->state, [ServerState::Listed, ServerState::Paused], true)) {
                $in->error('domain', 'is not a server listed in the directory');
                $server = null;
            }
        }
        if ([] !== $in->errors() || null === $server || null === $category || null === $text) {
            throw new ValidationFailed($in->errors());
        }

        $complaint = new Complaint($server, $category, $text, $email, $this->clock->now());
        $this->em->persist($complaint);
        $this->audit->public('complaint.received', $server, ['category' => $category]);
        $this->em->flush();

        $this->notifier->curators(
            \sprintf('New complaint about %s', $server->domain),
            \sprintf("A complaint (%s) about %s is in the queue.\n\n%s/console/complaints/%d", Complaint::CATEGORIES[$category], $server->domain, $this->settings->publicUrl, $complaint->id),
        );

        return $complaint;
    }
}
