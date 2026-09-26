<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Media\Domain;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;

/**
 * The safety matrix behind soft_remove_migrated_node.
 *
 * A node may only be removed if it is of a whitelisted NodeType AND carries the matching
 * ancestorAggregateId somewhere up its parent chain.
 *
 * Extending to other content is a config-only change: add another entry under
 * Sandstorm.MCP.FeatureSet.Media.removableNodes in your site package's Settings.
 */
#[Flow\Scope('singleton')]
class RemovableNodeWhitelist
{
    /**
     * @var array<int,array{nodeType:string,ancestorAggregateId:string}>
     */
    #[Flow\InjectConfiguration(path: 'removableNodes', package: 'Sandstorm.MCP.FeatureSet.Media')]
    public array $removableNodes = [];

    /**
     * Pure whitelist decision: is a node with the given type (probed via $isOfType, which
     * also matches supertypes) and the given ancestor aggregate ids removable?
     * A node is removable iff it matches at least one configured entry on BOTH NodeType
     * AND ancestor. Takes a callable rather than a NodeType so the safety matrix can be
     * unit tested without a Content Repository.
     *
     * @param callable(string):bool $isOfType
     * @param array<int,string> $ancestorIds
     */
    public function matches(callable $isOfType, array $ancestorIds): bool
    {
        foreach ($this->removableNodes as $entry) {
            $allowedType = $entry['nodeType'] ?? null;
            $allowedAncestor = $entry['ancestorAggregateId'] ?? null;
            if (!\is_string($allowedType) || !\is_string($allowedAncestor)) {
                continue;
            }
            if ($isOfType($allowedType) && \in_array($allowedAncestor, $ancestorIds, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Walk the parent chain and return all ancestor aggregate ids (as strings).
     *
     * @return array<int,string>
     */
    public function collectAncestorIds(ContentSubgraphInterface $subgraph, NodeAggregateId $startId): array
    {
        $ids = [];
        $currentId = $startId;
        // Bounded walk; news/project trees are shallow. Guard against cycles.
        for ($i = 0; $i < 50; $i++) {
            $parent = $subgraph->findParentNode($currentId);
            if ($parent === null) {
                break;
            }
            $ids[] = $parent->aggregateId->value;
            $currentId = $parent->aggregateId;
        }
        return $ids;
    }
}
