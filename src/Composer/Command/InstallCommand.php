<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Command;

use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Installer;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Util\HttpDownloader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Ryan Weaver <ryan@knplabs.com>
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 */
class InstallCommand extends BaseCommand
{
    /**
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('install')
            ->setAliases(array('i'))
            ->setDescription('Installs the project dependencies from the composer.lock file if present, or falls back on the composer.json.')
            ->setDefinition(array(
                new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
                new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist (default behavior).'),
                new InputOption('prefer-install', null, InputOption::VALUE_REQUIRED, 'Forces installation from package dist|source|auto (auto chooses source for dev versions, dist for the rest).'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Outputs the operations but will not execute anything (implicitly enables --verbose).'),
                new InputOption('dev', null, InputOption::VALUE_NONE, 'DEPRECATED: Enables installation of require-dev packages (enabled by default, only present for BC).'),
                new InputOption('no-suggest', null, InputOption::VALUE_NONE, 'DEPRECATED: This flag does not exist anymore.'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables installation of require-dev packages.'),
                new InputOption('no-autoloader', null, InputOption::VALUE_NONE, 'Skips autoloader generation'),
                new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
                new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
                new InputOption('no-install', null, InputOption::VALUE_NONE, 'Do not use, only defined here to catch misuse of the install command.'),
                new InputOption('verbose', 'v|vv|vvv', InputOption::VALUE_NONE, 'Shows more details including new commits pulled in when updating packages.'),
                new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump'),
                new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
                new InputOption('apcu-autoloader', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
                new InputOption('apcu-autoloader-prefix', null, InputOption::VALUE_REQUIRED, 'Use a custom prefix for the APCu autoloader cache. Implicitly enables --apcu-autoloader'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages).'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages).'),
                new InputArgument('packages', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Should not be provided, use composer require instead to add a given package to composer.json.'),
            ))
            ->setHelp(
                <<<EOT
The <info>install</info> command reads the composer.lock file from
the current directory, processes it, and downloads and installs all the
libraries and dependencies outlined in that file. If the file does not
exist it will look for composer.json and do the same.

<info>php composer.phar install</info>

Read more at https://getcomposer.org/doc/03-cli.md#install-i
EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = $this->getIO();
        if ($input->getOption('dev')) {
            $io->writeError('<warning>You are using the deprecated option "--dev". It has no effect and will break in Composer 3.</warning>');
        }
        if ($input->getOption('no-suggest')) {
            $io->writeError('<warning>You are using the deprecated option "--no-suggest". It has no effect and will break in Composer 3.</warning>');
        }

        if ($args = $input->getArgument('packages')) {
            $io->writeError('<error>Invalid argument '.implode(' ', $args).'. Use "composer require '.implode(' ', $args).'" instead to add packages to your composer.json.</error>');

            return 1;
        }

        if ($input->getOption('no-install')) {
            $io->writeError('<error>Invalid option "--no-install". Use "composer update --no-install" instead if you are trying to update the composer.lock file.</error>');

            return 1;
        }

        $composer = $this->getComposer(true, $input->getOption('no-plugins'));
        $composer->getEventDispatcher()->setRunScripts(!$input->getOption('no-scripts'));

        if ((!$composer->getLocker() || !$composer->getLocker()->isLocked()) && !HttpDownloader::isCurlEnabled()) {
            $io->writeError('<warning>Composer is operating significantly slower than normal because you do not have the PHP curl extension enabled.</warning>');
        }

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'install', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $install = Installer::create($io, $composer);

        $config = $composer->getConfig();
        list($preferSource, $preferDist) = $this->getPreferredInstallOptions($config, $input);

        $optimize = $input->getOption('optimize-autoloader') || $config->get('optimize-autoloader');
        $authoritative = $input->getOption('classmap-authoritative') || $config->get('classmap-authoritative');
        $apcuPrefix = $input->getOption('apcu-autoloader-prefix');
        $apcu = $apcuPrefix !== null || $input->getOption('apcu-autoloader') || $config->get('apcu-autoloader');

        $ignorePlatformReqs = $input->getOption('ignore-platform-reqs') ?: ($input->getOption('ignore-platform-req') ?: false);

        $composer->getInstallationManager()->setOutputProgress(!$input->getOption('no-progress'));

        $install
            ->setDryRun($input->getOption('dry-run'))
            ->setVerbose($input->getOption('verbose'))
            ->setPreferSource($preferSource)
            ->setPreferDist($preferDist)
            ->setDevMode(!$input->getOption('no-dev'))
            ->setDumpAutoloader(!$input->getOption('no-autoloader'))
            ->setOptimizeAutoloader($optimize)
            ->setClassMapAuthoritative($authoritative)
            ->setApcuAutoloader($apcu, $apcuPrefix)
            ->setPlatformRequirementFilter(PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs))
        ;

        if ($input->getOption('no-plugins')) {
            $install->disablePlugins();
        }

        return $install->run();
    }
}
