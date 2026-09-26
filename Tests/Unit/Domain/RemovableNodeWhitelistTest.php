<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Media\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sandstorm\MCP\FeatureSet\Media\Domain\RemovableNodeWhitelist;

/**
 * Covers the safety matrix shared by remove_migrated_node and soft_remove_migrated_node:
 * a node is removable ONLY when it matches a configured entry on BOTH NodeType and ancestor.
 */
class RemovableNodeWhitelistTest extends UnitTestCase
{
    private const NEWS_POST = 'Vendor.Site:Document.News.Post';
    private const NEWS_PARENT = '11111111-2222-4333-8444-555555555555';

    private function whitelist(array $removableNodes): RemovableNodeWhitelist
    {
        $whitelist = new RemovableNodeWhitelist();
        $whitelist->removableNodes = $removableNodes;
        return $whitelist;
    }

    /**
     * @param array<int,string> $isOfTypeMatches NodeType strings the node is "of type" of
     */
    private function isOfType(array $isOfTypeMatches): callable
    {
        return static fn (string $type): bool => \in_array($type, $isOfTypeMatches, true);
    }

    private function newsWhitelist(): array
    {
        return [['nodeType' => self::NEWS_POST, 'ancestorAggregateId' => self::NEWS_PARENT]];
    }

    /** @test */
    public function allowsWhitelistedTypeUnderWhitelistedAncestor(): void
    {
        $whitelist = $this->whitelist($this->newsWhitelist());
        self::assertTrue(
            $whitelist->matches($this->isOfType([self::NEWS_POST]), [self::NEWS_PARENT, 'some-other-ancestor'])
        );
    }

    /** @test */
    public function rejectsWhitelistedTypeUnderWrongAncestor(): void
    {
        $whitelist = $this->whitelist($this->newsWhitelist());
        self::assertFalse(
            $whitelist->matches($this->isOfType([self::NEWS_POST]), ['unrelated-ancestor'])
        );
    }

    /** @test */
    public function rejectsWrongTypeUnderWhitelistedAncestor(): void
    {
        $whitelist = $this->whitelist($this->newsWhitelist());
        self::assertFalse(
            $whitelist->matches($this->isOfType(['Vendor.Site:Document.News.Folder']), [self::NEWS_PARENT])
        );
    }

    /** @test */
    public function rejectsEverythingWhenWhitelistEmpty(): void
    {
        $whitelist = $this->whitelist([]);
        self::assertFalse(
            $whitelist->matches($this->isOfType([self::NEWS_POST]), [self::NEWS_PARENT])
        );
    }

    /** @test */
    public function ignoresMalformedWhitelistEntries(): void
    {
        $whitelist = $this->whitelist([
            ['nodeType' => self::NEWS_POST],                       // missing ancestor
            ['ancestorAggregateId' => self::NEWS_PARENT],          // missing nodeType
        ]);
        self::assertFalse(
            $whitelist->matches($this->isOfType([self::NEWS_POST]), [self::NEWS_PARENT])
        );
    }

    /** @test */
    public function matchesViaSupertypeProbe(): void
    {
        // isOfType also returns true for supertypes; a Projects entry should match
        // a concrete project node that isOfType the configured (super)type.
        $whitelist = $this->whitelist([
            ['nodeType' => 'Vendor.Site:Document.Project', 'ancestorAggregateId' => 'projects-parent'],
        ]);
        self::assertTrue(
            $whitelist->matches(
                $this->isOfType(['Vendor.Site:Document.Project']),
                ['projects-parent']
            )
        );
    }

    /** @test */
    public function allowsHeadlineContentUnderNewsRootViaContentSupertype(): void
    {
        // The h2 dedupe sweep relies on this: Content.Headline is a Neos.Neos:Content
        // subtype, and the deployed whitelist covers Neos.Neos:Content under the news root.
        $whitelist = $this->whitelist([
            ['nodeType' => 'Neos.Neos:Content', 'ancestorAggregateId' => self::NEWS_PARENT],
        ]);
        self::assertTrue(
            $whitelist->matches(
                $this->isOfType(['Vendor.Site:Content.Headline', 'Neos.Neos:Content']),
                ['main-collection-id', 'post-id', 'month-folder-id', self::NEWS_PARENT]
            )
        );
    }
}
