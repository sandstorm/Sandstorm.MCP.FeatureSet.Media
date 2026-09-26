<?php

declare(strict_types=1);

namespace Sandstorm\MCP\FeatureSet\Media\Tests\Unit\MediaImportFeatureSet;

use Neos\Flow\Tests\UnitTestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Sandstorm\MCP\FeatureSet\Media\MediaImportFeatureSet\ListImagesTool;
use SJS\Flow\MCP\Domain\Connection\Connection;
use SJS\Flow\MCP\Domain\Connection\ServerContext;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\FeatureSet\FeatureSetInterface;

class ListImagesToolTest extends UnitTestCase
{
    private vfsStreamDirectory $vfs;
    private ListImagesTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vfs = vfsStream::setup('root', null, [
            'McpResources' => [
                'photo.jpg'            => '',
                'document.pdf'         => '',
                'Resources.index.json' => '{}',
                'Resources' => [
                    '0' => [
                        '0' => [
                            '2' => [
                                '9' => [
                                    '00299641d68953ad5a30f7a06dabcdfedc54c0ff' => '',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->tool = new ListImagesTool($this->createMock(FeatureSetInterface::class));
        $this->tool->resourceFolder = vfsStream::url('root/McpResources/');
    }

    private function serverContext(): ServerContext
    {
        return new ServerContext(
            $this->createMock(Connection::class),
            $this->createMock(\Neos\Flow\Mvc\ActionRequest::class)
        );
    }

    private function contentText(Content $content): string
    {
        $data = json_decode(json_encode($content), true);
        return $data['content'][0]['text'] ?? '';
    }

    private function runTool(): array
    {
        $result = $this->tool->run($this->serverContext(), []);
        return json_decode($this->contentText($result), true);
    }

    private function checkPath(string $path): string
    {
        $result = $this->tool->run($this->serverContext(), ['path' => $path]);
        return $this->contentText($result);
    }

    /** @test */
    public function listsFilesDirectlyInRoot(): void
    {
        $paths = $this->runTool();
        self::assertContains('photo.jpg', $paths);
        self::assertContains('document.pdf', $paths);
    }

    /** @test */
    public function recursesIntoSubdirectoriesAndKeepsRelativePath(): void
    {
        $paths = $this->runTool();
        self::assertContains('Resources/0/0/2/9/00299641d68953ad5a30f7a06dabcdfedc54c0ff', $paths);
    }

    /** @test */
    public function includesAllFileTypesRegardlessOfExtension(): void
    {
        $paths = $this->runTool();
        self::assertContains('Resources.index.json', $paths);
    }

    /** @test */
    public function returnsEmptyArrayWhenNoFilesPresent(): void
    {
        vfsStream::setup('empty', null, ['McpResources' => []]);
        $this->tool->resourceFolder = vfsStream::url('empty/McpResources/');
        $paths = $this->runTool();
        self::assertSame([], $paths);
    }

    /** @test */
    public function skipsMissingSubFolders(): void
    {
        $this->tool->resourceFolder = vfsStream::url('root/NoSuchFolder/');
        $paths = $this->runTool();
        self::assertSame([], $paths);
    }

    /** @test */
    public function pathParamReturnsExistsForExistingFile(): void
    {
        self::assertSame('exists', $this->checkPath('document.pdf'));
        self::assertSame('exists', $this->checkPath('Resources/0/0/2/9/00299641d68953ad5a30f7a06dabcdfedc54c0ff'));
    }

    /** @test */
    public function pathParamReturnsDoesNotExistForMissingFile(): void
    {
        self::assertSame('does not exist', $this->checkPath('nope.jpg'));
    }

    /** @test */
    public function pathParamRejectsTraversal(): void
    {
        self::assertSame('does not exist', $this->checkPath('../../etc/passwd'));
    }
}
