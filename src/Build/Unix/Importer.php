<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\FileSyncManager;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * This uses RSYNC for file transfer
 */
class Importer
{
    use ProcessRunnerTrait;

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
     * @type FileSyncManager
     */
    private $fileSyncManager;

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
     * @param FileSyncManager $fileSyncManager
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     * @param string $remoteUser
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
        $command = $this->fileSyncManager->buildIncomingRsync($buildPath, $remoteUser, $remoteServer, $remotePath);
        if ($command === null) {
            return false;
        }

        $rsyncCommand = implode(' ', $command);

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments([''])
            ->setTimeout($this->commandTimeout)
            ->getProcess()
            // processbuilder escapes input, but it breaks the rsync params
            ->setCommandLine($rsyncCommand . ' 2>&1');

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