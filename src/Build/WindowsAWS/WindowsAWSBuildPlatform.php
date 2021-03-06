<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ec2\Ec2Client;
use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Hal\Agent\Build\WindowsAWS\Steps\Cleaner;
use Hal\Agent\Build\WindowsAWS\Steps\Configurator;
use Hal\Agent\Build\WindowsAWS\Steps\Exporter;
use Hal\Agent\Build\WindowsAWS\Steps\Importer;
use Hal\Agent\Build\PlatformTrait;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\JobExecution;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Utility\EncryptedPropertyResolver;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;

/**
 * TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO
 * TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO
 *
 * - Automated setup of agents
 *     - hosts
 *     - docker latest
 *     - bsdtar
 *
 *  - [3.0] Multi-builder support?
 *  - [3.0] Multi-platform support?
 *  - [TODO] Verify logging the right messages/context at the right times
 *
 *  - [3.0] Impersonation / De-escalation of SYSTEM privileges   (Native Builder)
 *  - [3.0] Testing (failure states, breaking out)               (Native Builder)
 *
 * TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO
 * TODO TODO TODO TODO TODO TODO TODO TODO TODO TODO
 */

/**
 * Note about windows AWS instance:
 *
 * - File management
 *     - Do not use 7z! It does not properly support targz (no pax and ustar)
 *     - Install LibArchive for Windows (http://gnuwin32.sourceforge.net/packages/libarchive.htm)
 *     - @todo automate this
 *
 * - Installing framework 3.5 (https://aws.amazon.com/premiumsupport/knowledge-center/net-framework-windows)
 *     - Find EBS Public Snapshot: "Windows 2016 English Installation Media"
 *     - Create Volume in same AZ as instances
 *     - Attach Volume to instance
 *     - RDP into server and bring volume "Online" In "Server Manager -> File Storage -> Volumes -> Disks"
 *     - Run "DISM /Online /Enable-Feature /FeatureName:NetFx3 /All /LimitAccess /Source:d:\sources\sxs"
 *     - Take Disk Offline
 *     - Detach and Delete Volume
 *
 * R&D references:
 *
 * - AWS Powershell cmdlets
 *     http://docs.aws.amazon.com/powershell/latest/reference/Index.html
 *
 * - System.Diagnostics.ProcessStartInfo
 *     https://msdn.microsoft.com/en-us/library/system.diagnostics.processstartinfo(v=vs.110).aspx
 * - System.Diagnostics.Process
 *     https://msdn.microsoft.com/en-us/library/system.diagnostics.process_methods(v=vs.110).aspx
 *
 * - LocalSystem Account
 *     https://msdn.microsoft.com/en-us/library/windows/desktop/ms684190(v=vs.85).aspx
 * - PsExec
 *     https://docs.microsoft.com/en-us/sysinternals/downloads/psexec
 * - SSM Agent source code
 *     https://github.com/aws/amazon-ssm-agent/blob/master/agent/plugins/runscript/runpowershellscript.go
 *
 * - Powershell cli help
 *     https://docs.microsoft.com/en-us/powershell/scripting/core-powershell/console/powershell.exe-command-line-help?view=powershell-5.1
 * - Start-Process cmdlet
 *     https://docs.microsoft.com/en-us/powershell/module/microsoft.powershell.management/start-process?view=powershell-5.1
 */
class WindowsAWSBuildPlatform implements JobPlatformInterface
{
    use FormatterTrait;
    // Comes with EmergencyBuildHandlerTrait, EnvironmentVariablesTrait, IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'Windows Docker Platform - Validating Windows configuration';
    private const STEP_2_EXPORTING = 'Windows Docker Platform - Exporting files to AWS environment';
    private const STEP_3_BUILDING = 'Windows Docker Platform - Running build steps';
    private const STEP_4_IMPORTING = 'Windows Docker Platform - Importing artifacts from AWS environment';
    private const STEP_5_CLEANING = 'Cleaning up AWS builder instance "%s"';

    private const ERR_CONFIGURATOR = 'Windows Docker build platform is not configured correctly';
    private const ERR_EXPORT = 'Failed to export build to build system';
    private const ERR_IMPORT = 'Failed to import build artifacts from build system';
    private const ERR_BAD_DECRYPT = 'An error occured while decrypting encrypted configuration';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var Exporter
     */
    private $exporter;

    /**
     * @var BuilderInterface
     */
    private $builder;

    /**
     * @var Importer
     */
    private $importer;

    /**
     * @var Cleaner
     */
    private $cleaner;

    /**
     * @var EncryptedPropertyResolver
     */
    private $decrypter;

    /**
     * @var string
     */
    private $defaultDockerImage;

