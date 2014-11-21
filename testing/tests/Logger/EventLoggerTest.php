<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Mockery;
use PHPUnit_Framework_TestCase;

class EventLoggerTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $factory;
    public $notifier;
    public $clock;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->factory = Mockery::mock('QL\Hal\Agent\Logger\EventFactory');
        $this->notifier = Mockery::mock('QL\Hal\Agent\Logger\Notifier');
        $this->clock = Mockery::mock('MCP\DataType\Time\Clock');
    }

    public function testKeepDataIsPassedToNotifier()
    {
        $this->notifier
            ->shouldReceive('keep')
            ->with('data1', 'testing1')
            ->once();
        $this->notifier
            ->shouldReceive('keep')
            ->with('data2', 'testing2')
            ->once();

        $logger = new EventLogger($this->em, $this->factory, $this->notifier, $this->clock);

        $logger->keep('data1', 'testing1');
        $logger->keep('data2', 'testing2');
    }

    public function testEventIsPassedToFactory()
    {
        $this->factory
            ->shouldReceive('info')
            ->with('test message', ['data' => 'test1'])
            ->once();

        $logger = new EventLogger($this->em, $this->factory, $this->notifier, $this->clock);

        $logger->event('info', 'test message', ['data' => 'test1']);
    }

    public function testInvalidEventStatusIsIgnored()
    {
        $this->factory
            ->shouldReceive('info')
            ->never();

        $logger = new EventLogger($this->em, $this->factory, $this->notifier, $this->clock);

        $logger->event('testing');
    }

    public function testSubscriptionPassedToNotifier()
    {
        $this->notifier
            ->shouldReceive('addSubscription')
            ->with('build.end', 'service.name')
            ->once();

        $logger = new EventLogger($this->em, $this->factory, $this->notifier, $this->clock);

        $logger->addSubscription('build.end', 'service.name');
    }

    public function testBuildIsSavedAndPersistedWhenStarted()
    {
        // $this->notifier
        //     ->shouldReceive('addSubscription')
        //     ->with('end', 'service.name')
        //     ->once();

        $build = Mockery::mock('QL\Hal\Core\Entity\Build', ['setStatus' => null, 'setStart' => null]);

        $this->factory
            ->shouldReceive('setBuild')
            ->with($build)
            ->once();

        $this->clock
            ->shouldReceive('read')
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($build)
            ->once();
        $this->em
            ->shouldReceive('flush')
            ->once();

        $logger = new EventLogger($this->em, $this->factory, $this->notifier, $this->clock);

        $logger->start($build);
        // $logger->addSubscription('end', 'service.name');
    }

    public function testEventNameIsAutoResolvedIfJobStarted()
    {
        $this->notifier
            ->shouldReceive('addSubscription')
            ->with('push.end', 'service.name')
            ->once();

        $push = Mockery::mock('QL\Hal\Core\Entity\Push', ['setStatus' => null, 'setStart' => null]);

        $this->factory
            ->shouldReceive('setPush');
        $this->clock
            ->shouldReceive('read');
        $this->em
            ->shouldReceive('merge');
        $this->em
            ->shouldReceive('flush');

        $logger = new EventLogger($this->em, $this->factory, $this->notifier, $this->clock);

        $logger->start($push);
        $logger->addSubscription('end', 'service.name');
    }

    public function testPushIsSuccess()
    {
        $push = Mockery::mock('QL\Hal\Core\Entity\Push', [
            'setStatus' => null,
            'setStart' => null,
            'setEnd' => null,
            'getStatus' => 'Pushing'
        ]);

        $this->notifier
            ->shouldReceive('sendNotifications')
            ->once();
        $this->factory
            ->shouldReceive('setStage')
            ->with('push.success')
            ->once();

        $this->factory
            ->shouldReceive('setPush');
        $this->clock
            ->shouldReceive('read')
            ->twice();
        $this->em
            ->shouldReceive('merge');
        $this->em
            ->shouldReceive('flush');

        $logger = new EventLogger($this->em, $this->factory, $this->notifier, $this->clock);

        $logger->start($push);
        $logger->success();
    }

    public function testBuildIsFailure()
    {
        $build = Mockery::mock('QL\Hal\Core\Entity\Build', [
            'setStatus' => null,
            'setStart' => null,
            'setEnd' => null,
            'getStatus' => 'Building'
        ]);

        $this->notifier
            ->shouldReceive('sendNotifications')
            ->once();
        $this->factory
            ->shouldReceive('setStage')
            ->with('build.failure')
            ->once();

        $this->factory
            ->shouldReceive('setBuild');
        $this->clock
            ->shouldReceive('read')
            ->twice();
        $this->em
            ->shouldReceive('merge');
        $this->em
            ->shouldReceive('flush');

        $logger = new EventLogger($this->em, $this->factory, $this->notifier, $this->clock);

        $logger->start($build);
        $logger->failure();
    }

}
