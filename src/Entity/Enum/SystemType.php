<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Common system kinds an agency hosts/maintains for customers.
 *
 * Values are stable strings — never rename a case, integrations will pin to
 * them (e.g. the planned TYPO3 client portal renders a TYPO3-specific
 * dashboard for "typo3"). `Other` is the escape hatch when the customer
 * runs something we don't have a first-class concept for yet.
 */
enum SystemType: string
{
    case TYPO3 = 'typo3';
    case WordPress = 'wordpress';
    case Drupal = 'drupal';
    case Magento = 'magento';
    case Shopware = 'shopware';
    case Joomla = 'joomla';
    case Symfony = 'symfony';
    case Laravel = 'laravel';
    case Static_ = 'static';
    case Other = 'other';
}
