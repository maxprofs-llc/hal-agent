<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Rsync;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Hal\Agent\Remoting\FileSyncManager;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class Pusher
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Code Deployment';
    const ERR_TIMEOUT = 'Deploying code to server took too long';

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
     * @param array $excludedFiles
     *
     * @return boolean
     */
    public function __invoke($buildPath, $remoteUser, $remoteServer, $remotePath, array $excludedFiles)
    {
        $command = $this->fileSyncManager->buildOutgoingRsync(
            $buildPath,
            $remoteUser,
            $remoteServer,
            $remotePath,
            $excludedFiles
        );

        if ($command === null) {
            return false;
        }

        $rsyncCommand = implode(' ', $command);
        $dispCommand = implode("\n", $command);

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments([''])
            ->setTimeout($this->commandTimeout)
            ->getProcess()
            // processbuilder escapes input, but it breaks the rsync params
            ->setCommandLine($rsyncCommand . ' 2>&1');

        if (!$this->runProcess($process, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return $this->processSuccess($dispCommand, $process);
        }

        return $this->processFailure($dispCommand, $process);
    }
}
