<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contact;
use App\Entity\Newsletter;
use App\Repository\NewsletterSubscriptionRepository;
use App\Service\Newsletter\NewsletterConfirmSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public double-opt-in confirmation from the link in the confirmation email. The
 * signed token IS the credential (security.yaml `^/v1/newsletter/confirm`
 * PUBLIC_ACCESS) — mirrors {@see NewsletterUnsubscribeController}. Idempotent:
 * confirming twice still reports success. A malformed/forged/expired token 404s.
 * A confirmation for a withdrawn subscription does NOT resurrect it.
 *
 *   GET  /v1/newsletter/confirm/{token}  → { newsletterTitle, confirmed }
 *   POST /v1/newsletter/confirm/{token}  → confirm the subscription
 */
final class NewsletterConfirmController
{
    public function __construct(
        private readonly NewsletterConfirmSigner $signer,
        private readonly NewsletterSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/newsletter/confirm/{token}',
        name: 'api_newsletter_confirm_info',
        requirements: ['token' => '[A-Za-z0-9._-]{1,512}'],
        methods: ['GET'],
    )]
    public function info(string $token): JsonResponse
    {
        [$newsletter, $contact] = $this->resolve($token);
        $sub = $this->subscriptions->findOneForContact($newsletter, $contact);

        return new JsonResponse([
            'newsletterTitle' => $newsletter->getTitle(),
            'confirmed' => $sub !== null && $sub->isConfirmed(),
        ]);
    }

    #[Route(
        path: '/v1/newsletter/confirm/{token}',
        name: 'api_newsletter_confirm',
        requirements: ['token' => '[A-Za-z0-9._-]{1,512}'],
        methods: ['POST'],
    )]
    public function confirm(string $token): JsonResponse
    {
        [$newsletter, $contact] = $this->resolve($token);
        $sub = $this->subscriptions->findOneForContact($newsletter, $contact);
        // Only confirm a live (non-withdrawn) opt-in; never resurrect a revoked row.
        if ($sub !== null && $sub->isActive()) {
            $sub->confirm();
            $this->em->flush();
        }

        return new JsonResponse(['confirmed' => true, 'newsletterTitle' => $newsletter->getTitle()]);
    }

    /**
     * @return array{0: Newsletter, 1: Contact}
     */
    private function resolve(string $token): array
    {
        $ids = $this->signer->verify($token);
        if ($ids === null) {
            throw new NotFoundHttpException();
        }
        $newsletter = $this->em->find(Newsletter::class, $ids['newsletterId']);
        $contact = $this->em->find(Contact::class, $ids['contactId']);
        if (!$newsletter instanceof Newsletter || !$contact instanceof Contact) {
            throw new NotFoundHttpException();
        }

        return [$newsletter, $contact];
    }
}
