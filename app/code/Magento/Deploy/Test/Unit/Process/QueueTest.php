<?php
/**
 * Copyright 2017 Adobe
 * All Rights Reserved.
 */
declare(strict_types=1);

namespace Magento\Deploy\Test\Unit\Process;

use Magento\Deploy\Package\Package;
use Magento\Deploy\Process\Queue;
use Magento\Deploy\Process\TimeoutException;
use Magento\Deploy\Service\DeployPackage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Queue class.
 *
 * @coversDefaultClass \Magento\Deploy\Process\Queue
 */
class QueueTest extends TestCase
{
    /**
     * @var AppState&MockObject
     */
    private AppState&MockObject $appState;

    /**
     * @var LocaleResolver&MockObject
     */
    private LocaleResolver&MockObject $localeResolver;

    /**
     * @var ResourceConnection&MockObject
     */
    private ResourceConnection&MockObject $resourceConnection;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * @var DeployPackage&MockObject
     */
    private DeployPackage&MockObject $deployPackageService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appState = $this->createMock(AppState::class);
        $this->localeResolver = $this->createMock(LocaleResolver::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->deployPackageService = $this->createMock(DeployPackage::class);
    }

    /**
     * Create Queue instance with mocked dependencies.
     *
     * @param int $maxProcesses Maximum number of parallel processes.
     * @param int $maxExecTime Maximum execution time in seconds.
     * @return Queue
     */
    private function createQueue(int $maxProcesses = 1, int $maxExecTime = 900): Queue
    {
        return new Queue(
            $this->appState,
            $this->localeResolver,
            $this->resourceConnection,
            $this->logger,
            $this->deployPackageService,
            [],
            $maxProcesses,
            $maxExecTime
        );
    }

    /**
     * Create a Package mock with common configuration.
     *
     * @param string $path
     * @param int|null $state
     * @param Package|null $parent
     * @return Package&MockObject
     */
    private function createPackageMock(
        string $path,
        ?int $state = null,
        ?Package $parent = null
    ): Package&MockObject {
        $package = $this->createMock(Package::class);
        $package->method('getPath')->willReturn($path);
        $package->method('getState')->willReturn($state);
        $package->method('getArea')->willReturn('frontend');
        $package->method('getLocale')->willReturn('en_US');
        $package->method('getFiles')->willReturn([]);
        $package->method('getPreProcessors')->willReturn([]);
        if ($parent !== null) {
            $package->method('getParent')->willReturn($parent);
        }
        return $package;
    }

    /**
     * Set a private property on the Queue instance.
     *
     * @param Queue $queue
     * @param string $property
     * @param mixed $value
     * @return void
     */
    private function setPrivateProperty(Queue $queue, string $property, mixed $value): void
    {
        (new \ReflectionClass($queue))->getProperty($property)->setValue($queue, $value);
    }

    /**
     * Get a private property from the Queue instance.
     *
     * @param Queue $queue
     * @param string $property
     * @return mixed
     */
    private function getPrivateProperty(Queue $queue, string $property): mixed
    {
        return (new \ReflectionClass($queue))->getProperty($property)->getValue($queue);
    }

    /**
     * Invoke a private method on the Queue instance.
     *
     * @param Queue $queue Queue instance.
     * @param string $method Method name.
     * @param array $args Method arguments.
     * @return mixed
     */
    private function invokeMethod(Queue $queue, string $method, array $args = []): mixed
    {
        return (new \ReflectionClass($queue))->getMethod($method)->invokeArgs($queue, $args);
    }

    /**
     * Test adding and processing packages.
     *
     * @param array $deps
     * @param string $area
     * @param bool $hasParent
     * @return void
     * @dataProvider addAndProcessDataProvider
     * @covers ::add
     * @covers ::process
     */
    public function testAddAndProcess(array $deps, string $area, bool $hasParent): void
    {
        $queue = $this->createQueue();

        $package = $this->createMock(Package::class);
        $package->method('getPath')->willReturn('test/path');
        $package->method('getArea')->willReturn($area);
        $package->method('getState')->willReturn(0);
        $package->method('getLocale')->willReturn('en_US');
        $package->method('getFiles')->willReturn([]);
        $package->method('getPreProcessors')->willReturn([]);
        $package->expects($hasParent ? $this->exactly(2) : $this->once())
            ->method('getParent')->willReturn($hasParent ? $package : null);

        $this->appState->expects($this->once())->method('emulateAreaCode');
        $this->logger->expects($this->exactly(2))->method('info');

        $this->assertTrue($queue->add($package, $deps));
        $this->assertArrayHasKey('test/path', $queue->getPackages());
        $this->assertSame(0, $queue->process());
    }

