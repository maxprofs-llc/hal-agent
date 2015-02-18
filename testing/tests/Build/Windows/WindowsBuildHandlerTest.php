<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class WindowsBuildHandlerTest extends PHPUnit_Framework_TestCase
{
    public $output;
    public $logger;

    public $exporter;
    public $builder;
    public $importer;
    public $cleaner;
    public $decrypter;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');

        $this->exporter = Mockery::mock('QL\Hal\Agent\Build\Windows\Exporter', ['__invoke' => true]);
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Windows\Builder', ['__invoke' => true]);
        $this->importer = Mockery::mock('QL\Hal\Agent\Build\Windows\Importer', ['__invoke' => true]);
        $this->cleaner = Mockery::mock('QL\Hal\Agent\Build\Windows\Cleaner', ['__invoke' => true]);
        $this->decrypter = Mockery::mock('QL\Hal\Agent\Utility\EncryptedPropertyResolver');
    }

    public function testSuccess()
    {
        $properties = [
            'windows' => [
                'buildUser' => 'sshuser',
                'buildServer' => 'windowsserver',
                'remotePath' => '/path',
                'environmentVariables' => []
            ],
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->exporter
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        $this->importer
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        // non-essential commands
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();

        $actual = $handler($this->output, $properties['configuration']['build'], $properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
Building on windows
Validating windows configuration
Exporting files to build server
Running build command
Importing files from build server
Cleaning up build server

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailSanityCheck()
    {
        $properties = [
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Windows build system is not configured')
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();

        $actual = $handler($this->output, $properties['configuration']['build'], $properties);
        $this->assertSame(200, $actual);
    }

    public function testFailOnBuild()
    {
        $properties = [
            'windows' => [
                'buildUser' => 'sshuser',
                'buildServer' => 'windowsserver',
                'remotePath' => '/path',
                'environmentVariables' => []
            ],
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();

        $actual = $handler($this->output, $properties['configuration']['build'], $properties);
        $this->assertSame(202, $actual);
    }

    public function testEncryptedPropertiesMergedIntoEnv()
    {
        $properties = [
            'windows' => [
                'buildUser' => 'sshuser',
                'buildServer' => 'windowsserver',
                'remotePath' => '/path',
                'environmentVariables' => ['derp' => 'herp']
            ],
            'configuration' => [
                'system' => 'windows',
                'build' => ['cmd1'],
            ],
            'location' => [
                'path' => ''
            ],
            'encrypted' => [
                'VAL1' => 'testing1',
                'VAL2' => 'testing2'
            ]
        ];

        $this->decrypter
            ->shouldReceive('decryptAndMergeProperties')
            ->with(
                ['derp' => 'herp'],
                [
                    'VAL1' => 'testing1',
                    'VAL2' => 'testing2'
                ]
            )
            ->andReturn(['decrypt-output']);

        $this->builder
            ->shouldReceive('__invoke')
            ->with(
                'sshuser',
                'windowsserver',
                '/path',
                ['cmd1'],
                ['decrypt-output']
            )
            ->andReturn(true)
            ->once();

        $handler = new WindowsBuildHandler(
            $this->logger,
            $this->exporter,
            $this->builder,
            $this->importer,
            $this->cleaner,
            $this->decrypter
        );
        $handler->disableShutdownHandler();

        $actual = $handler($this->output, $properties['configuration']['build'], $properties);

        $this->assertSame(0, $actual);
    }
}