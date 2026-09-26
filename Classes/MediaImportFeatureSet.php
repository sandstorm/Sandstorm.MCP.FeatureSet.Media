<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Media;

use Neos\Flow\Annotations as Flow;
use SJS\Flow\MCP\FeatureSet\AbstractFeatureSet;
use Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet\ImportImageTool;
use Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet\ListImagesTool;
use Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet\SetAssetPropertyTool;
use Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet\SoftRemoveMigratedNodeTool;

#[Flow\Scope("singleton")]
class MediaImportFeatureSet extends AbstractFeatureSet
{
    public function initialize(): void
    {
        $this->addTool(ListImagesTool::class);
        $this->addTool(ImportImageTool::class);
        $this->addTool(SetAssetPropertyTool::class);
        $this->addTool(SoftRemoveMigratedNodeTool::class);
    }
}
