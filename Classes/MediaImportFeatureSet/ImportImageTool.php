<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;

class ImportImageTool extends Tool implements ToolConstructor
{
    #[Flow\InjectConfiguration(path: 'resourceFolder', package: 'Sandstorm.MCP.FeatureSet.Media')]
    protected string $resourceFolder;

    #[Flow\Inject]
    protected ResourceManager $resourceManager;

    #[Flow\Inject]
    protected AssetRepository $assetRepository;

    #[Flow\Inject]
    protected PersistenceManagerInterface $persistenceManager;

    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'import_image',
            description: 'Imports an image file from the configured resource folder (setting Sandstorm.MCP.FeatureSet.Media.resourceFolder) into the Neos media library. Returns the asset UUID.',
            inputSchema: new ObjectSchema(properties: [
                'path' => (new StringSchema(description: 'Relative path of the image to import as listed by list_images (e.g. "photo.jpg").'))->required(),
                'filename' => new StringSchema(description: 'Optional original filename including extension (e.g. "Portrait.jpg"). Sets the resource filename and derived media type. Required when the source file is sha1-named without an extension, otherwise the media library shows no preview. Defaults to the source basename.'),
            ]),
            annotations: new Annotations(title: 'Import Image from Folder'),
            featureSet: $featureSet
        );
    }

    public function run(ServerContext $serverContext, array $input): Content
    {
        $relativePath = ltrim($input['path'], '/');

        if (str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException("Invalid path: $relativePath");
        }

        $path = rtrim($this->resourceFolder, '/') . '/' . $relativePath;

        if (!is_file($path)) {
            throw new \InvalidArgumentException("File not found: $relativePath");
        }

        $resource = $this->resourceManager->importResource($path);

        $filename = !empty($input['filename']) ? $input['filename'] : pathinfo($path, PATHINFO_BASENAME);
        $resource->setFilename($filename);

        $image = new Image($resource);
        $image->setTitle(pathinfo($filename, PATHINFO_FILENAME));

        $this->assetRepository->add($image);
        $this->persistenceManager->persistAll();

        $assetId = $this->persistenceManager->getIdentifierByObject($image);

        return Content::text("Imported image with assetId: $assetId");
    }
}