    /**
     * Data provider for testAddAndProcess.
     *
     * @return array
     */
    public static function addAndProcessDataProvider(): array
    {
        return [
            'frontend with parent' => [[], 'frontend', true],
            'adminhtml with deps' => [['dep' => 'val'], 'adminhtml', true],
            'base area no parent' => [[], Package::BASE_AREA, false],
        ];
    }

    /**
     * Test executePackage with various conditions.
     *
     * @param bool $inPkg
     * @param bool $depsNotDone
     * @param int|null $state
     * @param int|null $pid
     * @param bool $exec
     * @return void
     * @dataProvider executePackageDataProvider
     * @covers ::executePackage
     * @covers ::getPid
     * @covers ::isDeployed
     */
    public function testExecutePackage(bool $inPkg, bool $depsNotDone, ?int $state, ?int $pid, bool $exec): void
    {
        $queue = $this->createQueue();
        $package = $this->createPackageMock('test/path', $state);

        if ($pid !== null) {
            $this->setPrivateProperty($queue, 'processIds', ['test/path' => $pid]);
        }
        if (!$inPkg) {
            $this->logger->expects($this->once())->method('debug');
        }
        $this->appState->expects($exec ? $this->once() : $this->never())->method('emulateAreaCode');

        $packages = $inPkg ? ['test/path' => ['package' => $package, 'dependencies' => []]] : [];
        $this->invokeMethod($queue, 'executePackage', [$package, 'test/path', &$packages, $depsNotDone]);
    }

    /**
     * Data provider for testExecutePackage.
     *
     * @return array
     */
    public static function executePackageDataProvider(): array
    {
        return [
            'duplicate with pid' => [false, false, null, 12345, false],
            'duplicate no pid' => [false, false, null, null, false],
            'deps not finished' => [true, true, null, null, false],
            'already deployed' => [true, false, Package::STATE_COMPLETED, null, false],
            'ready to execute' => [true, false, null, null, true],
        ];
    }

    /**
     * Test process throws TimeoutException when max execution time exceeded.
     *
     * @return void
     * @covers ::process
     * @covers ::checkTimeout
     */
    public function testProcessThrowsTimeoutException(): void
    {
        $queue = $this->createQueue(1, 0);
        $parent = $this->createPackageMock('parent');
        $package = $this->createPackageMock('test', null, $parent);

        $queue->add($package, ['dep' => $this->createPackageMock('dep')]);

        $this->expectException(TimeoutException::class);
        $queue->process();
    }

