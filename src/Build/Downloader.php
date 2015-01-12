<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Github\ArchiveApi;

class Downloader
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Download GitHub archive';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ArchiveApi
     */
    private $github;

    /**
     * @param EventLogger $logger
     * @param ArchiveApi $github
     */
    public function __construct(EventLogger $logger, ArchiveApi $github)
    {
        $this->logger = $logger;
        $this->github = $github;
    }

    /**
     * @param string $user
     * @param string $repo
     * @param string $ref
     * @param string $target
     * @return boolean
     */
    public function __invoke($user, $repo, $ref, $target)
    {
        if ($isSuccessful = $this->github->download($user, $repo, $ref, $target)) {

            $filesize = filesize($target);
            $this->logger->keep('filesize', ['download' => $filesize]);
            $this->logger->event('success', self::EVENT_MESSAGE, [
                'size' => sprintf('%s MB', round($filesize / 1048576, 2))
            ]);

            return true;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE, [
            'repository' => sprintf('%s/%s', $user, $repo),
            'reference' => $ref,
            'target' => $target
        ]);

        return false;
    }
}
