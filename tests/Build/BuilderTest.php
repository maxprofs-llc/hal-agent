<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;

class BuilderTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $logger = new Logger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Builder($logger, $builder);

        $success = $action('path', 'command', []);
        $this->assertTrue($success);

        $message = $logger->messages()[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Build command executed', $message[1]);
        $this->assertSame('test-output', $message[2]['output']);
    }

    public function testFail()
    {
        $logger = new Logger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'test-output',
            'isSuccessful' => false,
            'getExitCode' => 127
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Builder($logger, $builder);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);

        $message = $logger->messages()[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Build command executed with errors', $message[1]);
        $this->assertSame('test-output', $message[2]['output']);
        $this->assertSame(127, $message[2]['exitCode']);
    }
}
