<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\JobConfiguration;

trait DefaultConfigurationTrait
{
    /**
     * @return array
     */
    private function buildDefaultConfiguration()
    {
        return [
            'platform' => 'linux',
            'image' => '',

            'dist' => '.',
            'transform_dist' => '.',

            'env' => [],

            // Build stages
            'build' => [],

            // Release stages
            'build_transform' => [],
            'before_deploy' => [],
            'deploy' => [],
            'after_deploy' => [],

            // rsync only
            'rsync_exclude' => [],
            'rsync_before' => [],
            'rsync_after' => []
        ];
    }
}
