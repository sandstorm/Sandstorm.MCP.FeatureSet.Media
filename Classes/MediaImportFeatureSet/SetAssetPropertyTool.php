<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use Neos\Media\Domain\Model\Adjustment\CropImageAdjustment;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Model\ImageVariant;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\IntegerSchema;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;

class SetAssetPropertyTool extends Tool implements ToolConstructor
{
    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected AssetRepository $assetRepository;

    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'set_asset_property',
            description: 'Sets an asset/image property on an existing content node using an asset UUID. Use this instead of content_update_content for image/asset properties.',
            inputSchema: new ObjectSchema(properties: [
                'node_address' => (new ObjectSchema(
                    description: 'The node_address of the content node to update',
                    properties: [
                        'contentRepositoryId' => (new StringSchema())->required(),
                        'workspaceName' => (new StringSchema())->required(),
                        'dimensionSpacePoint' => (new ObjectSchema())->required(),
                        'aggregateId' => (new StringSchema())->required(),
                    ]
                ))->required(),
                'property_name' => (new StringSchema(description: 'The property name (e.g. "image")'))->required(),
                'asset_id' => (new StringSchema(description: 'UUID of the asset in the Neos media library'))->required(),
                'asset_type' => new StringSchema(description: 'FQCN of the asset type. Defaults to Neos\Media\Domain\Model\Image'),
                'crop' => new ObjectSchema(
                    description: 'Optional crop rectangle (pixels on the original image). If given, an ImageVariant with a CropImageAdjustment is created and used as the property value. Only valid for Image assets.',
                    properties: [
                        'x' => (new IntegerSchema(description: 'Left offset in pixels', minimum: 0))->required(),
                        'y' => (new IntegerSchema(description: 'Top offset in pixels', minimum: 0))->required(),
                        'width' => (new IntegerSchema(description: 'Crop width in pixels', minimum: 1))->required(),
                        'height' => (new IntegerSchema(description: 'Crop height in pixels', minimum: 1))->required(),
                    ]
                ),
            ]),
            annotations: new Annotations(title: 'Set Asset Property'),
            featureSet: $featureSet
        );
    }

    public function run(ServerContext $serverContext, array $input): Content
    {
        $nodeAddress = NodeAddress::fromArray($input['node_address']);

        if ($nodeAddress->workspaceName->value === 'live') {
            throw new \InvalidArgumentException('Updating nodes on Live workspace is disabled.');
        }

        $propertyName = $input['property_name'];
        $assetId = $input['asset_id'];
        $assetType = $input['asset_type'] ?? \Neos\Media\Domain\Model\Image::class;

        $asset = $this->persistenceManager->getObjectByIdentifier($assetId, $assetType);
        if ($asset === null) {
            throw new \InvalidArgumentException("Asset not found with id: $assetId (type: $assetType)");
        }

        $propertyValue = $asset;
        $resultMessage = "Property '$propertyName' updated with asset $assetId";

        if (isset($input['crop'])) {
            if (!$asset instanceof Image) {
                throw new \InvalidArgumentException('Cropping is only supported for Image assets (asset_type Neos\\Media\\Domain\\Model\\Image).');
            }

            $crop = $input['crop'];
            $cropAdjustment = new CropImageAdjustment();
            $cropAdjustment->setX((int)$crop['x']);
            $cropAdjustment->setY((int)$crop['y']);
            $cropAdjustment->setWidth((int)$crop['width']);
            $cropAdjustment->setHeight((int)$crop['height']);

            $imageVariant = new ImageVariant($asset);
            $imageVariant->addAdjustment($cropAdjustment);

            $this->assetRepository->add($imageVariant);
            $this->persistenceManager->persistAll();

            $variantId = $this->persistenceManager->getIdentifierByObject($imageVariant);
            $propertyValue = $imageVariant;
            $resultMessage = "Property '$propertyName' updated with cropped ImageVariant $variantId (from image $assetId, crop {$crop['x']},{$crop['y']} {$crop['width']}x{$crop['height']})";
        }

        $httpRequest = $serverContext->request->getHttpRequest();
        $contentRepositoryId = SiteDetectionResult::fromRequest($httpRequest)->contentRepositoryId;
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $command = SetNodeProperties::create(
            workspaceName: $nodeAddress->workspaceName,
            nodeAggregateId: $nodeAddress->aggregateId,
            originDimensionSpacePoint: OriginDimensionSpacePoint::fromDimensionSpacePoint($nodeAddress->dimensionSpacePoint),
            propertyValues: PropertyValuesToWrite::fromArray([$propertyName => $propertyValue])
        );

        $contentRepository->handle($command);

        return Content::text($resultMessage);
    }
}
