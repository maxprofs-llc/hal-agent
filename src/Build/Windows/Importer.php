<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Windows;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\FileSyncManager;
use Hal\Agent\Utility\ProcessRunnerTrait;
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

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Import from build server';
    const ERR_TIMEOUT = 'Import from build server took too long';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var FileSyncManager
     */
    private $fileSyncManager;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * Time (in seconds) to wait before aborting
     *
     * @var int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param FileSyncManager $fileSyncManager
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        FileSyncManager $fileSyncManager,
        ProcessBuilder $processBuilder,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->fileSyncManager = $fileSyncManager;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return boolean
     */
    public function __invoke($buildPath, $remoteUser, $remoteServer, $remotePath)
    {
        if (!$this->transferFiles($buildPath, $remoteUser, $remoteServer, $remotePath)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return bool
     */
    private function transferFiles($buildPath, $remoteUser, $remoteServer, $remotePath)
    {
        $command = $this->fileSyncManager->buildIncomingScpForDirectory('.', $remoteUser, $remoteServer, $remotePath);
        if ($command === null) {
            return false;
        }

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
