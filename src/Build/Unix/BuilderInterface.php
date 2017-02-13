<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Unix;

interface BuilderInterface
{
    /**
     * @param string $imageName
     *
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remoteFile
     *
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke($imageName, $remoteUser, $remoteServer, $remoteFile, array $commands, array $env);
}
