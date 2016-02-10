<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command\Worker;

use Symfony\Component\Process\Process;

trait WorkerTrait
{
    private $BOOKEND = <<<STDOUT
+----------------------------------------------------------------------------------------------------------------------+\n
STDOUT;

    /**
     * @param string $id
     * @param Process $process
     * @param bool $timedOut
     *
     * @return string
     */
    private function outputJob($id, Process $process, $timedOut = false)
    {
        $exit = $process->getExitCode();
        $stdout = $process->getOutput() . $process->getErrorOutput();

        $status = "\xE2\x9C\x96" . ' error'; // U+2716

        if ($exit === 0) {
            $status = "\xE2\x9C\x94" . ' success'; // U+2714
        }

        if ($timedOut) {
            $status = "\xE2\x9D\x97" . ' timed out'; // U+2757
        }

        $header = sprintf('Build %s finished: %s', $id, $status);

        $output = $this->BOOKEND .
            $this->buildRows($header, 116 + 2) .
            $this->BOOKEND .
            $this->buildRows($stdout) .
            $this->BOOKEND;

        return "\n" . $output;
    }

    /**
     * @param string|strings[] $lines
     * @param int $max
     *
     * @return string
     */
    private function buildRows($lines, $max = 116)
    {
        if (!is_array($lines)) {
            $lines = explode("\n", trim($lines));
        }

        $output = '';
        foreach ($lines as $line) {
            $line = mb_substr($line, 0, 116);
            $output .= sprintf("| %s |\n", str_pad($line, $max));
        }

        return $output;
    }
}