    /**
     * @param EventLogger $logger
     * @param EncryptedPropertyResolver $decrypter
     *
     * @param Configurator $configurator
     * @param Exporter $exporter
     * @param BuilderInterface $builder
     * @param Importer $importer
     * @param Cleaner $cleaner
     *
     * @param string $defaultDockerImage
     */
    public function __construct(
        EventLogger $logger,
        EncryptedPropertyResolver $decrypter,
        Configurator $configurator,
        Exporter $exporter,
        BuilderInterface $builder,
        Importer $importer,
        Cleaner $cleaner,
        $defaultDockerImage
    ) {
        $this->logger = $logger;
        $this->decrypter = $decrypter;

        $this->configurator = $configurator;
        $this->exporter = $exporter;
        $this->builder = $builder;
        $this->importer = $importer;
        $this->cleaner = $cleaner;

        $this->defaultDockerImage = $defaultDockerImage;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        $image = $execution->parameter('image') ?? $this->defaultDockerImage;
        $steps = $execution->steps();

        if (!$platformConfig = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return $this->bombout(false);
        }

        if (!$this->export($job->id(), $properties['workspace_path'], $platformConfig)) {
            $this->sendFailureEvent(self::ERR_EXPORT);
            return $this->bombout(false);
        }

        // decrypt
        $env = $this->decrypt($properties['encrypted'], $platformConfig['environment_variables'], $execution->parameter('env'));
        if ($env === null) {
            $this->sendFailureEvent(self::ERR_BAD_DECRYPT);
            return $this->bombout(false);
        }

        // run build
        if (!$this->build($job->id(), $image, $steps, $env, $platformConfig)) {
            return $this->bombout(false);
        }

        if (!$this->import($job->id(), $properties['workspace_path'], $platformConfig)) {
            $this->sendFailureEvent(self::ERR_IMPORT);
            return $this->bombout(false);
        }

        // success
        return $this->bombout(true);
    }

    /**
     * @param Job $job
     *
     * @return array|null
     */
    private function configurator(Job $job)
    {
        $this->getIO()->section(self::STEP_1_CONFIGURING);

        $platformConfig = ($this->configurator)($job);

        if (!$platformConfig) {
            return null;
        }

        $this->outputTable($this->getIO(), 'Platform configuration:', $platformConfig);

        return $platformConfig;
    }

    /**
     * @param string $jobID
     * @param string $workspacePath
     * @param array $platformConfig
     *
     * @return bool
     */
    private function export($jobID, $workspacePath, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_2_EXPORTING);

        $s3 = $platformConfig['sdk']['s3'];
        $ssm = $platformConfig['sdk']['ssm'];
        $instanceID = $platformConfig['instance_id'];

        $buildPath = $workspacePath . '/job';
        $localFile = $workspacePath . '/build_export.tgz';

        $bucket = $platformConfig['bucket'];
        $inputObject = $platformConfig['s3_input_object'];
        $outputObject = $platformConfig['s3_output_object'];

        $this->getIO()->listing([
            sprintf('Workspace: <info>%s</info>', $buildPath),
            sprintf('Local File: <info>%s</info>', $localFile),
            sprintf('S3 Object: <info>%s/%s</info>', $bucket, $inputObject),
            sprintf('S3 Build Artifact: <info>%s/%s</info>', $bucket, $outputObject)
        ]);

        $response = ($this->exporter)($s3, $ssm, $instanceID, $jobID, $buildPath, $localFile, $bucket, $inputObject);

        if ($response) {
            // Set emergency handler in case of super fatal
            $this->enableEmergencyHandler(function () use ($jobID, $platformConfig) {
                $this->cleanupBuilder($jobID, $platformConfig);
            });
        }

        return $response;
    }

    /**
     * @param array $encryptedConfig
     * @param array $platformEnv
     * @param array $env
     *
     * @return array|null
     */
    private function decrypt(array $encryptedConfig, array $platformEnv, array $env)
    {
        $decrypted = $this->decrypter->decryptProperties($encryptedConfig);
        if (count($decrypted) !== count($encryptedConfig)) {
            return null;
        }

        $env = $this->determineEnvironment($platformEnv, $decrypted, $env);

        return $env;
    }

    /**
     * @param string $jobID
     * @param string $image
     *
     * @param array $steps
     * @param array $env
     * @param array $platformConfig
     *
     * @return bool
     */
    private function build($jobID, $image, array $steps, array $env, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_3_BUILDING);

        $ssm = $platformConfig['sdk']['ssm'];
        $instanceID = $platformConfig['instance_id'];

        $this->builder->setIO($this->getIO());

        return ($this->builder)($jobID, $image, $ssm, $instanceID, $steps, $env);
    }

    /**
     * @param string $workspacePath
     * @param string $workspacePath
     * @param array $platformConfig
     *
     * @return bool
     */
    private function import($jobID, $workspacePath, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_4_IMPORTING);

        $s3 = $platformConfig['sdk']['s3'];
        $ssm = $platformConfig['sdk']['ssm'];
        $instanceID = $platformConfig['instance_id'];

        $buildPath = $workspacePath . '/job';
        $localFile = $workspacePath . '/build_import.tgz';

        $bucket = $platformConfig['bucket'];
        $outputObject = $platformConfig['s3_output_object'];

        $this->getIO()->listing([
            sprintf('Workspace: <info>%s</info>', $buildPath),
            sprintf('Remote Object: <info>%s/%s</info>', $bucket, $outputObject),
            sprintf('Local File: <info>%s</info>', $localFile),
        ]);

        return ($this->importer)($s3, $ssm, $instanceID, $jobID, $buildPath, $localFile, $bucket, $outputObject);
    }

    /**
     * @param string $jobID
     * @param array $platformConfig
     *
     * @return void
     */
    private function cleanupBuilder($jobID, array $platformConfig)
    {
        $s3 = $platformConfig['sdk']['s3'];
        $ssm = $platformConfig['sdk']['ssm'];
        $instanceID = $platformConfig['instance_id'];

        $bucket = $platformConfig['bucket'];
        $s3Artifacts = [
            $platformConfig['s3_input_object'],
            $platformConfig['s3_output_object']
        ];

        $this->getIO()->note(sprintf(self::STEP_5_CLEANING, $instanceID));

        ($this->cleaner)($s3, $ssm, $instanceID, $jobID, $bucket, $s3Artifacts);
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function sendFailureEvent($message)
    {
        $this->logger->event('failure', $message);
        $this->getIO()->error($message);
    }
}
