<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\LoggerInterface;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Repository\BuildRepository;

/**
 * Resolve build properties from user and environment input
 */
class Resolver
{
    /**
     * @var string
     */
    const FS_DIRECTORY_PREFIX = 'hal9000-build-%s';
    const FS_BUILD_PREFIX = 'hal9000-build-%s.tar.gz';
    const FS_ARCHIVE_PREFIX = 'hal9000-%s.tar.gz';

    /**
     * @var string
     */
    const FOUND = 'Found build: %s';
    const ERR_NOT_FOUND = 'Build "%s" could not be found!';
    const ERR_NOT_WAITING = 'Build "%s" has a status of "%s"! It cannot be rebuilt.';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var string
     */
    private $envPath;

    /**
     * @var string
     */
    private $archivePath;

    /**
     * @var string
     */
    private $buildDirectory;

    /**
     * @var string
     */
    private $homeDirectory;

    /**
     * @param LoggerInterface $logger
     * @param BuildRepository $buildRepo
     * @param string $envPath
     * @param string $archivePath
     */
    public function __construct(LoggerInterface $logger, BuildRepository $buildRepo, $envPath, $archivePath)
    {
        $this->logger = $logger;
        $this->buildRepo = $buildRepo;
        $this->envPath = $envPath;
        $this->archivePath = $archivePath;
    }

    /**
     * @param string $buildId
     * @return array|null
     */
    public function __invoke($buildId)
    {
        if (!$build = $this->buildRepo->find($buildId)) {
            $this->logger->error(sprintf(self::ERR_NOT_FOUND, $buildId));
            return null;
        }

        $this->logger->info(sprintf(self::FOUND, $buildId));

        if ($build->getStatus() !== 'Waiting') {
            $this->logger->error(sprintf(self::ERR_NOT_WAITING, $buildId, $build->getStatus()));
            return null;
        }

        $properties = [
            'build' => $build,
            'buildCommand' => $build->getRepository()->getBuildCmd(),

            'buildFile' => $this->generateRepositoryDownload($build->getId()),
            'buildPath' => $this->generateBuildPath($build->getId()),
            'archiveFile' => $this->generateBuildArchive($build->getId()),

            'githubUser' => $build->getRepository()->getGithubUser(),
            'githubRepo' => $build->getRepository()->getGithubRepo(),
            'githubReference' => $build->getCommit(),

            'environmentVariables' => $this->generateBuildEnvironmentVariables($build)
        ];

        $properties['artifacts'] = $this->findBuildArtifacts($properties);

        $this->logger->info('Resolved build properties', $properties);
        return $properties;
    }

    /**
     * Set the base directory in which temporary build artifacts are stored.
     *
     * If none is provided the system temporary directory is used.
     *
     * @param string $directory
     *  @return null
     */
    public function setBaseBuildDirectory($directory)
    {
        $this->buildDirectory = $directory;
    }

    /**
     * Set the home directory for all build scripts. This can easily be changed
     * later to be unique for each build.
     *
     * If none is provided a common location within the shared build directory is used.
     *
     *  @param string $directory
     *  @return string
     */
    public function setHomeDirectory($directory)
    {
        $this->homeDirectory = $directory;
    }

    /**
     * Find the build artifacts that must be cleaned up after build.
     *
     * @param array $properties
     * @return array
     */
    private function findBuildArtifacts(array $properties)
    {
        $artifacts = [
            $properties['buildFile'],
            $properties['buildPath']
        ];

        $caches = [
            'BOWER_STORAGE__CACHE',
            'BOWER_STORAGE__PACKAGES',
            'BOWER_TMP',
            'COMPOSER_CACHE_DIR',
            'NPM_CONFIG_CACHE'
        ];

        foreach ($caches as $cache) {
            if (isset($properties['environmentVariables'][$cache])) {
                $artifacts[] = $properties['environmentVariables'][$cache];
            }
        }

        // Add $HOME if this is an isolated build
        // For the love of all that is holy $HOME better be set to a build specific directory!
        if (false) {
        // if ($properties['build']->getRepository()->isIsolated()) {
            $artifacts[] = $properties['HOME'];
        }

        return $artifacts;
    }

