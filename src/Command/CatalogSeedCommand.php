<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\ProductType;
use App\Entity\Enum\ProductStatus;
use App\Entity\Product;
use App\Entity\Workspace;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * One-shot command: populate product/service catalogue from wappler.systems research.
 * Idempotent — skips records already present (matched by slug + workspace).
 */
#[AsCommand(name: 'app:catalog:seed', description: 'Seed product catalogue from website research')]
final class CatalogSeedCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspace = $this->em->getRepository(Workspace::class)->findOneBy(['name' => 'WapplerSystems']);
        if (!$workspace) {
            $output->writeln('<error>Workspace WapplerSystems not found</error>');
            return Command::FAILURE;
        }

        $this->createServices($workspace, $output);
        $this->updateExtensionDescriptions($workspace, $output);
        $this->updateProductHierarchy($workspace, $output);

        $this->em->flush();
        $output->writeln('<info>Done.</info>');
        return Command::SUCCESS;
    }

    private function createServices(Workspace $workspace, OutputInterface $output): void
    {
        $services = [
            [
                'name' => 'TYPO3 Beratung', 'slug' => 'typo3-beratung',
                'description' => 'Strategische Beratung zu TYPO3: Architektur-Entscheidungen, CMS-Auswahl, Upgrade-Planung und technische Konzeption. Wir analysieren Ihre Anforderungen und entwickeln eine zukunftssichere TYPO3-Strategie.',
                'category' => 'Consulting',
            ],
            [
                'name' => 'Managed TYPO3 Hosting', 'slug' => 'managed-typo3-hosting',
                'description' => 'Server-Administration, Monitoring, Backups und DSGVO-konformes Hosting in Deutschland — alles aus einer Hand. Infrastruktur, die genau auf TYPO3 abgestimmt ist. Inkl. SSL, Firewall und 24/7-Überwachung.',
                'category' => 'Hosting',
            ],
            [
                'name' => 'TYPO3 SEO', 'slug' => 'typo3-seo',
                'description' => 'Bessere Sichtbarkeit für Ihre TYPO3-Website: Technisches SEO (Core Web Vitals, Structured Data), Content-Optimierung und Web-Analytics mit Matomo oder Google Search Console — DSGVO-konform.',
                'category' => 'Marketing',
            ],
            [
                'name' => 'Extension-Entwicklung', 'slug' => 'extension-entwicklung',
                'description' => 'Maßgeschneiderte TYPO3-Extensions nach Ihren Anforderungen. Ob Anpassung bestehender Erweiterungen oder Neuentwicklung — wir setzen Ihre Wünsche professionell um. Composer-kompatibel, getestet, dokumentiert.',
                'category' => 'Development',
            ],
            [
                'name' => 'TYPO3 Barrierefreiheit', 'slug' => 'typo3-barrierefreiheit',
                'description' => 'WCAG-konforme Umsetzung Ihrer TYPO3-Website: Audit, Konzeption und Implementierung barrierefreier Inhalte und Navigationen. Erfüllt gesetzliche Vorgaben und erweitert Ihre Zielgruppe.',
                'category' => 'Consulting',
            ],
            [
                'name' => 'TYPO3 Performance Analyse', 'slug' => 'typo3-performance-analyse',
                'description' => 'Detaillierte Performance-Analyse Ihrer TYPO3-Installation: Ladezeiten, Caching-Strategie, Datenbank-Optimierung und Server-Konfiguration. Mit konkretem Maßnahmenkatalog zur Optimierung.',
                'category' => 'Consulting',
            ],
            [
                'name' => 'TYPO3 Notfall-Hilfe', 'slug' => 'typo3-notfall-hilfe',
                'description' => 'Schnelle Soforthilfe bei TYPO3-Problemen: Website down, Sicherheitsvorfall, Update-Fehler oder kritische Bugs. Wir reagieren kurzfristig und bringen Ihre Installation wieder zum Laufen.',
                'category' => 'Support',
            ],
            [
                'name' => 'TYPO3 Support & Wartung', 'slug' => 'typo3-support-wartung',
                'description' => 'Laufende Betreuung Ihrer TYPO3-Website: Sicherheitsupdates, Core- und Extension-Updates, Fehleranalyse und schnelle Hilfe bei Problemen. Mit oder ohne Service-Vertrag buchbar.',
                'category' => 'Support',
            ],
            [
                'name' => 'TYPO3 Schulungen', 'slug' => 'typo3-schulungen',
                'description' => 'Individuelle Schulungen für Ihr Team: TYPO3-Redakteursschulungen, Composer-Workshops oder technische Einführungen — praxisnah und auf Ihren Wissensstand zugeschnitten. Vor Ort oder remote.',
                'category' => 'Training',
            ],
            [
                'name' => 'Sicherheit & Spam-Schutz', 'slug' => 'sicherheit-spam-schutz',
                'description' => 'Aktuelle Server-Software, mehrstufiger Spam-Schutz und proaktive Sicherheitsmaßnahmen für Ihre Webanwendungen. Inkl. Security-Audit, Härten der Konfiguration und Monitoring.',
                'category' => 'Security',
            ],
            [
                'name' => 'Domains & DNS', 'slug' => 'domains-dns',
                'description' => 'Domainregistrierung, -transfer und DNS-Management: Wir sichern Ihre Wunschdomain, konfigurieren DNS-Einträge (A, MX, TXT, CNAME) und beraten Sie bei der richtigen Domain-Strategie.',
                'category' => 'Infrastructure',
            ],
            [
                'name' => 'Webentwicklung & Relaunch', 'slug' => 'webentwicklung-relaunch',
                'description' => 'Individuelle Websites und Webanwendungen auf Basis von TYPO3, WordPress oder Shopify. Ob Neuaufbau, Relaunch oder Erweiterung — wir setzen Ihr Projekt technisch sauber und zukunftssicher um.',
                'category' => 'Development',
            ],
            [
                'name' => 'B2B Zulieferung', 'slug' => 'b2b-zulieferung',
                'description' => 'Technische Zulieferung als Ihr verlängerter Arm: TYPO3-Entwicklung, Server-Setup oder Problemlösung — wir unterstützen Ihr Team flexibel und diskret im White-Label-Modell.',
                'category' => 'Development',
            ],
            [
                'name' => 'Video Streaming', 'slug' => 'video-streaming',
                'description' => 'Streaming-Lösungen für TYPO3: Integration von Video-Plattformen, eigenes Hosting mit Player, Playlists, Cue-Points und Untertiteln. DSGVO-konform und responsive.',
                'category' => 'Media',
            ],
            [
                'name' => 'GSB Migration', 'slug' => 'gsb-migration',
                'description' => 'Migration vom Government Site Builder (GSB) 10 auf Version 11. Wir begleiten Behörden und öffentliche Einrichtungen bei der reibungslosen Umstellung mit minimaler Downtime.',
                'category' => 'Consulting',
            ],
            [
                'name' => 'WordPress', 'slug' => 'wordpress',
                'description' => 'WordPress-Entwicklung und -Betreuung: Theme-Entwicklung, Plugin-Programmierung, Performance-Optimierung und Sicherheits-Updates. Auch als Ergänzung zu TYPO3-Projekten.',
                'category' => 'Development',
            ],
            [
                'name' => 'Shopify', 'slug' => 'shopify',
                'description' => 'Shopify-Shop-Entwicklung und -Optimierung: Theme-Anpassung, App-Integration, Produktdaten-Migration und SEO-Optimierung für Ihren Online-Shop.',
                'category' => 'Development',
            ],
            [
                'name' => 'OpenEMM → Mautic Migration', 'slug' => 'openemm-mautic-migration',
                'description' => 'Migration Ihres E-Mail-Marketings von OpenEMM zu Mautic: Datenübernahme, Automation-Workflows, Template-Migration und Schulung. Open-Source, selbstgehostet, DSGVO-konform.',
                'category' => 'Consulting',
            ],
        ];

        foreach ($services as $s) {
            $existing = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'slug' => $s['slug']]);
            if ($existing) {
                $output->writeln("  ⏭  {$s['name']} (exists)");
                continue;
            }
            $product = new Product();
            $product->setName($s['name']);
            $product->setSlug($s['slug']);
            $product->setType(ProductType::Service);
            $product->setStatus(ProductStatus::Active);
            $product->setDescription($s['description']);
            $product->setCategory($s['category']);
            $product->setWorkspace($workspace);
            $this->em->persist($product);
            $output->writeln("  ✓  {$s['name']}");
        }
    }

    private function updateExtensionDescriptions(Workspace $workspace, OutputInterface $output): void
    {
        $descriptions = [
            'A21glossary' => 'Automatic conversion of all abbreviations and acronyms in the special tags for accessibility issues. Improves WCAG compliance by marking up <abbr> tags throughout your content.',
            'Address' => 'Address extension for TYPO3. Manage locations, persons and organizations with structured address data. Code based on the news extension architecture from Georg Ringer.',
            'Avif' => 'Creates AVIF copies for images in TYPO3, based on the webp extension. Delivers modern, bandwidth-efficient image formats automatically while keeping fallbacks.',
            'Backend Theme' => 'Backend theme optimization for TYPO3. Customize the look and feel of the TYPO3 administration interface for better usability.',
            'Benchmark' => 'TYPO3 performance benchmarking extension. Measure and analyze response times, database queries and cache efficiency.',
            'Cache Monitor' => 'Monitor and analyze TYPO3 caching behavior. Track cache hits, misses and lifetime to optimize your caching strategy.',
            'Ce Timeline' => 'Fork of simonkoehler/ce-timeline. Adds a new Timeline content element to TYPO3 with configurable events and responsive layout.',
            'Cleverreach' => 'CleverReach finishers and validators for EXT:form and Powermail. Sync newsletter subscriptions directly from TYPO3 forms to your CleverReach account. (55,928 installs)',
            'Core Upgrader' => 'Run upgrade wizards for multiple TYPO3 versions at once and clean up the system. Essential tool for TYPO3 major version upgrades. (28,467 installs)',
            'Fe Changepw' => 'Frontend user password change extension for TYPO3. Allows website visitors to securely change their own passwords.',
            'Fe Registration' => 'Frontend user registration for TYPO3. Customizable registration forms with email verification and admin approval workflows.',
            'Filecollection Gallery' => 'Simple image gallery that renders a FileCollection containing static or folder-based images. Lightweight, responsive, no JavaScript required.',
            'Flipbook' => 'Interactive page-flip effect for PDFs and images in TYPO3. Based on rflipbook, with responsive design and mobile-friendly touch controls.',
            'Font Downloader' => 'Automatically downloads external CSS fonts (Google Fonts, Font Awesome, etc.) and serves them locally for GDPR compliance. No external requests to Google. (6,925 installs)',
            'Form Extended' => 'Multi upload field, sender addresses in site config, new field types and more for TYPO3 forms. Extends the built-in form framework. (23,368 installs)',
            'Form Mailchimp' => 'MailChimp form finishers for subscribe and unsubscribe in TYPO3 forms. Connect any form directly to your MailChimp audience.',
            'Herobuilder' => 'Drag-and-drop hero section builder for TYPO3. Create stunning landing page heroes with background images, videos, overlays and call-to-action buttons.',
            'Infomaniak Vod' => 'Infomaniak VOD video streaming extension for TYPO3. Integrate professional video-on-demand hosting with your TYPO3 website.',
            'Inquiry' => 'Universal TYPO3 extension that allows visitors to create enquiries for quotes. Flexible form builder with email notifications and backend management.',
            'Meilisearch' => 'Meilisearch integration for TYPO3. Ultra-fast, typo-tolerant fulltext search with instant results. Modern alternative to Solr with simpler setup.',
            'Messenger' => 'Default Symfony Messenger commands for TYPO3. Async message processing with queue support for improved performance.',
            'Messenger Monitor' => 'Monitor and manage your Symfony Messenger queues within the TYPO3 backend. View failed messages, retry or delete them.',
            'Microsoft Graph Mailer' => 'Send TYPO3 system emails via Microsoft Graph API (Office 365/Exchange Online). Modern replacement for SMTP with OAuth authentication.',
            'Multisite Belogin' => 'Cross site/domain backend login for TYPO3. Allows backend users to work in the frontend across multiple domains without re-authentication. (38,210 installs)',
            'Newslayouts' => 'Extends EXT:news plugins and records with individual layout settings. Supports the Bootstrap CSS framework for flexible news display.',
            'Oauth Service' => 'OAuth 2.0 authentication service for TYPO3. Enable login via external providers (Google, Microsoft, GitHub, etc.).',
            'Pdflip' => 'PDF to flipbook converter for TYPO3. Turns uploaded PDFs into interactive, page-flipping publications.',
            'Php Html Parser' => 'An HTML DOM parser for PHP. Find and manipulate tags on an HTML page with selectors just like jQuery. Useful for content processing.',
            'Proxy' => 'TYPO3 proxy extension for showing content of another system inside TYPO3. Seamlessly integrate external applications.',
            'Rt Ckeditor Translations' => 'Additional translations for the CKEditor rich text editor in TYPO3. Extended language support beyond the default set.',
            'Samlauth' => 'SAML authentication for TYPO3. Single Sign-On integration with identity providers like Azure AD, Okta or Keycloak.',
            'Save And Close' => 'Adds save and close button to all content elements in TYPO3. Reduces clicks by 50% for editors. (181,912 installs)',
            'Site Region' => 'Additional region field for TYPO3 sites. Group and filter sites by geographic or logical regions in multi-site installations.',
            'Site Sets Extras' => 'Extended functionality for TYPO3 site sets. Additional configuration options and reusable components for site management.',
            'Tag' => 'A patch for TYPO3 to easily add tags just like categories to any element. Universal tagging across pages, content and records.',
            'Teaser' => 'Flexible teaser/content element for TYPO3. Create engaging preview cards with images, text and links for any page or record.',
            'Templatemaker' => 'TYPO3 extension for creating and managing page templates. Streamline your template workflow with reusable layouts.',
            'Testimonials' => 'Customer testimonial and review management for TYPO3. Display reviews with star ratings, author details and rich snippets for SEO.',
            'Videos' => 'Extends video file properties and provides a player for playlists, cue points and subtitles. Supports YouTube, Vimeo and self-hosted videos. (18,991 installs)',
            'Ws Bulletinboard' => 'Bulletin board / notice board extension for TYPO3. Create categorized announcements with optional expiry dates.',
            'Ws Components' => 'Reusable UI component library for TYPO3. Pre-built content blocks that can be composed into pages by editors.',
            'Ws Guestbook' => 'Guestbook extension for TYPO3. Allow visitors to leave entries with optional admin moderation and spam protection.',
            'Ws Less' => 'LESS compiler for TYPO3. Compiles LESS files to CSS at runtime with caching for optimal performance. (6,694 installs)',
            'Ws Scss' => 'Compiles SCSS to CSS at runtime with caching, TypoScript variables and EXT: import support. The most-installed SCSS compiler for TYPO3. (153,058 installs)',
            'Ws Slider' => 'Universal slider extension for TYPO3 supporting TinySlider, Swiper, FlexSlider and more. Responsive, touch-enabled, accessible. (22,593 installs)',
            'Zabbix Client' => 'TYPO3 Zabbix Client — integrate TYPO3 monitoring into your Zabbix infrastructure. Track uptime, performance and errors. (180,803 installs)',
        ];

        foreach ($descriptions as $name => $desc) {
            $product = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'name' => $name]);
            if (!$product) {
                $output->writeln("  ✗  {$name} (not found in DB)");
                continue;
            }
            $product->setDescription($desc);
            $output->writeln("  ✓  {$name}");
        }
    }

    private function updateProductHierarchy(Workspace $workspace, OutputInterface $output): void
    {
        // Worktide CMS description
        $cms = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'name' => 'Worktide CMS']);
        if ($cms) {
            $cms->setDescription('TYPO3 Content Management System — das Open-Source-Enterprise-CMS für skalierbare Websites, Portale und Intranets. Mit tausenden Extensions, Multi-Site- und Multi-Language-Support.');
            $output->writeln("  ✓  Worktide CMS (description)");
        }

        // Move T3Bootstrap from TYPO3 Extensions (service) to root level as standalone product
        $t3bootstrap = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'slug' => 't3bootstrap']);
        $extensionsSvc = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'slug' => 'typo3-extensions']);
        if ($t3bootstrap && $t3bootstrap->getParent() === $extensionsSvc) {
            $t3bootstrap->setParent(null);
            $t3bootstrap->setDescription('Kommerzielles Bootstrap 5 Template für TYPO3 v14. 29 Inhaltselemente, WCAG-barrierefrei, Mobile-first, Dark Mode, EXT:container + Fluid-Architektur. Perfekt auf Bootstrap abgestimmt mit No-TypoScript-Backendmodul.');
            $t3bootstrap->setCategory('Template');
            $output->writeln("  ✓  T3Bootstrap (standalone product)");
        }

        // Move Shyguy similarly
        $shyguy = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'slug' => 'shyguy']);
        if ($shyguy && $shyguy->getParent() === $extensionsSvc) {
            $shyguy->setParent(null);
            $output->writeln("  ✓  Shyguy (detached from TYPO3 Extensions service)");
        }

        // Websites → Service hierarchy: keep as is (already correct)
        // Make sure Corporate Website and Online Shop have descriptions
        $corpWeb = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'name' => 'Corporate Website']);
        if ($corpWeb) {
            $corpWeb->setDescription('Maßgeschneiderte Unternehmens-Website auf Basis von TYPO3. Professionelles Webdesign, responsives Layout und benutzerfreundliches CMS für Ihre digitale Präsenz.');
        }
        $shop = $this->em->getRepository(Product::class)->findOneBy(['workspace' => $workspace, 'name' => 'Online Shop']);
        if ($shop) {
            $shop->setDescription('TYPO3-basierter Online-Shop mit individueller Produktdarstellung, Warenkorb und Checkout. Integration von Zahlungsdienstleistern und Warenwirtschaft.');
        }
    }
}
