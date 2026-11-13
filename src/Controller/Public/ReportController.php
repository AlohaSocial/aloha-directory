<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Api\RateLimits;
use App\Entity\Complaint;
use App\Service\ApiProblem;
use App\Service\ComplaintIntake;
use App\Validation\ValidationFailed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The one dynamic page of the public website: the complaint form (concept 7.5, 13.5).
 */
final class ReportController extends AbstractController
{
    #[Route('/report', name: 'report', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ComplaintIntake $intake, RateLimits $limits): Response
    {
        $values = ['domain' => mb_substr((string) $request->query->get('domain', ''), 0, 253), 'category' => '', 'text' => '', 'contact_email' => ''];
        $errors = [];
        $submitted = false;

        if ($request->isMethod('POST')) {
            foreach (array_keys($values) as $field) {
                $values[$field] = mb_substr((string) $request->request->get($field, ''), 0, 6000);
            }
            if (!$this->isCsrfTokenValid('report', (string) $request->request->get('_csrf_token'))) {
                $errors['_form'] = ['Your session expired. Please send the form again.'];
            } elseif ('' !== (string) $request->request->get('website', '')) {
                $submitted = true; // honeypot: quietly accept and drop
            } else {
                try {
                    $limits->consume('report', (string) $request->getClientIp());
                    $intake->submit([
                        'domain' => $values['domain'],
                        'category' => $values['category'],
                        'text' => $values['text'],
                        'contact_email' => '' === $values['contact_email'] ? null : $values['contact_email'],
                    ]);
                    $submitted = true;
                } catch (ValidationFailed $e) {
                    $errors = $e->errors;
                } catch (ApiProblem $e) {
                    $errors['_form'] = [429 === $e->status ? 'You sent several reports in a short time. Please try again later.' : $e->getMessage()];
                }
            }
        }

        $response = $this->render('site/report.html.twig', [
            'categories' => Complaint::CATEGORIES,
            'values' => $values,
            'errors' => $errors,
            'submitted' => $submitted,
            'csrf_token' => $this->container->get('security.csrf.token_manager')->getToken('report')->getValue(),
        ], new Response(null, [] === $errors ? 200 : 422));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
