<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet;

use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Sandstorm\MCP\FeatureSet\Media\Domain\RemovableNodeWhitelist;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;

/**
 * Soft removes a node aggregate by tagging its subtree with Neos' `removed` tag, subject to
 * the configured (nodeType, ancestorAggregateId) whitelist {@see RemovableNodeWhitelist}.
 *
 * Why no hard removal: a RemoveNodeAggregate on a non-live workspace
 * destroys the hierarchy information that the workspace module needs to group changes by
 * document: WorkspaceController::computeSiteChanges() resolves every change through
 * findNodeById() and silently drops the ones it cannot resolve, so the overview counts the
 * removals while the Review page reports "no unpublished changes". Soft removed nodes stay
 * resolvable, render correctly in the review list, land in that workspace's trash bin and
 * are only hard removed by the SoftRemovalGarbageCollector once published to live.
 *
 * Hard guards (cannot be bypassed by the caller):
 *  - never operates on the `live` workspace
 *  - the target node must match the configured removableNodes whitelist; otherwise it refuses.
 */
class SoftRemoveMigratedNodeTool extends Tool implements ToolConstructor
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    public RemovableNodeWhitelist $whitelist;

    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'soft_remove_migrated_node',
            description: 'Soft removes a migrated node aggregate on a non-live workspace by tagging it as removed, '
                . 'the same way the Neos backend deletes nodes. Strictly limited to nodes matching a configured '
                . '(nodeType, ancestor) whitelist — refuses anything else. '
                . 'The change stays reviewable in the workspace module, appears in the trash bin and can be restored '
                . 'or discarded until it is published.',
            inputSchema: new ObjectSchema(properties: [
                'node_address' => (new ObjectSchema(
                    description: 'The node_address of the node aggregate to soft remove (as returned by other tools)',
                    properties: [
                        'contentRepositoryId' => (new StringSchema())->required(),
                        'workspaceName' => (new StringSchema())->required(),
                        'dimensionSpacePoint' => (new ObjectSchema())->required(),
                        'aggregateId' => (new StringSchema())->required(),
                    ]
                ))->required(),
            ]),
            annotations: new Annotations(
                title: 'Soft Remove Migrated Node',
                destructiveHint: true
            ),
            featureSet: $featureSet
        );
    }

    /**
     * @param array<string,mixed> $input
     */
    public function run(ServerContext $serverContext, array $input): Content
    {
        $nodeAddress = NodeAddress::fromArray($input['node_address']);

        // Guard 1: never touch live.
        if ($nodeAddress->workspaceName->value === 'live') {
            throw new \InvalidArgumentException('Removing nodes on the Live workspace is disabled.');
        }

        $httpRequest = $serverContext->request->getHttpRequest();
        $contentRepositoryId = SiteDetectionResult::fromRequest($httpRequest)->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $contentGraph = $contentRepository->getContentGraph($nodeAddress->workspaceName);

        // createEmpty() rather than withoutRestrictions(): the latter still excludes the
        // `removed` tag, so an already soft removed node would look like it did not exist.
        $subgraph = $contentGraph->getSubgraph(
            $nodeAddress->dimensionSpacePoint,
            VisibilityConstraints::createEmpty()
        );

        $node = $subgraph->findNodeById($nodeAddress->aggregateId);
        if ($node === null) {
            throw new \InvalidArgumentException("Could not find node {$nodeAddress->aggregateId->value} in workspace {$nodeAddress->workspaceName->value}.");
        }

        // Guard 2: NodeType + ancestor must match a configured whitelist entry.
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            throw new \InvalidArgumentException("Node {$nodeAddress->aggregateId->value} has unknown NodeType {$node->nodeTypeName->value}.");
        }

        $ancestorIds = $this->whitelist->collectAncestorIds($subgraph, $nodeAddress->aggregateId);

        if (!$this->whitelist->matches(fn (string $type): bool => $nodeType->isOfType($type), $ancestorIds)) {
            throw new \InvalidArgumentException(
                "Refusing to soft remove node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}): "
                . 'it does not match any configured removableNodes (nodeType + ancestor) whitelist entry.'
            );
        }

        // TagSubtree is not idempotent: it throws SubtreeIsAlreadyTagged (1731167142) when the
        // aggregate already carries an explicit `removed` tag in this dimension space point.
        // Report that instead, so re-runs of a bulk job are safe.
        $nodeAggregate = $contentGraph->findNodeAggregateById($nodeAddress->aggregateId);
        if ($nodeAggregate === null) {
            throw new \InvalidArgumentException("Could not find node aggregate {$nodeAddress->aggregateId->value} in workspace {$nodeAddress->workspaceName->value}.");
        }
        $explicitlyRemoved = $nodeAggregate->getCoveredDimensionsTaggedBy(NeosSubtreeTag::removed(), withoutInherited: true);
        if ($explicitlyRemoved->contains($nodeAddress->dimensionSpacePoint)) {
            return Content::text("Node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) is already soft removed in workspace {$nodeAddress->workspaceName->value}; nothing to do.");
        }

        $contentRepository->handle(TagSubtree::create(
            workspaceName: $nodeAddress->workspaceName,
            nodeAggregateId: $nodeAddress->aggregateId,
            coveredDimensionSpacePoint: $nodeAddress->dimensionSpacePoint,
            nodeVariantSelectionStrategy: NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
            tag: NeosSubtreeTag::removed()
        ));

        return Content::text("Soft removed node {$nodeAddress->aggregateId->value} (type {$node->nodeTypeName->value}) in workspace {$nodeAddress->workspaceName->value}.");
    }
}
