<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

class ConfigurationReader
{
    const FOUND = 'Found .hal9000.yml configuration';
    const ERR_INVALID_YAML = '.hal9000.yml was invalid';
    const ERR_INVALID_KEY = '.hal9000.yml configuration key "%s" is invalid';
    const ERR_TOO_MANY_COOKS = 'Too many commands specified for "%s". Must be less than 10.';

    /**
     * @var string
     */
    const FS_CONFIG_FILE = '.hal9000.yml';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var callable
     */
    private $fileLoader;

    /**
     * @param EventLogger $logger
     * @param Filesystem $filesystem
     * @param Parser $parser
     * @param callable $fileLoader
     */
    public function __construct(
        EventLogger $logger,
        Filesystem $filesystem,
        Parser $parser,
        callable $fileLoader = null
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->parser = $parser;

        if ($fileLoader === null) {
            $fileLoader = $this->getDefaultFileLoader();
        }

        $this->fileLoader = $fileLoader;
    }

    /**
     * @param string $buildPath
     * @param array $config
     *
     * @return bool
     */
    public function __invoke($buildPath, array &$config)
    {
        $configFile = rtrim($buildPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::FS_CONFIG_FILE;

        if (!$this->filesystem->exists($configFile)) {
            return true;
        }

        $file = call_user_func($this->fileLoader, $configFile);
        $context = ['file' => $file];

        try {
            $yaml = $this->parser->parse($file);
        } catch (ParseException $e) {
            $this->logger->event('failure', self::ERR_INVALID_YAML);
            return false;
        }

        if (!is_array($yaml)) {
            $this->logger->event('failure', self::ERR_INVALID_YAML);
            return false;
        }

        // load system
        if (array_key_exists('system', $yaml) && $yaml['system']) {
            if (!is_scalar($yaml['system'])) {
                $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, 'system'), $context);
                return false;
            }

            $config['system'] = (string) trim($yaml['system']);
        }

        // load dist
        if (array_key_exists('dist', $yaml) && $yaml['dist']) {
            if (!is_scalar($yaml['dist'])) {
                $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, 'dist'), $context);
                return false;
            }

            $config['dist'] = (string) trim($yaml['dist']);
        }

        $parsed = ['exclude', 'build', 'build_transform', 'pre_push', 'deploy', 'post_push'];

        foreach ($parsed as $p) {
            $config[$p] = $this->validateList($yaml, $p, $context);

            // If any of the lists are null, an error occured.
            if ($config[$p] === null) {
                return false;
            }
        }

        $context['configuration'] = $config;
        $this->logger->event('success', self::FOUND, $context);
        return true;
    }

    /**
     * @param array $yaml
     * @param string $key
     * @param array $context
     *
     * @return array|null
     */
    private function validateList(array $yaml, $key, array $context)
    {
        if (!array_key_exists($key, $yaml)) {
            return [];
        }

        $commands = $yaml[$key];
        if (!is_array($commands)) {
            $commands = [$commands];
        }

        // # of commands must be <=10
        if (count($commands) > 10) {
            $this->logger->event('failure', sprintf(self::ERR_TOO_MANY_COOKS, $key), $context);
            return null;
        }

        $sanitized = [];
        foreach ($commands as $command) {
            if (is_scalar($command) || is_null($command)) {
                $command = (string) $command;
            } else {
                // blow the fuck up
                $this->logger->event('failure', sprintf(self::ERR_INVALID_KEY, $key), $context);
                return null;
            }

            if ($command) {
                $sanitized[] = trim($command);
            }
        }

        return $sanitized;
    }

    private function getDefaultFileLoader()
    {
        return 'file_get_contents';
    }
}
