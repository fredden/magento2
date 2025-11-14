<?php declare(strict_types=1);
/**
 * Copyright 2015 Adobe
 * All Rights Reserved.
 */

namespace Magento\Developer\Test\Unit\Model\View\Page\Config\ClientSideLessCompilation;

use Magento\Developer\Model\View\Page\Config\ClientSideLessCompilation\Renderer;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\File;
use Magento\Framework\View\Asset\GroupedCollection;
use Magento\Framework\View\Asset\MergeService;
use Magento\Framework\View\Asset\PropertyGroup;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Config\Metadata\MsApplicationTileImage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RendererTest extends TestCase
{
    /** @var MockObject|Renderer */
    private Renderer $model;

    /** @var  MockObject|GroupedCollection */
    private GroupedCollection $assetCollectionMock;

    /** @var  MockObject|Repository */
    private Repository $assetRepo;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var MergeService
     */
    private MergeService $assetMergeServiceMock;

    /**
     * @var UrlInterface|MockObject
     */
    private UrlInterface $urlBuilderMock;

    /**
     * @var Escaper|MockObject
     */
    private Escaper $escaperMock;

    /**
     * @var StringUtils|MockObject
     */
    private StringUtils $stringMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $loggerMock;

    /**
     * @var MsApplicationTileImage|MockObject
     */
    private MsApplicationTileImage $msApplicationTileImageMock;

    protected function setUp(): void
    {
        $pageConfigMock = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->assetCollectionMock = $this->getMockBuilder(GroupedCollection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $pageConfigMock->expects($this->once())
            ->method('getAssetCollection')
            ->willReturn($this->assetCollectionMock);
        $this->assetRepo = $this->getMockBuilder(Repository::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->assetMergeServiceMock = $this->getMockBuilder(MergeService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->urlBuilderMock = $this->getMockForAbstractClass(UrlInterface::class);
        $this->escaperMock = $this->getMockBuilder(Escaper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->escaperMock->expects($this->any())
            ->method('escapeHtml')
            ->willReturnArgument(0);
        $this->stringMock = $this->getMockBuilder(StringUtils::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->loggerMock = $this->getMockBuilder(LoggerInterface::class)
            ->getMock();
        $this->msApplicationTileImageMock = $this->getMockBuilder(MsApplicationTileImage::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new Renderer(
            $pageConfigMock,
            $this->assetMergeServiceMock,
            $this->urlBuilderMock,
            $this->escaperMock,
            $this->stringMock,
            $this->loggerMock,
            $this->assetRepo,
            $this->msApplicationTileImageMock,
            $this->scopeConfig
        );

        parent::setUp();
    }

    /**
     * Test calls renderAssets as a way to execute renderLessJsScripts code
     */
    public function testRenderLessJsScripts()
    {
        $propertyGroup = $this->getMockBuilder(PropertyGroup::class)
            ->disableOriginalConstructor()
            ->getMock();
        $propertyGroup->expects($this->once())->method('getAll')->willReturn([]);
        $propertyGroups = [
            $propertyGroup
        ];
        $this->assetCollectionMock->expects($this->once())->method('getGroups')->willReturn($propertyGroups);

        // Stubs for renderLessJsScripts code
        $lessConfigFile = $this->getMockBuilder(File::class)
            ->disableOriginalConstructor()
            ->getMock();
        $lessMinFile = $this->getMockBuilder(File::class)
            ->disableOriginalConstructor()
            ->getMock();
        $lessConfigUrl = 'less/config/url.css';
        $lessMinUrl = 'less/min/url.css';
        $lessConfigFile->expects($this->once())->method('getUrl')->willReturn($lessConfigUrl);
        $lessMinFile->expects($this->once())->method('getUrl')->willReturn($lessMinUrl);

        $assetMap = [
            ['less/config.less.js', [], $lessConfigFile],
            ['less/less.min.js', [], $lessMinFile]
        ];
        $this->assetRepo->expects($this->exactly(2))->method('createAsset')->willReturnMap($assetMap);

        $resultGroups = "<script src=\"$lessConfigUrl\"></script>\n<script src=\"$lessMinUrl\"></script>\n";

        // Call method
        $this->assertSame($resultGroups, $this->model->renderAssets(['js' => '']));
    }
}
