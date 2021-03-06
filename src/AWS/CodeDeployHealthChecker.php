<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\AWS;

use Aws\CodeDeploy\CodeDeployClient;
use Aws\CodeDeploy\Exception\CodeDeployException;
use Aws\ResultInterface;
use DateTime;
use QL\MCP\Common\Clock;
use QL\MCP\Common\Time\TimePoint;

/**
 * Get the health status for a codedeploy group
 *
 * Status:
 *     - Created
 *     - Queued
 *     - InProgress
 *     - Succeeded
 *     - Failed
 *     - Stopped
 *     - Ready <----- bluegreen can continue
 *
 *     - Invalid
 *     - None
 *
 */
class CodeDeployHealthChecker
{
    const STATUS_INVALID = 'Invalid';
    const STATUS_NEVER = 'None';

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var string
     */
    private $outputTimezone;

    /**
     * @param Clock $clock
     * @param string $outputTimezone
     */
    public function __construct(Clock $clock, string $outputTimezone)
    {
        $this->clock = $clock;
        $this->outputTimezone = $outputTimezone;
    }

    /**
     * @param CodeDeployClient $cd
     * @param string $name
     * @param string $group
     *
     * @return array
     */
    public function getLastDeploymentHealth(CodeDeployClient $cd, string $name, string $group): array
    {
        try {
            $result = $cd->listDeployments([
                'applicationName' => $name,
                'deploymentGroupName' => $group
            ]);

            $lastDeploymentID = $result->search('deployments[0]');
            if (!$lastDeploymentID) {
                return $this->buildResponse(self::STATUS_NEVER);
            }

        } catch (CodeDeployException $ex) {
            return $this->buildResponse(self::STATUS_INVALID);
        }

        return $this->getDeploymentHealth($cd, $lastDeploymentID);
    }

    /**
     * @param CodeDeployClient $cd
     * @param string $id
     *
     * @return array
     */
    public function getDeploymentHealth(CodeDeployClient $cd, string $id): array
    {
        $result = $cd->getDeployment(['deploymentId' => $id]);
        return $this->parseStatus($result);
    }

    /**
     * @param CodeDeployClient $cd
     * @param string $id
     *
     * @return array
     */
    public function getDeploymentInstancesHealth(CodeDeployClient $cd, string $id): array
    {
        $health = $this->getDeploymentHealth($cd, $id);

        // Only get instance statuses if deployment is active
        if (!in_array($health['status'], ['InProgress', 'Succeeded', 'Ready', 'Stopped', 'Failed'])) {
            return $health;
        }

        // Technically this would only list a certain number of instances (they are paged)
        // I do not know what the page size is, so lets ignore it for now.
        $result = $cd->listDeploymentInstances([
            'deploymentId' => $id
        ]);

        if (!$instanceIDs = $result->search('instancesList')) {
            return $health;
        }

        $result = $cd->batchGetDeploymentInstances([
            'deploymentId' => $id,
            'instanceIds' => $instanceIDs
        ]);

        $summary = $this->parseInstancesSummary($result);
        $detailed = $this->parseInstancesStatus($result);

        $context = $health + [
            'instances' => $instanceIDs,
            'instancesSummary' => $summary,
            'instancesDetailed' => $detailed
        ];

        return $context;
    }

    /**
     * @param ResultInterface $result
     *
     * @return array
     */
    private function parseStatus(ResultInterface $result)
    {
        $status = $result->search('deploymentInfo.status');
        $overview = $result->search('deploymentInfo.deploymentOverview');
        $error = $result->search('deploymentInfo.errorInformation');

        return $this->buildResponse($status, $overview, $error);
    }

    /**
     * @param ResultInterface $result
     *
     * @return string
     */
    public function parseInstancesSummary(ResultInterface $result): string
    {
        $outputs = [];

        if ($err = $result->search('errorMessage')) {
            $outputs[] = "Error message: $err\n\n";
        }

        // @todo - identify first start, last end, total duration from events?

        $config = [20, 20, 15, 30, 30, 20, 20];
        $rows = [
            ['Instance ID', 'Type', 'Status', 'Start Time', 'End Time', 'Duration', 'Most Recent Event'],
            array_map(function ($size) {
                return str_repeat('-', $size);
            }, $config)
        ];

        $summaries = $result->search('instancesSummary') ?: [];
        foreach ($summaries as $summary) {
            $id = $summary['instanceId'];
            $parts = explode('/', $id);
            $id = array_pop($parts);

            $type = $summary['instanceType'] ?? '';
            if ($type) {
                $type = ($type === 'Green') ? 'Replacement (Green)' : 'Original (Blue)';
            } else {
                $type = 'Original';
            }

            $status = $summary['status'] ?? 'Unknown';
            $events = $summary['lifecycleEvents'];

            $lastFinishedEvent = null;
            foreach ($events as $e) {
                if (in_array($e['status'], ['Succeeded', 'Failed'])) {
                    $lastFinishedEvent = $e;
                }
            }

            $start = $this->clock->fromString($summary['lastUpdatedAt'], DateTime::ATOM);
            $firstEvent = array_shift($events);
            if ($firstEventTime = $firstEvent['startTime'] ?? '') {
                $start = $this->clock->fromString($firstEventTime, DateTime::ATOM);
            }

            $end = null;
            $lastEvent = array_pop($events);
            if ($lastEventTime = $lastEvent['endTime'] ?? '') {
                $end = $this->clock->fromString($lastEventTime, DateTime::ATOM);
            }

            $rows[] = [
                $id,
                $type,
                $status,
                $this->formatTime($start),
                $this->formatTime($end),
                $this->formatDuration($start, $end),
                $lastFinishedEvent ? $lastFinishedEvent['lifecycleEventName'] : ''
            ];
        }

        $outputs[] = $this->renderSummaryLines($rows, $config);

        return implode("\n", $outputs);
    }

