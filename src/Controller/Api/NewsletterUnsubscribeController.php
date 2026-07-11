<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contact;
use App\Entity\Newsletter;
use App\Repository\NewsletterSubscriptionRepository;
use App\Service\Newsletter\NewsletterUnsubscribeSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public one-click unsubscribe from the link in a newsletter email. The signed
 * token IS the credential (security.yaml `^/v1/newsletter/unsubscribe`
 * PUBLIC_ACCESS) — no auth, mirroring the booking cancel-token. Idempotent: a
 * missing subscription still reports success. A malformed/forged token 404s so
 * nothing can be probed.
 *
 *   GET  /v1/newsletter/unsubscribe/{token}  → { newsletterTitle, unsubscribed }
 *   POST /v1/newsletter/unsubscribe/{token}  → remove the subscription
 */
final class NewsletterUnsubscribeController
{
    public function __construct(
        private readonly NewsletterUnsubscribeSigner $signer,
        private readonly NewsletterSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route(
        path: '/v1/newsletter/unsubscribe/{token}',
        name: 'api_newsletter_unsubscribe_info',
        requirements: ['token' => '[A-Za-z0-9._-]{1,256}'],
        methods: ['GET'],
    )]
    public function info(string $token): JsonResponse
    {
        [$newsletter, $contact] = $this->resolve($token);
        $sub = $this->subscriptions->findOneForContact($newsletter, $contact);

        return new JsonResponse([
            'newsletterTitle' => $newsletter->getTitle(),
            'unsubscribed' => $sub === null,
        ]);
    }

    #[Route(
        path: '/v1/newsletter/unsubscribe/{token}',
        name: 'api_newsletter_unsubscribe',
        requirements: ['token' => '[A-Za-z0-9._-]{1,256}'],
        methods: ['POST'],
    )]
    public function unsubscribe(string $token): JsonResponse
    {
        [$newsletter, $contact] = $this->resolve($token);
        $sub = $this->subscriptions->findOneForContact($newsletter, $contact);
        if ($sub !== null) {
            $this->em->remove($sub);
            $this->em->flush();
        }

        return new JsonResponse(['unsubscribed' => true, 'newsletterTitle' => $newsletter->getTitle()]);
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
