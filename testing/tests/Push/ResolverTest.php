<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use MCP\DataType\Time\Clock;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository;
use QL\Hal\Core\Entity\Server;

class ResolverTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    /**
     * @expectedException QL\Hal\Agent\Push\PushException
     * @expectedExceptionMessage Push "1234" could not be found!
     */
    public function testPushNotFound()
    {
        $clock = new Clock('now', 'UTC');
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\PushRepository', [
            'find' => null
        ]);

        $action = new Resolver($this->logger, $repo, $clock, 'sshuser', 'ENV_PATH', 'ARCHIVE_PATH', 'http://git');

        $properties = $action('1234', 'pushmethod');
    }

    /**
     * @expectedException QL\Hal\Agent\Push\PushException
     * @expectedExceptionMessage Push "1234" has a status of "Poo"! It cannot be redeployed.
     */
    public function testPushNotCorrectStatus()
    {
        $push = new Push;
        $push->setStatus('Poo');

        $clock = new Clock('now', 'UTC');
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\PushRepository', [
            'find' => $push,
            'findBy' => []
        ]);

        $action = new Resolver($this->logger, $repo, $clock, 'sshuser', 'ENV_PATH', 'ARCHIVE_PATH', 'http://git');

        $properties = $action('1234', 'pushmethod');
    }

    /**
     * @expectedException QL\Hal\Agent\Push\PushException
     * @expectedExceptionMessage Push "1234" is trying to clobber a running push! It cannot be deployed at this time.
     */
    public function testPushFindsActiveDeployment()
    {
        $deployment = new Deployment;
        $push = new Push;
        $push->setStatus('Waiting');
        $push->setDeployment($deployment);

        $clock = new Clock('now', 'UTC');
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\PushRepository', [
            'find' => $push,
            'findBy' => ['derp']
        ]);

        $action = new Resolver($this->logger, $repo, $clock, 'sshuser', 'ENV_PATH', 'ARCHIVE_PATH', 'http://git');

        $properties = $action('1234', 'pushmethod');
    }

    public function testSuccess()
    {
        $repository = new Repository;
        $repository->setGithubUser('user1');
        $repository->setGithubRepo('repo1');
        $repository->setBuildTransformCmd('bin/build-transform');
        $repository->setPrePushCmd('bin/pre');
        $repository->setPostPushCmd('bin/post');
        $repository->setKey('repokey');

        $environment = new Environment;
        $environment->setKey('envname');

        $build = new Build;
        $build->setId('8956');
        $build->setBranch('master');
        $build->setCommit('5555');
        $build->setRepository($repository);
        $build->setEnvironment($environment);

        $server = new Server;
        $server->setName('127.0.0.1');

        $deployment = new Deployment;
        $deployment->setPath('/herp/derp');
        $deployment->setServer($server);

        $push = new Push;
        $push->setId('1234');
        $push->setStatus('Waiting');
        $push->setBuild($build);
        $push->setDeployment($deployment);

        $expected = [
            'push' => $push,
            'method' => 'pushmethod',
            'hostname' => '127.0.0.1',
            'syncPath' => 'sshuser@127.0.0.1:/herp/derp',
            'remotePath' => '/herp/derp',

            'configuration' => [
                'system' => 'global',
                'dist' => '.',
                'exclude' => [
                    'config/database.ini',
                    'data/'
                ],
                'build' => [],
                'build_transform' => [
                    'bin/build-transform'
                ],
                'pre_push' => [
                    'bin/pre'
                ],
                'post_push' => [
                    'bin/post'
                ]
            ],

            'location' => [
                'path' => 'testdir/hal9000-push-1234',
                'archive' => 'ARCHIVE_PATH/hal9000-8956.tar.gz',
                'tempArchive' => 'testdir/hal9000-1234.tar.gz'
            ],

            'artifacts' => [
                'testdir/hal9000-1234.tar.gz',
                'testdir/hal9000-push-1234'
            ]
        ];

        $expectedEnv = [
            'HOME' => 'testdir/home/',
            'PATH' => 'ENV_PATH',
            'HAL_HOSTNAME' => '127.0.0.1',
            'HAL_PATH' => '/herp/derp',
            'HAL_BUILDID' => '8956',
            'HAL_COMMIT' => '5555',
            'HAL_GITREF' => 'master',
            'HAL_ENVIRONMENT' => 'envname',
            'HAL_REPO' => 'repokey'
        ];

        $expectedServerEnv = [
            'HAL_HOSTNAME' => '127.0.0.1',
            'HAL_PATH' => '/herp/derp',
            'HAL_BUILDID' => '8956',
            'HAL_COMMIT' => '5555',
            'HAL_GITREF' => 'master',
            'HAL_ENVIRONMENT' => 'envname',
            'HAL_REPO' => 'repokey'
        ];

        $expectedPushProperties = [
            'id' => '8956',
            'source' => 'http://git/user1/repo1',
            'env' => 'envname',
            'user' => null,
            'reference' => 'master',
            'commit' => '5555',
            'date' => '2015-03-15T08:00:00-04:00'
        ];

        $clock = new Clock('2015-03-15 12:00:00', 'UTC');
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository\PushRepository', [
            'find' => $push,
            'findBy' => []
        ]);

        $action = new Resolver($this->logger, $repo, $clock, 'sshuser', 'ENV_PATH', 'ARCHIVE_PATH', 'http://git');
        $action->setBaseBuildDirectory('testdir');

        $properties = $action('1234', 'pushmethod');

        $this->assertSame($expectedEnv, $properties['environmentVariables']);
        $this->assertSame($expectedServerEnv, $properties['serverEnvironmentVariables']);
        $this->assertSame($expectedPushProperties, $properties['pushProperties']);

        unset($properties['environmentVariables']);
        unset($properties['serverEnvironmentVariables']);
        unset($properties['pushProperties']);
        $this->assertSame($expected, $properties);
    }
}