    /**
     * @param ResultInterface $result
     *
     * @return string
     */
    private function parseInstancesStatus(ResultInterface $result)
    {
        $outputs = [];

        if ($err = $result->search('errorMessage')) {
            $outputs[] = "Error message: $err\n\n";
        }

        $summaries = $result->search('instancesSummary') ?: [];
        foreach ($summaries as $summary) {
            $id = $summary['instanceId'];
            $type = $summary['instanceType'] ?? 'Original';
            $status = $summary['status'];
            $events = $summary['lifecycleEvents'];
            $updated = $this->formatTime($summary['lastUpdatedAt']);

            $outputs[] = $this->renderInstanceStatus($id, $type, $status, $updated, $events);
        }

        return implode("\n", $outputs);
    }

    /**
     * @param string $status
     * @param array|null $overview
     * @param array|null $error
     *
     * @return array
     */
    private function buildResponse($status, $overview = null, $error = null)
    {
        return [
            'status' => $status,
            'overview' => $overview,
            'error' => $error
        ];
    }

    /**
     * @param string $id
     * @param string $status
     * @param string $updated
     * @param array $events
     *
     * @return string
     */
    private function renderInstanceStatus($id, $type, $status, $updated, array $events)
    {
        $config = [20, 20, 30, 30, 20];
        $output = [
            ['Event Name', 'Status', 'Start', 'End', 'Duration'],
            array_map(function ($size) {
                return str_repeat('-', $size);
            }, $config)
        ];

        $diagnostics = [];

        foreach ($events as $event) {
            if ($start = $event['startTime'] ?? '') {
                $start = $this->clock->fromString($start, DateTime::ATOM);
            }

            if ($end = $event['endTime'] ?? '') {
                $end = $this->clock->fromString($end, DateTime::ATOM);
            }

            $name = $event['lifecycleEventName'] ?? 'Unknown';
            $status = $event['status'] ?? 'Unknown';

            $output[] = [
                $name,
                $status,
                $this->formatTime($start),
                $this->formatTime($end),
                $this->formatDuration($start, $end)
            ];

            // Diagnostics are only added to failed events
            if ($status === 'Failed') {
                $diagnostics[$name] = $event['diagnostics'] ?? [];
            }
        }

        $table = $this->renderSummaryLines($output, $config);

        $log = implode("\n", [
            ">>>> Instance ID: $id",
            ">>>> Status: $status (Type: $type)",
            ">>>> Last Update: $updated\n",
            "$table\n"
        ]);

        foreach ($diagnostics as $name => $diagnostic) {
            $log .= $this->renderDiagnostic($name, $diagnostic);
        }

        return $log;
    }

    /**
     * @param string $name
     * @param array $diagnostic
     *
     * @return string
     */
    private function renderDiagnostic($name, array $diagnostic)
    {
        $msg = $diagnostic['message'];
        $script = $diagnostic['scriptName'];
        $errorCode = $diagnostic['errorCode'];
        $tail = $diagnostic['logTail'];

        return "\n" . implode("\n", [
            "$name event failed! $msg\n",
            "Script: $script",
            "Error Code: $errorCode\n",
            "$tail\n"
        ]);
    }

    /**
     * @param array $lines
     * @param array $config
     *
     * @return string
     */
    private function renderSummaryLines(array $lines, array $config)
    {
        $formatted = [];
        foreach ($lines as $event) {
            $line = [];
            foreach ($config as $index => $size) {
                $line[] = str_pad($event[$index], $size);
            }

            $formatted[] = implode(' | ', $line);
        }

        return implode("\n", $formatted);
    }

    /**
     * @param TimePoint|string|null $time
     *
     * @return string
     */
    private function formatTime($time)
    {
        if (!$time) {
            return 'N/A';
        }

        if (!$time instanceof TimePoint) {
            $time = $this->clock->fromString($time, DateTime::ATOM);
        }

        if ($time) {
            return $time->format('M d, Y h:i:s T', $this->outputTimezone);
        }

        return 'N/A';
    }

    /**
     * @param TimePoint|null $start
     * @param TimePoint|null $end
     *
     * @return string
     */
    private function formatDuration($start, $end)
    {
        if (!$start || !$end) {
            return '';
        }

        $diff = $start->diff($end);
        $sec = (int) $diff->format('%s');
        $min = (int) $diff->format('%i');
        $hrs = (int) $diff->format('%h');

        $total = $sec + ($min * 60) + ($hrs * 3600);

        if ($total > 3600) {
            return sprintf('%d hr, %d min', $hrs, $min);
        }

        if ($total > 60) {
            return sprintf('%d min, %d sec', $min, $sec);
        }

        return sprintf('%d sec', $sec);
    }
}
