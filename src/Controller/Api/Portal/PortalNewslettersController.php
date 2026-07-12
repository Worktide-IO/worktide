<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Enum\NewsletterConsentSource;
use App\Entity\Newsletter;
use App\Entity\NewsletterSubscription;
use App\Repository\NewsletterRepository;
use App\Repository\NewsletterSubscriptionRepository;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Customer-portal newsletter subscriptions (roadmap §7 "Newsletter-Verwaltung").
 *
 * The customer's staff-granted newsletter nodes ({@see \App\Entity\Customer::$enabledNewsletterIds})
 * are shown as a tree; the contact opts in/out per node. Nodes that are only
 * ancestors of a granted node are returned as non-subscribable structure so the
 * tree reads correctly. Gated by the `newsletters` portal feature.
 *
 * Subscribe/unsubscribe are idempotent (one {@see NewsletterSubscription} per
 * contact per node, unique-constrained). Every mutation re-checks that the node
 * is actually granted to the caller's customer — fail-closed 404, never trusting
 * the id alone (mirrors PortalIdeasController::findIdeaOr404).
 */
final class PortalNewslettersController
{
    public function __construct(
        private readonly PortalAccessResolver $portal,
        private readonly NewsletterRepository $newsletters,
        private readonly NewsletterSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route(
        path: '/v1/portal/newsletters',
        name: 'api_portal_newsletters_list',
        methods: ['GET'],
    )]
    public function list(): JsonResponse
    {
        $this->portal->assertFeatureEnabled('newsletters');

        $customer = $this->portal->customer();
        $contact = $this->portal->contact();

        $enabled = array_flip($customer->getEnabledNewsletterIds());
        $subscribed = array_flip($this->subscriptions->subscribedNewsletterIds($contact));

        // Flat → children-by-parent map (root bucket keyed '').
        $childrenByParent = [];
        foreach ($this->newsletters->findAllForWorkspace($this->portal->workspace()) as $node) {
            $pid = $node->getParent()?->getId()?->toRfc4122() ?? '';
            $childrenByParent[$pid][] = $node;
        }

        return new JsonResponse([
            'newsletters' => $this->buildForest($childrenByParent[''] ?? [], $childrenByParent, $enabled, $subscribed),
        ]);
    }

    #[Route(
        path: '/v1/portal/newsletters/{id}/subscription',
        name: 'api_portal_newsletters_subscribe',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['POST'],
    )]
    public function subscribe(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('newsletters');

        $newsletter = $this->findEnabledNewsletterOr404($id);
        $contact = $this->portal->contact();

        // One row per (newsletter, contact): create it, or reactivate a
        // previously-revoked one. Setting the toggle IS the consent act, so we
        // stamp consentedAt/consentSource here (Portal origin).
        $existing = $this->subscriptions->findOneForContact($newsletter, $contact);
        if ($existing === null) {
            $sub = (new NewsletterSubscription())->setNewsletter($newsletter)->setContact($contact);
            $sub->grantConsent(NewsletterConsentSource::Portal);
            $this->em->persist($sub);
            $this->em->flush();
        } elseif (!$existing->isActive()) {
            $existing->grantConsent(NewsletterConsentSource::Portal);
            $this->em->flush();
        }

        return new JsonResponse(['id' => $id, 'subscribed' => true]);
    }

    #[Route(
        path: '/v1/portal/newsletters/{id}/subscription',
        name: 'api_portal_newsletters_unsubscribe',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['DELETE'],
    )]
    public function unsubscribe(string $id): JsonResponse
    {
        $this->portal->assertFeatureEnabled('newsletters');

        $newsletter = $this->findEnabledNewsletterOr404($id);
        // Soft opt-out: keep the row (consent audit trail), stamp revokedAt.
        $existing = $this->subscriptions->findOneForContact($newsletter, $this->portal->contact());
        if ($existing !== null && $existing->isActive()) {
            $existing->revoke();
            $this->em->flush();
        }

        return new JsonResponse(['id' => $id, 'subscribed' => false]);
    }

    /**
     * Prune the tree to nodes that are enabled for the customer OR have an
     * enabled descendant. Enabled nodes are `subscribable`; ancestors kept only
     * for structure are `subscribable: false`.
     *
     * @param list<Newsletter>                 $nodes
     * @param array<string, list<Newsletter>>  $childrenByParent
     * @param array<string, int>               $enabled     set of enabled ids
     * @param array<string, int>               $subscribed  set of subscribed ids
     * @return list<array<string, mixed>>
     */
    private function buildForest(array $nodes, array $childrenByParent, array $enabled, array $subscribed): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $id = $node->getId()?->toRfc4122();
            if ($id === null) {
                continue;
            }
            $children = $this->buildForest($childrenByParent[$id] ?? [], $childrenByParent, $enabled, $subscribed);
            $isEnabled = isset($enabled[$id]);
            if (!$isEnabled && $children === []) {
                continue; // neither granted nor an ancestor of a granted node
            }
            $frequency = $node->getEstimatedFrequency();
            $out[] = [
                'id' => $id,
                'title' => $node->getTitle(),
                'description' => $node->getDescription(),
                // Per-locale title/description overrides (see localize() in the portal).
                'translations' => $node->getTranslations(),
                'estimatedFrequency' => $frequency?->value,
                'estimatedFrequencyLabel' => $frequency !== null
                    ? $this->translator->trans('label.newsletter_frequency.' . $frequency->value)
                    : null,
                'subscribable' => $isEnabled,
                'subscribed' => $isEnabled && isset($subscribed[$id]),
                'children' => $children,
            ];
        }

        return $out;
    }

    private function findEnabledNewsletterOr404(string $id): Newsletter
    {
        $newsletter = $this->newsletters->find(Uuid::fromString($id));
        $canonicalId = $newsletter?->getId()?->toRfc4122();
        if (
            !$newsletter instanceof Newsletter
            || $newsletter->getDeletedAt() !== null
            || $newsletter->getWorkspace()->getId()?->toRfc4122() !== $this->portal->workspace()->getId()?->toRfc4122()
            || !\in_array($canonicalId, $this->portal->customer()->getEnabledNewsletterIds(), true)
        ) {
            throw new NotFoundHttpException('Newsletter not found.');
        }

        return $newsletter;
    }
}
