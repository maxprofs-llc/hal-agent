<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3;

use Hal\Agent\Build\Packer as BuildPacker;

class Packer extends BuildPacker
{
    const EVENT_MESSAGE = 'Pack deployment into archive';
    const ERR_TIMEOUT = 'Packing the archive took too long';

    // For AWS, deref hardlinks when creating tarball "-h"
    const TAR_FLAGS = '-hvczf';
}
