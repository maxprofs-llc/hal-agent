<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Build\FileSyncTrait;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Art Vandaley
 *
 * This uses SCP for file transfer
 */
class Importer
{
    use ProcessRunnerTrait;
    use FileSyncTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Import from build server';
    const ERR_TIMEOUT = 'Import from build server took too long';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * Time (in seconds) to wait before aborting
     *
     * @type int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        ProcessBuilder $processBuilder,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return boolean
     */
    public function __invoke($buildPath, $remoteUser, $buildServer, $remotePath)
    {
        if (!$this->transferFiles($buildPath, $remoteUser, $buildServer, $remotePath)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return bool
     */
    private function transferFiles($buildPath, $remoteUser, $buildServer, $remotePath)
    {
        $command = $this->buildIncomingScp('.', $remoteUser, $buildServer, $remotePath);

        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($command)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            // command timed out, bomb out
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        $dispCommand = implode("\n", $command);
        return $this->processFailure($dispCommand, $process);
    }
}
