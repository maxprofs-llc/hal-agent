<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Command\IOInterface;
use Hal\Agent\Executor\ExecutorInterface;
use Hal\Agent\Executor\ExecutorTrait;
use Hal\Agent\Executor\JobStatsTrait;
use Psr\Log\LoggerInterface;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Repository\PushRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Cron worker that will pick up and run any "waiting" pushes.
 */
class DeployCommand implements ExecutorInterface
{
    use ExecutorTrait;
    use FormatterTrait;
    use JobStatsTrait;
    use WorkerTrait;

    const COMMAND_TITLE = 'Worker - Run pending deployments';
    const MSG_SUCCESS = 'All pending deployments were completed.';

    const INFO_NO_PENDING = 'No pending releases found.';

    const SUCCESS_JOB = 'Deployment Success: %s';
    const ERR_JOB = 'Deployment Failed: %s';
    const ERR_JOB_TIMEOUT = 'Deployment Timeout: %s';

    // 1 hour max
    const MAX_JOB_TIMEOUT = 3600;

    // Wait 5 seconds between checks
    const DEFAULT_SLEEP_TIME = 5;

    /**
     * @var PushRepository
     */
    private $pushRepo;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var ProcessBuilder
     */
    private $builder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Process[]
     */
    private $processes;

    /**
     * @var array
     */
    private $deploymentCache;

    /**
     * @var string
     */
    private $workingDir;

    /**
     * Sleep time in seconds
     *
     * @var int
     */
    private $sleepTime;

    /**
     * @param EntityManagerInterface $em
     * @param ProcessBuilder $builder
     * @param LoggerInterface $logger
     * @param string $workingDir
     */
    public function __construct(
        EntityManagerInterface $em,
        ProcessBuilder $builder,
        LoggerInterface $logger,
        $workingDir
    ) {
        $this->pushRepo = $em->getRepository(Push::class);
        $this->em = $em;
        $this->builder = $builder;
        $this->logger = $logger;
        $this->workingDir = $workingDir;

        $this->sleepTime = self::DEFAULT_SLEEP_TIME;
        $this->processes = [];
        $this->deploymentCache = [];
        $this->startTimer();
    }

    /**
     * Set a sleep time in seconds. Only values between 1-30 are allowed.
     *
     * @param int $seconds
     *
     * @return void
     */
    public function setSleepTime($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds > 0 && $seconds < 30) {
            $this->sleepTime = $seconds;
        }
    }

    /**
     * @param Command $command
     *
     * @return void
     */
    public static function configure(Command $command)
    {
        $command
            ->setDescription('Find and deploy all pending releases.');
    }

    /**
     * @param IOInterface $io
     *
     * @return int|null
     */
    public function execute(IOInterface $io)
    {
        $io->title(self::COMMAND_TITLE);

        if (!$pushes = $this->pushRepo->findBy(['status' => 'Waiting'])) {
            $io->note(self::INFO_NO_PENDING);
            return $this->success($io, self::MSG_SUCCESS);
        }

        $io->section('Starting pending deployments');
        $io->text(sprintf('Found %s releases:', count($pushes)));

        foreach ($pushes as $push) {
            $id = $push->id();
            $command = [
                'bin/hal',
                'runner:deploy',
                $id
            ];

            // Skip pushes without deployment target
            if (!$push->deployment()) {
                $io->listing([
                    sprintf("<fg=red>Skipping</> release: <info>%s</info>\n", $id) .
                    sprintf('   > Release <info>%s</info> has no target. Marking as failure.', $push->id())
                ]);

                $this->stopWeirdPush($push);
                continue;
            }

            // Every time the worker runs we need to ensure all deployments spawned are unique.
            // This helps prevent concurrent deployments.
            if ($this->hasConcurrentDeployment($push->deployment())) {
                $io->listing([
                    sprintf("<fg=red>Skipping</> release: <info>%s</info>\n", $id) .
                    sprintf('   > A release to target <info>%s</info> is already in progress.', $push->deployment()->id())
                ]);

                continue;
            }

            $process = $this->builder
                ->setWorkingDirectory($this->workingDir)
                ->setArguments($command)
                ->setTimeout(self::MAX_JOB_TIMEOUT)
                ->getProcess();

            $io->listing([
                sprintf("Starting release: <info>%s</info>\n   > %s", $id, implode(' ', $command))
            ]);

            $this->processes[$id] = $process;
            $process->start();
        }

        $io->section('Waiting for running deployments to finish');

        $this->wait($io);

        $this->outputJobStats($io);

        return $this->success($io, self::MSG_SUCCESS);
    }

    /**
     * @param IOInterface $io
     *
     * @return void
     */
    private function wait(IOInterface $io)
    {
        $allDone = true;
        foreach ($this->processes as $id => $process) {

            $name = sprintf('Release %s', $id);

            if ($process->isRunning()) {
                try {
                    $io->comment(sprintf('Checking release status: <info>%s</info>', $id));

                    $process->checkTimeout();
                    $allDone = false;

                } catch (ProcessTimedOutException $e) {
                    $output = $this->outputJob($name, $process, true);
                    $this->write($output);

                    $io->section(sprintf('Release <info>%s</info> timed out', $id));
                    $io->text($output);

                    $this->logger->warn(sprintf(self::ERR_JOB_TIMEOUT, $id), ['exceptionData' => $output]);

                    unset($this->processes[$id]);
                }

            } else {
                $output = $this->outputJob($name, $process, false);

                $io->section(sprintf('Release <info>%s</info> finished', $id));
                $io->text($output);

                if ($exit = $process->getExitCode()) {
                    $this->logger->info(sprintf(self::ERR_JOB, $id), ['exceptionData' => $output, 'exitCode' => $exit]);
                } else {
                    $this->logger->info(sprintf(self::SUCCESS_JOB, $id), ['exceptionData' => $output]);
                }

                unset($this->processes[$id]);
            }
        }

        if (!$allDone) {
            $io->comment(sprintf('Waiting %d seconds...', $this->sleepTime));

            sleep($this->sleepTime);
            $this->wait($io);
        }
    }

    /**
     * @param Push $push
     *
     * @return void
     */
    private function stopWeirdPush(Push $push)
    {
        $push->withStatus('Error');
        $this->em->merge($push);
        $this->em->flush();
    }

    /**
     * @param Deployment $deployment
     *
     * @return boolean
     */
    private function hasConcurrentDeployment(Deployment $deployment)
    {
        if (isset($this->deploymentCache[$deployment->id()])) {
            return true;
        }

        $this->deploymentCache[$deployment->id()] = true;
        return false;
    }
}