<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use MCP\DataType\Time\Clock;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\User;
use QL\Hal\Core\JobIdGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Create a push job
 */
class CreatePushCommand extends Command
{
    use CommandTrait;
    use FormatterTrait;

    /**
     * A list of all possible exit codes of this command
     *
     * type array
     */
    private static $codes = [
        0 => 'Success',
        1 => 'Build not found.',
        2 => 'Provided build was not successful! It cannot be pushed.',
        4 => 'Deployment not found.',
        8 => 'User not found.',
    ];

    /**
     * type EntityManagerInterface
     */
    private $em;

    /**
     * type Clock
     */
    private $clock;

    /**
     * type EntityRepository
     */
    private $buildRepo;
    private $deploymentRepo;
    private $pushRepo;
    private $userRepo;

    /**
     * type JobIdGenerator
     */
    private $unique;

    /**
     * @param string $name
     * @param EntityManagerInterface $em
     * @param Clock $clock
     * @param JobIdGenerator $unique
     */
    public function __construct(
        $name,
        EntityManagerInterface $em,
        Clock $clock,
        JobIdGenerator $unique
    ) {
        parent::__construct($name);

        $this->clock = $clock;

        $this->em = $em;
        $this->buildRepo = $em->getRepository(Build::CLASS);
        $this->deploymentRepo = $em->getRepository(Deployment::CLASS);
        $this->pushRepo = $em->getRepository(Push::CLASS);
        $this->userRepo = $em->getRepository(User::CLASS);

        $this->unique = $unique;
    }

    /**
     * Configure the command
     */
    protected function configure()
    {
        $this
            ->setDescription('Deploy a previously built application to a server.')
            ->addArgument(
                'BUILD_ID',
                InputArgument::REQUIRED,
                'The ID of the build to deploy.'
            )
            ->addArgument(
                'DEPLOYMENT_ID',
                InputArgument::REQUIRED,
                'The ID of the deployment relationship.'
            )
            ->addArgument(
                'USER_ID',
                InputArgument::OPTIONAL,
                'The user that triggered the push.'
            )
            ->addOption(
                'porcelain',
                null,
                InputOption::VALUE_NONE,
                'If set, only the Push ID will be returned.'
            );

        $help = ['<fg=cyan>Exit codes:</fg=cyan>'];
        foreach (static::$codes as $code => $message) {
            $help[] = $this->formatSection($code, $message);
        }
        $this->setHelp(implode("\n", $help));
    }

    /**
     * Run the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $buildId = $input->getArgument('BUILD_ID');
        $deploymentId = $input->getArgument('DEPLOYMENT_ID');
        $userId = $input->getArgument('USER_ID');

        if (!$build = $this->buildRepo->find($buildId)) {
            return $this->failure($output, 1);
        }

        if ($build->getStatus() !== 'Success') {
            return $this->failure($output, 2);
        }

        if (!$deployment = $this->deploymentRepo->find($deploymentId)) {
            return $this->failure($output, 4);
        }

        $user = null;
        if ($userId && !$user = $this->userRepo->find($userId)) {
            return $this->failure($output, 8);
        }

        $push = new Push;
        $push->setId($this->unique->generatePushId());
        $push->setCreated($this->clock->read());
        $push->setStatus('Waiting');
        $push->setBuild($build);
        $push->setDeployment($deployment);
        $push->setRepository($build->getRepository());
        $push->setUser($user);

        $this->dupeCatcher($push);

        $this->entityManager->persist($push);
        $this->entityManager->flush();

        if ($input->getOption('porcelain')) {
            $output->writeln($push->getId());

        } else {
            $this->success($output, sprintf('Push created: %s', $push->getId()));
        }
    }

    /**
     * @param Push $push
     * @return null
     */
    private function dupeCatcher(Push $push)
    {
        $dupe = $this->pushRepo->findBy(['id' => [$push->getId()]]);
        if ($dupe) {
            $push->setId($this->unique->generatePushId());
            $this->dupeCatcher($push);
        }
    }

}