    /**
     *  Generate a target for the build archive.
     *
     *  @param string $id
     *  @return string
     */
    private function generateRepositoryDownload($id)
    {
        return $this->getBuildDirectory() . sprintf(self::FS_BUILD_PREFIX, $id);
    }

    /**
     *  Generate a target for the build path.
     *
     *  @param string $id
     *  @return string
     */
    private function generateBuildPath($id)
    {
        return $this->getBuildDirectory() . sprintf(self::FS_DIRECTORY_PREFIX, $id);
    }

    /**
     *  Generate a target for the github repository archive.
     *
     *  @param string $id
     *  @return string
     */
    private function generateBuildArchive($id)
    {
        return sprintf(
            '%s%s%s',
            rtrim($this->archivePath, '/'),
            DIRECTORY_SEPARATOR,
            sprintf(self::FS_ARCHIVE_PREFIX, $id)
        );
    }

    /**
     *  Generate a target for $HOME and/or $TEMP with an optional suffix for uniqueness
     *
     *  @param string $suffix
     *  @return string
     */
    private function generateHomePath($suffix = '')
    {
        if (!$this->homeDirectory) {
            $this->homeDirectory = $this->getBuildDirectory() . 'home';
        }

        $suffix = (strlen($suffix) > 0) ? sprintf('.%s', $suffix) : '';

        return rtrim($this->homeDirectory, DIRECTORY_SEPARATOR) . $suffix . DIRECTORY_SEPARATOR;
    }

    /**
     *  @param string $id
     *  @return string
     */
    private function getBuildDirectory()
    {
        if (!$this->buildDirectory) {
            $this->buildDirectory = sys_get_temp_dir();
        }

        return rtrim($this->buildDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * @param Build $build
     * @return array
     */
    private function generateBuildEnvironmentVariables(Build $build)
    {
        $vars = [
            'HOME' => $this->generateHomePath($build->getRepository()->getId()),
            'PATH' => $this->envPath,

            'HAL_BUILDID' => $build->getId(),
            'HAL_COMMIT' => $build->getCommit(),
            'HAL_GITREF' => $build->getBranch(),
            'HAL_ENVIRONMENT' => $build->getEnvironment()->getKey(),
            'HAL_REPO' => $build->getRepository()->getKey()
        ];

        // add package manager configuration
        $vars = array_merge($vars, [
            'BOWER_INTERACTIVE' => 'false',
            'BOWER_STRICT_SSL' => 'false',

            'COMPOSER_HOME' => $vars['HOME'],
            'COMPOSER_NO_INTERACTION' => '1',

            'NPM_CONFIG_STRICT_SSL' => 'false',

            // wheres gems are installed
            'GEM_HOME' => $vars['HOME'] . '.gem/local',

            // where gems are searched for
            'GEM_PATH' => $vars['HOME'] . '.gem/local'
        ]);

        if ($gemPath = exec('gem env gempath')) {
            $vars['GEM_PATH'] = $vars['GEM_PATH'] . ':' . $gemPath;
        }

        // add package manager configuration for isolated builds
        if (false) {
        // if ($build->getRepository()->isIsolated()) {
            $buildPath = $this->generateBuildPath($build->getId());
            $vars = array_merge($vars, [
                # DEFAULT = ???, version < 1.0.0
                'BOWER_STORAGE__CACHE' => $buildPath . '-bower-cache',

                # DEFAULT = ???, version >= 1.0.0
                'BOWER_STORAGE__PACKAGES' => $buildPath . '-bower-cache',

                # DEFAULT = $TEMP/bower
                'BOWER_TMP' => $buildPath . '-bower',

                # DEFAULT = $COMPOSER_HOME/cache
                'COMPOSER_CACHE_DIR' => $buildPath . '-composer-cache',

                # DEFAULT = $HOME/.npm
                'NPM_CONFIG_CACHE' =>  $buildPath . '-npm-cache',

                'HOME' =>  $buildPath . '-home'
            ]);
        }

        return $vars;
    }
}
