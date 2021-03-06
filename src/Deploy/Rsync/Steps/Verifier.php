<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Rsync\Steps;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\SSHProcess;
use Hal\Agent\Remoting\SSHSessionManager;

/**
 * Ugh http://unix.stackexchange.com/questions/42685/rsync-how-to-exclude-the-topmost-directory
 */
class Verifier
{
    const CREATE_DIR = 'Create target directory';
    const ERR_COULD_NOT_CONNECT = 'Could not connect to server';
    const ERR_READ_PERMISSIONS = 'Could not read permissions of target directory';
    const ERR_VERIFY_PERMISSIONS = 'Could not verify permissions of target directory';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSHSessionManager
     */
    private $sshManager;

    /**
     * @var SSHProcess
     */
    private $remoter;

    /**
     * @param EventLogger $logger
     * @param SSHSessionManager $sshManager
     * @param SSHProcess $remoter
     */
    public function __construct(EventLogger $logger, SSHSessionManager $sshManager, SSHProcess $remoter)
    {
        $this->logger = $logger;
        $this->sshManager = $sshManager;
        $this->remoter = $remoter;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return bool
     */
    public function __invoke(string $remoteUser, string $remoteServer, string $remotePath): bool
    {
        if (!$this->verifyConnectability($remoteUser, $remoteServer)) {
            return false;
        }

        if (!$this->verifyTargetExists($remoteUser, $remoteServer, $remotePath)) {
            return false;
        }

        if (!$this->verifyTargetIsWriteable($remoteUser, $remoteServer, $remotePath)) {
            return false;
        }

        // all good
        return true;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     *
     * @return bool
     */
    private function verifyConnectability($remoteUser, $remoteServer)
    {
        if (!$ssh = $this->sshManager->createSession($remoteUser, $remoteServer)) {
            $this->logger->event('failure', self::ERR_COULD_NOT_CONNECT, ['errors' => $this->sshManager->getErrors()]);
            return false;
        }

        return true;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $target
     *
     * @return bool
     */
    private function verifyTargetExists($remoteUser, $remoteServer, $target)
    {
        $dirExists = sprintf('test -d "%s"', $target);
        $mkDir = sprintf('mkdir "%s"', $target);

        $context = $this->remoter->createCommand($remoteUser, $remoteServer, $dirExists);
        if (!$response = $this->remoter->run($context, [], [false])) {
            // does not exist, try creating
            $context = $this->remoter->createCommand($remoteUser, $remoteServer, $mkDir);
            if (!$response = $this->remoter->run($context, [], [true, self::CREATE_DIR])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $target
     *
     * @return bool
     */
    private function verifyTargetIsWriteable($remoteUser, $remoteServer, $target)
    {
        $dirWriteable = sprintf('test -w "%s"', $target);
        $getTargetStats = sprintf('ls -ld "%s"', $target);
        $verifyOwner = sprintf('find "%s" -prune -user "%s" -type d', $target, $remoteUser);

        $context = $this->remoter->createCommand($remoteUser, $remoteServer, $dirWriteable);
        $isWriteable = $this->remoter->run($context, [], [false]);

        // Get the ls metadata for log output
        $context = $this->remoter->createCommand($remoteUser, $remoteServer, $getTargetStats);
        if (!$response = $this->remoter->run($context, [], [false])) {
            $this->logger->event('failure', self::ERR_READ_PERMISSIONS, ['directory' => $target]);
            return false;
        }

        $output = trim($this->remoter->getLastOutput());

        $context = $this->remoter->createCommand($remoteUser, $remoteServer, $verifyOwner);
        $isOwned = $this->remoter->run($context, [], [false]);

        if (!$isWriteable || !$isOwned) {
            $this->logger->event('failure', self::ERR_VERIFY_PERMISSIONS, [
                'directory' => $target,
                'currentPermissions' => $output,
                'requiredOwner' => $remoteUser,
                'isWriteable' => $isWriteable ? 'Yes' : 'No'
            ]);
            return false;
        }

        return true;
    }
}
