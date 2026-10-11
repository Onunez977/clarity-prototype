<?php

namespace Tests\Feature\Otto;

use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Surface;
use App\Support\Otto\MessageRenderer;
use Tests\TestCase;

class OttoKnowledgeBaseTest extends TestCase
{
    public function test_help_article_says_otto_can_search_zones(): void
    {
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));
        $article = $kb->all()->first(fn ($candidate) => $candidate->slug === 'asking-otto-for-help');

        $this->assertNotNull($article);
        $this->assertStringContainsString('patrol zones', $article->summary);
        $this->assertLessThanOrEqual(160, strlen($article->summary));
        $this->assertStringContainsString('Scout patrol zones', $article->body);
        $this->assertStringNotContainsString('cannot yet search zones', $article->body);
    }

    public function test_knowledge_base_returns_starter_articles(): void
    {
        $user = $this->createUser();
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));

        $all = $kb->all();

        $this->assertGreaterThanOrEqual(7, $all->count());
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'what-is-a-scan'));
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'kit-user-vs-camera'));
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'who-is-insight-lpr'));
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'searching-hits'));
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'what-is-a-hotlist'));
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'plates-states-and-wildcards'));
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'orders-and-case-numbers'));
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'repossession-adjusters'));
        $this->assertTrue($all->contains(fn ($a) => $a->slug === 'scans-on-a-case'));
        $this->assertFalse($all->contains(fn ($a) => $a->slug === 'readme'));
    }

    public function test_knowledge_search_identifies_insight_lpr_as_the_owning_company(): void
    {
        $user = $this->createUser();
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));

        $article = $kb->all()->first(fn ($a) => $a->slug === 'who-is-insight-lpr');

        $this->assertNotNull($article);
        $this->assertSame('Who is Insight LPR?', $article->title);
        $this->assertStringContainsString(
            'Insight LPR is the company that owns Platform and Clarity',
            $article->summary,
        );
        $this->assertLessThanOrEqual(160, strlen($article->summary));
        $this->assertStringContainsString(
            'Insight LPR is the company that owns Platform and Clarity',
            $article->body,
        );
        $this->assertStringContainsString('https://insightlpr.com', $article->body);

        foreach (['who is Insight LPR', 'who owns Platform and Clarity'] as $query) {
            $results = $kb->search($query, $user, Surface::Chat, 3);

            $this->assertNotEmpty($results, "No article matched {$query}");
            $this->assertSame('who-is-insight-lpr', $results->first()->slug, "Wrong top article for {$query}");
        }
    }

    public function test_knowledge_search_finds_scan_article(): void
    {
        $user = $this->createUser();
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));

        $results = $kb->search('what is a scan plate kit', $user, Surface::Chat, 3);

        $this->assertNotEmpty($results);
        $this->assertStringContainsStringIgnoringCase('scan', $results->first()->title);
    }

    public function test_knowledge_distinguishes_kit_user_from_camera(): void
    {
        $user = $this->createUser();
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));

        $scan = $kb->all()->first(fn ($article) => $article->slug === 'what-is-a-scan');
        $this->assertNotNull($scan);
        $this->assertStringNotContainsStringIgnoringCase('kits (cameras)', $scan->body);
        $this->assertStringNotContainsStringIgnoringCase('kit/camera', $scan->summary);

        foreach ([
            'what is a kit',
            'is a kit the camera',
            'kit vs scan car',
            'kid id',
        ] as $query) {
            $results = $kb->search($query, $user, Surface::Chat, 3);

            $this->assertSame(
                'kit-user-vs-camera',
                $results->first()?->slug,
                "Expected kit-user-vs-camera first for [{$query}]",
            );
        }

        $kit = $kb->all()->first(fn ($article) => $article->slug === 'kit-user-vs-camera');
        $this->assertNotNull($kit);
        $this->assertStringContainsStringIgnoringCase('person logged into the vehicle', $kit->body);
        $this->assertStringContainsStringIgnoringCase('kid ID', $kit->body);
        $this->assertStringContainsStringIgnoringCase('scan car', $kit->body);
        $this->assertStringContainsStringIgnoringCase('four cameras', $kit->body);
    }

    public function test_knowledge_articles_avoid_internal_infrastructure_terms(): void
    {
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));
        // "rdn" is customer language for Recovery Database Network. Block plumbing, not the name.
        $forbidden = ['clickhouse', 'postgres', 'postgresql', 'cdc', 'impersonat', 'debezium', 'peerdb', 'soap', 'api key', 'api_key', 'sqs', 'rdn_api', 'rdn api'];

        foreach ($kb->all() as $article) {
            $haystack = strtolower($article->title.' '.$article->summary.' '.$article->body.' '.implode(' ', $article->keywords));
            foreach ($forbidden as $term) {
                $this->assertStringNotContainsString($term, $haystack, "Article {$article->slug} leaked internal term {$term}");
            }

            $this->assertStringNotContainsString('arion', $haystack, "Article {$article->slug} invented an Arion entity");
        }
    }

    public function test_order_article_maps_case_language_to_external_order_id(): void
    {
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));
        $article = $kb->all()->first(fn ($candidate) => $candidate->slug === 'orders-and-case-numbers');

        $this->assertNotNull($article);

        $haystack = strtolower($article->title.' '.$article->summary.' '.$article->body);
        $this->assertStringContainsString('rdn', $haystack);
        $this->assertStringContainsString('recovery database network', $haystack);
        $this->assertStringContainsString('recoverydatabase.net', $haystack);
        $this->assertStringContainsString('case number', $haystack);
        $this->assertStringContainsString('external order id', $haystack);
        $this->assertLessThanOrEqual(160, strlen($article->summary));
    }

    public function test_adjuster_article_separates_truck_staff_from_otto_users(): void
    {
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));
        $article = $kb->all()->first(fn ($candidate) => $candidate->slug === 'repossession-adjusters');

        $this->assertNotNull($article);

        $haystack = strtolower($article->title.' '.$article->summary.' '.$article->body);
        $this->assertStringContainsString('picking up metal', $haystack);
        $this->assertStringContainsString('not logged', $haystack);
        $this->assertStringContainsString('owners, managers, and office staff', $haystack);
        $this->assertLessThanOrEqual(160, strlen($article->summary));
    }

    public function test_scan_on_case_article_describes_pre_assignment_intent(): void
    {
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));
        $article = $kb->all()->first(fn ($candidate) => $candidate->slug === 'scans-on-a-case');

        $this->assertNotNull($article);

        $haystack = strtolower($article->title.' '.$article->summary.' '.$article->body);
        $this->assertStringContainsString('case number', $haystack);
        $this->assertStringContainsString('plate', $haystack);
        $this->assertStringContainsString('adjuster', $haystack);
        $this->assertStringContainsString('external order id', $haystack);
        $this->assertLessThanOrEqual(160, strlen($article->summary));
    }

    public function test_knowledge_search_finds_order_and_scan_on_case_articles(): void
    {
        $user = $this->createUser();
        $kb = new KnowledgeBase(resource_path('otto-knowledge'));

        $orders = $kb->search('what is an order RDN case number', $user, Surface::Chat, 3);
        $this->assertTrue($orders->contains(fn ($article) => $article->slug === 'orders-and-case-numbers'));

        $scans = $kb->search('scans on this case number before assigning an adjuster', $user, Surface::Chat, 3);
        $this->assertTrue($scans->contains(fn ($article) => $article->slug === 'scans-on-a-case'));
    }

    public function test_message_renderer_replaces_widget_markers(): void
    {
        $html = MessageRenderer::render("Summary.\n\n<!--otto_table:scan:abc123xyz-->\n");

        $this->assertStringContainsString('data-widget="scan"', $html);
        $this->assertStringContainsString('abc123xyz', $html);
        $this->assertStringNotContainsString('<!--otto_table', $html);
    }

    public function test_message_renderer_replaces_hit_widget_markers(): void
    {
        $html = MessageRenderer::render("Summary.\n\n<!--otto_table:hit:hitsearch01-->\n");

        $this->assertStringContainsString('data-widget="hit"', $html);
        $this->assertStringContainsString('hitsearch01', $html);
        $this->assertStringNotContainsString('<!--otto_table', $html);
    }

    public function test_message_renderer_replaces_scout_driver_widget_markers(): void
    {
        $html = MessageRenderer::render("Summary.\n\n<!--otto_table:scout_driver:scoutsearch01-->\n");

        $this->assertStringContainsString('data-otto-widget="driver-map"', $html);
        $this->assertStringContainsString('data-widget="scout_driver"', $html);
        $this->assertStringContainsString('scoutsearch01', $html);
        $this->assertStringNotContainsString('ottoDriverMap', $html);
        $this->assertStringNotContainsString('<!--otto_table', $html);
    }

    public function test_message_renderer_hides_calling_scan_search_stand_in(): void
    {
        $html = MessageRenderer::render(
            "Let me pull up today's scans for you right now!\n\n[Calling ScanSearch with preset: \"today\"]"
        );

        $this->assertStringNotContainsString('Calling ScanSearch', $html);
        $this->assertStringNotContainsString('preset', $html);
        $this->assertStringNotContainsString('Let me pull up', $html);
    }
}
