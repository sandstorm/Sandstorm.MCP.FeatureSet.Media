<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet;

use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\Domain\MCP\ToolConstructor;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;

class ListImagesTool extends Tool implements ToolConstructor
{
    #[Flow\InjectConfiguration(path: 'resourceFolder', package: 'Sandstorm.MCP.FeatureSet.Media')]
    public string $resourceFolder = '/app/Data/Persistent/McpResources/';

    public function __construct(FeatureSetInterface $featureSet)
    {
        parent::__construct(
            name: 'list_images',
            description: 'Lists all importable asset files (images, PDFs, any type) under the configured resource folder (setting Sandstorm.MCP.FeatureSet.Media.resourceFolder), recursing into subdirectories. A Neos resources dump can be placed directly into that folder (e.g. its Resources/0/0/... tree). Returns relative paths like "photo.jpg" or "Resources/0/0/2/9/00299..." for use with import_image. Pass "path" (a relative path as returned by this tool) to instead check whether that single file exists.',
            inputSchema: new ObjectSchema(properties: [
                'path' => new StringSchema(description: 'Optional. Relative path under the resource folder (e.g. "photo.jpg"). If given, returns whether the file exists instead of listing all files.'),
            ]),
            annotations: new Annotations(
                title: 'List Importable Assets',
                readOnlyHint: true
            ),
            featureSet: $featureSet
        );
    }

    public function run(ServerContext $serverContext, array $input): Content
    {
        if (isset($input['path']) && $input['path'] !== '') {
            $relativePath = ltrim($input['path'], '/');

            if (str_contains($relativePath, '..')) {
                return Content::text('does not exist');
            }

            $exists = is_file(rtrim($this->resourceFolder, '/') . '/' . $relativePath);

            return Content::text($exists ? 'exists' : 'does not exist');
        }

        $paths = [];

        $root = rtrim($this->resourceFolder, '/') . '/';
        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $paths[] = substr($file->getPathname(), strlen($root));
            }
        }

        return Content::text(json_encode($paths, JSON_PRETTY_PRINT));
    }
}
