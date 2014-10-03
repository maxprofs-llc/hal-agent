<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Exception;
use MCP\DataType\Time\Clock;
use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BuildCommandTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $clock;
    public $resolver;
    public $downloader;
    public $unpacker;
    public $builder;
    public $packer;
    public $downloadProgress;
    public $processBuilder;

    public $input;
    public $output;

    public function setUp()
    {
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->clock = new Clock('now', 'UTC');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Build\Resolver');
        $this->downloader = Mockery::mock('QL\Hal\Agent\Build\Downloader');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Build\Unpacker');
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Builder');
        $this->packer = Mockery::mock('QL\Hal\Agent\Build\Packer');
        $this->downloadProgress = Mockery::mock('QL\Hal\Agent\Helper\DownloadProgressHelper');
        $this->processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');

        $this->output = new BufferedOutput;
    }

    public function testBuildResolvingFails()
    {
        $this->input = new ArrayInput([
            'BUILD_ID' => '1'
        ]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturnNull();

        $command = new BuildCommand(
            'cmd',
            $this->em,
            $this->clock,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
            $this->processBuilder
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
Resolving build properties
Build details could not be resolved.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testSuccess()
    {
        $this->input = new ArrayInput([
            'BUILD_ID' => '1'
        ]);

        $build = Mockery::mock('QL\Hal\Core\Entity\Build', [
            'getStatus' => null,
            'setStatus' => null,
            'setStart' => null,
            'setEnd' => null,
            'getId' => 1234,
            'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                'getKey' => null
            ]),
            'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                'getKey' => null
            ])
        ]);

        $this->em
            ->shouldReceive('merge')
            ->with($build);
        $this->em
            ->shouldReceive('flush');

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'build'  => $build,
                'archiveFile' => 'path/file',
                'buildPath' => 'path/dir',
                'githubUser' => 'user1',
                'githubRepo' => 'repo1',
                'githubReference' => 'master',
                'buildCommand' => 'bin/build',
                'environmentVariables' => [],
                'buildFile' => 'path/file',
                'artifacts' => [
                    'path/dir',
                    'path/file'
                ]
            ]);

        $this->downloadProgress
            ->shouldReceive('enableDownloadProgress');

        $this->downloader
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);

        // cleanup
        $this->processBuilder
            ->shouldReceive('getProcess->run')
            ->twice();

        $command = new BuildCommand(
            'cmd',
            $this->em,
            $this->clock,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
            $this->processBuilder
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
Resolving build properties
Downloading github repository
Unpacking github repository
Running build command
Packing build into archive
Success!

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testEmergencyErrorHandling()
    {
        $this->input = new ArrayInput([
            'BUILD_ID' => '1'
        ]);

        $build = Mockery::mock('QL\Hal\Core\Entity\Build', [
            'getStatus' => 'Building',
            'getId' => 1234,
            'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                'getKey' => null
            ]),
            'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                'getKey' => null
            ])
        ]);

        $build
            ->shouldReceive('setEnd')
            ->once();
        $build
            ->shouldReceive('setStatus')
            ->with('Error')
            ->once();

        $this->em
            ->shouldReceive('merge')
            ->with($build);
        $this->em
            ->shouldReceive('flush')
            ->once();

        $this->downloadProgress->shouldIgnoreMissing();
        $this->downloader->shouldReceive(['__invoke' => true]);
        // simulate an error
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andThrow(new Exception);
        $this->builder->shouldReceive(['__invoke' => true]);
        $this->packer->shouldReceive(['__invoke' => true]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'build'  => $build,
                'buildPath' => 'path/dir',
                'githubUser' => 'user1',
                'githubRepo' => 'repo1',
                'githubReference' => 'master',
                'buildFile' => 'path/file',
                'artifacts' => []
            ]);

        $command = new BuildCommand(
            'cmd',
            $this->em,
            $this->clock,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
            $this->processBuilder
        );

        try {
            $command->run($this->input, $this->output);
        } catch (Exception $e) {}

        // this will call __destruct
        unset($command);
    }
}