<?php

declare(strict_types=1);

namespace App\Controller\Api\Portal;

use App\Entity\Enum\NewsletterConsentSource;
use App\Entity\Newsletter;
use App\Entity\NewsletterSubscription;
use App\Repository\NewsletterRepository;
use App\Repository\NewsletterSubscriptionRepository;
use App\Service\Newsletter\NewsletterMailer;
use App\Service\Portal\PortalAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        private readonly NewsletterMailer $mailer,
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
        $pending = array_flip($this->subscriptions->pendingNewsletterIds($contact));

        // Flat → children-by-parent map (root bucket keyed '').
        $childrenByParent = [];
        foreach ($this->newsletters->findAllForWorkspace($this->portal->workspace()) as $node) {
            $pid = $node->getParent()?->getId()?->toRfc4122() ?? '';
            $childrenByParent[$pid][] = $node;
        }

        return new JsonResponse([
            'newsletters' => $this->buildForest($childrenByParent[''] ?? [], $childrenByParent, $enabled, $subscribed, $pending),
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
        if ($newsletter->isMandatory()) {
            // Mandatory nodes are always on — nothing to (un)subscribe.
            throw new ConflictHttpException('This newsletter is mandatory and cannot be changed.');
        }
        $contact = $this->portal->contact();
        $doubleOptIn = $this->isDoubleOptInEnabled();

        // One row per (newsletter, contact): create it, or reactivate a
        // previously-revoked one. Setting the toggle IS the consent act, so we
        // stamp consentedAt/consentSource here (Portal origin).
        $existing = $this->subscriptions->findOneForContact($newsletter, $contact);
        $sub = $existing ?? (new NewsletterSubscription())->setNewsletter($newsletter)->setContact($contact);
        if ($existing === null) {
            $this->em->persist($sub);
        }

        if (!$sub->isEffective()) {
            // New, revoked, or still-pending. Re-stamp consent only when it's a
            // fresh opt-in (new row or a revoked one being reactivated) — a
            // pending row keeps its original consent timestamp.
            if ($existing === null || $existing->getRevokedAt() !== null) {
                $sub->grantConsent(NewsletterConsentSource::Portal);
            }
            if ($doubleOptIn) {
                $this->em->flush();
                $this->mailer->sendConfirmation($sub); // pending until the link is clicked
            } else {
                $sub->confirm();
                $this->em->flush();
            }
        }

        return new JsonResponse([
            'id' => $id,
            'subscribed' => $sub->isEffective(),
            'pending' => $sub->isPending(),
        ]);
    }

    /** Per-workspace double-opt-in switch (settings.newsletter.doubleOptIn; default off). */
    private function isDoubleOptInEnabled(): bool
    {
        $settings = $this->portal->workspace()->getSettings();

        return ($settings['newsletter']['doubleOptIn'] ?? false) === true;
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
        if ($newsletter->isMandatory()) {
            throw new ConflictHttpException('This newsletter is mandatory and cannot be changed.');
        }
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
     * @param array<string, int>               $subscribed  set of confirmed-subscribed ids
     * @param array<string, int>               $pending     set of opted-in-but-unconfirmed ids
     * @return list<array<string, mixed>>
     */
    private function buildForest(array $nodes, array $childrenByParent, array $enabled, array $subscribed, array $pending): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $id = $node->getId()?->toRfc4122();
            if ($id === null || $node->isArchived()) {
                continue; // archived nodes (and their subtree) are hidden from the portal
            }
            $children = $this->buildForest($childrenByParent[$id] ?? [], $childrenByParent, $enabled, $subscribed, $pending);
            $isGranted = isset($enabled[$id]);
            // Mandatory = granted + forced on, no toggle. Subscribable = granted +
            // opt-in allowed AND not mandatory. A granted-but-non-subscribable node
            // is a pure category header.
            $isMandatory = $isGranted && $node->isMandatory();
            $isSubscribable = $isGranted && $node->isSubscribable() && !$isMandatory;
            if (!$isSubscribable && !$isMandatory && $children === []) {
                continue; // neither an opt-in target, a forced node, nor an ancestor of one
            }
            $frequency = $node->getEstimatedFrequency();
            $out[] = [
                'id' => $id,
                'title' => $node->getTitle(),
                'description' => $node->getDescription(),
                // Per-locale title/description overrides (see localize() in the portal).
                'translations' => $node->getTranslations(),
                'icon' => $node->getIcon(),
                'color' => $node->getColor(),
                'slug' => $node->getSlug(),
                'estimatedFrequency' => $frequency?->value,
                'estimatedFrequencyLabel' => $frequency !== null
                    ? $this->translator->trans('label.newsletter_frequency.' . $frequency->value)
                    : null,
                'subscribable' => $isSubscribable,
                'mandatory' => $isMandatory,
                'subscribed' => $isMandatory || ($isSubscribable && isset($subscribed[$id])),
                // Opted in but awaiting a double-opt-in confirmation click.
                'pending' => $isSubscribable && isset($pending[$id]),
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