    /**
     * Test awaitForAllProcesses waits for in-progress packages and closes connection.
     *
     * @param int $maxProcs
     * @param bool $hasInProgress
     * @param bool $expectClose
     * @return void
     * @dataProvider awaitDataProvider
     * @covers ::awaitForAllProcesses
     * @covers ::isDeployed
     * @covers ::refreshStatus
     * @covers ::isCanBeParalleled
     */
    public function testAwaitForAllProcesses(int $maxProcs, bool $hasInProgress, bool $expectClose): void
    {
        if ($expectClose && !function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $queue = $this->createQueue($maxProcs);
        $this->setPrivateProperty($queue, 'lastJobStarted', time());

        if ($hasInProgress) {
            $this->setPrivateProperty($queue, 'inProgress', [
                'path' => $this->createPackageMock('path', Package::STATE_COMPLETED)
            ]);
            $this->setPrivateProperty($queue, 'logDelay', 10);
            $this->logger->expects($this->once())->method('info')->with('.');
        }

        $this->resourceConnection->expects($expectClose ? $this->once() : $this->never())
            ->method('closeConnection');

        $this->invokeMethod($queue, 'awaitForAllProcesses');
    }

    /**
     * Data provider for testAwaitForAllProcesses.
     *
     * @return array
     */
    public static function awaitDataProvider(): array
    {
        return [
            'single proc with inProgress' => [1, true, false],
            'parallel mode closes conn' => [4, false, true],
        ];
    }

    /**
     * Test refreshStatus increments logDelay when less than 10.
     *
     * @return void
     * @covers ::refreshStatus
     */
    public function testRefreshStatusIncrementsLogDelay(): void
    {
        $queue = $this->createQueue();
        $this->setPrivateProperty($queue, 'logDelay', 5);

        $this->logger->expects($this->never())->method('info');

        $this->invokeMethod($queue, 'refreshStatus');

        $logDelay = $this->getPrivateProperty($queue, 'logDelay');
        $this->assertSame(6, $logDelay);
    }

    /**
     * Test assertAndExecute handles dependencies correctly.
     *
     * @param bool $depDeployed
     * @param bool $depInPkg
     * @param int $calls
     * @return void
     * @dataProvider assertAndExecuteDataProvider
     * @covers ::assertAndExecute
     * @covers ::isDeployed
     * @covers ::executePackage
     */
    public function testAssertAndExecuteWithDependencies(bool $depDeployed, bool $depInPkg, int $calls): void
    {
        $queue = $this->createQueue();
        $parent = $this->createPackageMock('parent/path');
        $dep = $this->createPackageMock('dep/path', $depDeployed ? Package::STATE_COMPLETED : null);
        $dep->method('getParent')->willReturn(null);

        $package = $this->createMock(Package::class);
        $package->method('getPath')->willReturn('main/path');
        $package->method('getParent')->willReturn($parent);
        $package->method('getState')->willReturn($depDeployed ? 0 : null);
        $package->method('getArea')->willReturn('frontend');
        $package->method('getLocale')->willReturn('en_US');
        $package->method('getFiles')->willReturn([]);
        $package->method('getPreProcessors')->willReturn([]);

        $this->appState->expects($this->exactly($calls))->method('emulateAreaCode');

        $packages = ['main/path' => ['package' => $package, 'dependencies' => ['dep/path' => $dep]]];
        if ($depInPkg) {
            $packages['dep/path'] = ['package' => $dep, 'dependencies' => []];
        }

        $this->invokeMethod($queue, 'assertAndExecute', ['main/path', &$packages, $packages['main/path']]);
    }

    /**
     * Data provider for testAssertAndExecuteWithDependencies.
     *
     * @return array
     */
    public static function assertAndExecuteDataProvider(): array
    {
        return [
            'dep deployed' => [true, false, 1],
            'dep waiting' => [false, false, 0],
            'dep recursive' => [false, true, 2],
        ];
    }

    /**
     * Test isDeployed returns correct status for various conditions.
     *
     * @param int $maxProcs
     * @param int|null $state
     * @param int|null $pid
     * @param bool $expected
     * @return void
     * @dataProvider isDeployedDataProvider
     * @covers ::isDeployed
     * @covers ::isCanBeParalleled
     * @covers ::getPid
     */
    public function testIsDeployed(int $maxProcs, ?int $state, ?int $pid, bool $expected): void
    {
        if ($maxProcs > 1 && !function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $queue = $this->createQueue($maxProcs);
        $package = $this->createPackageMock('test/path', $state);

        if ($pid !== null) {
            $this->setPrivateProperty($queue, 'processIds', ['test/path' => $pid]);
        }

        $this->assertSame($expected, (bool)$this->invokeMethod($queue, 'isDeployed', [$package]));
    }

    /**
     * Data provider for testIsDeployed.
     *
     * @return array
     */
    public static function isDeployedDataProvider(): array
    {
        return [
            'non-parallel completed' => [1, Package::STATE_COMPLETED, null, true],
            'non-parallel null' => [1, null, null, false],
            'parallel state set' => [4, Package::STATE_COMPLETED, null, true],
            'parallel null pid' => [4, null, null, false],
        ];
    }

    /**
     * Test isDeployed throws exception for invalid PID.
     *
     * @return void
     * @covers ::isDeployed
     * @covers ::getPid
     */
    public function testIsDeployedThrowsExceptionForInvalidPid(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $queue = $this->createQueue(4);
        $this->setPrivateProperty($queue, 'processIds', ['test/path' => 999999]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error encountered checking child process status');

        $this->invokeMethod($queue, 'isDeployed', [$this->createPackageMock('test/path')]);
    }

    /**
     * Test __destruct throws exception for invalid PID.
     *
     * @return void
     * @covers ::__destruct
     * @covers ::getPid
     */
    public function testDestructThrowsExceptionForInvalidPid(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $queue = $this->createQueue(4);
        $this->setPrivateProperty($queue, 'inProgress', ['test/path' => $this->createPackageMock('test/path')]);
        $this->setPrivateProperty($queue, 'processIds', ['test/path' => 999999]);

        $this->logger->expects($this->atLeastOnce())->method('info');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Error encountered waiting for child process');

        $queue->__destruct();
    }

    /**
     * Test execute in non-parallel mode calls deploy service directly.
     *
     * @return void
     * @covers ::execute
     */
    public function testExecuteInNonParallelMode(): void
    {
        $queue = $this->createQueue(1); // Non-parallel mode

        $package = $this->createMock(Package::class);
        $package->method('getPath')->willReturn('test/path');
        $package->method('getArea')->willReturn('frontend');
        $package->method('getState')->willReturn(null);
        $package->method('getLocale')->willReturn('en_US');
        $package->method('getFiles')->willReturn(['file1.js', 'file2.css']);
        $package->method('getPreProcessors')->willReturn([]);
        $package->method('getParent')->willReturn(null);

        $this->appState->expects($this->once())->method('emulateAreaCode')
            ->willReturnCallback(fn($area, $callback) => $callback());

        $this->localeResolver->expects($this->once())->method('setLocale')->with('en_US');

        $this->deployPackageService->expects($this->once())
            ->method('deploy')
            ->with($package, []);

        $this->logger->expects($this->atLeastOnce())->method('info');

        $queue->add($package);
        $queue->process();
    }

    /**
     * Test isDeployed returns true when child process completes successfully.
     *
     * @return void
     * @covers ::isDeployed
     * @covers ::getPid
     */
    public function testIsDeployedWithCompletedChildProcess(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        // Fork a real child process that exits immediately
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Failed to fork');
        } elseif ($pid === 0) {
            exit(0);
        }

        // Parent process - wait for child to exit
        usleep(100000);

        $queue = $this->createQueue(4);
        $package = $this->createMock(Package::class);
        $package->method('getPath')->willReturn('test/path');
        $package->method('getState')->willReturn(null);
        $package->expects($this->once())->method('setState')->with(Package::STATE_COMPLETED);

        $this->setPrivateProperty($queue, 'processIds', ['test/path' => $pid]);
        $this->setPrivateProperty($queue, 'inProgress', ['test/path' => $package]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Exited:'),
                $this->callback(fn($ctx) => isset($ctx['process'], $ctx['status']))
            );

        $result = $this->invokeMethod($queue, 'isDeployed', [$package]);
        $this->assertTrue($result);
    }

    /**
     * Test isDeployed returns false when child process exits with failure status.
     *
     * @return void
     * @covers ::isDeployed
     */
    public function testIsDeployedWithFailedChildProcess(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Failed to fork');
        } elseif ($pid === 0) {
            // Child exits with failure status
            // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
            exit(1);
        }

        usleep(100000);

        $queue = $this->createQueue(4);
        $package = $this->createMock(Package::class);
        $package->method('getPath')->willReturn('test/path');
        $package->method('getState')->willReturn(null);
        $package->expects($this->once())->method('setState');

        $this->setPrivateProperty($queue, 'processIds', ['test/path' => $pid]);
        $this->setPrivateProperty($queue, 'inProgress', ['test/path' => $package]);

        $this->logger->expects($this->once())->method('info');

        $result = $this->invokeMethod($queue, 'isDeployed', [$package]);
        $this->assertFalse($result);
    }

}
