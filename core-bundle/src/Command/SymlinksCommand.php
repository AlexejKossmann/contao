<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Command;

use Contao\CoreBundle\Analyzer\HtaccessAnalyzer;
use Contao\CoreBundle\Config\ResourceFinderInterface;
use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\GenerateSymlinksEvent;
use Contao\CoreBundle\Util\SymlinkUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Symlinks the public resources into the web directory.
 *
 * @internal
 */
class SymlinksCommand extends Command
{
    protected static $defaultName = 'contao:symlinks';

    /**
     * @var array
     */
    private $rows = [];

    /**
     * @var string
     */
    private $projectDir;

    /**
     * @var string
     */
    private $webDir;

    /**
     * @var string
     */
    private $uploadPath;

    /**
     * @var string
     */
    private $logsDir;

    /**
     * @var ResourceFinderInterface
     */
    private $resourceFinder;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var int
     */
    private $statusCode = 0;

    public function __construct(string $projectDir, string $uploadPath, string $logsDir, ResourceFinderInterface $resourceFinder, EventDispatcherInterface $eventDispatcher)
    {
        $this->projectDir = $projectDir;
        $this->uploadPath = $uploadPath;
        $this->logsDir = $logsDir;
        $this->resourceFinder = $resourceFinder;
        $this->eventDispatcher = $eventDispatcher;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'The target directory', 'web')
            ->setDescription('Symlinks the public resources into the web directory.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->webDir = rtrim($input->getArgument('target'), '/');

        $this->generateSymlinks();

        if (!empty($this->rows)) {
            $io = new SymfonyStyle($input, $output);
            $io->newLine();
            $io->table(['', 'Symlink', 'Target / Error'], $this->rows);
        }

        return $this->statusCode;
    }

    /**
     * Generates the symlinks in the web directory.
     */
    private function generateSymlinks(): void
    {
        $fs = new Filesystem();

        // Remove the base folders in the document root
        $fs->remove($this->projectDir.'/'.$this->webDir.'/'.$this->uploadPath);
        $fs->remove($this->projectDir.'/'.$this->webDir.'/system/modules');
        $fs->remove($this->projectDir.'/'.$this->webDir.'/vendor');

        $this->symlinkFiles($this->uploadPath);
        $this->symlinkModules();
        $this->symlinkThemes();

        // Symlink the assets and themes directory
        $this->symlink('assets', $this->webDir.'/assets');
        $this->symlink('system/themes', $this->webDir.'/system/themes');

        // Symlinks the logs directory
        $this->symlink($this->getRelativePath($this->logsDir), 'system/logs');

        $this->triggerSymlinkEvent();
    }

    private function symlinkFiles(string $uploadPath): void
    {
        $this->createSymlinksFromFinder(
            $this->findIn($this->projectDir.'/'.$uploadPath)->files()->depth('> 0')->name('.public'),
            $uploadPath
        );
    }

    private function symlinkModules(): void
    {
        $filter = static function (SplFileInfo $file): bool {
            return HtaccessAnalyzer::create($file)->grantsAccess();
        };

        $this->createSymlinksFromFinder(
            $this->findIn($this->projectDir.'/system/modules')->files()->filter($filter)->name('.htaccess'),
            'system/modules'
        );
    }

    private function symlinkThemes(): void
    {
        /** @var array<SplFileInfo> $themes */
        $themes = $this->resourceFinder->findIn('themes')->depth(0)->directories();

        foreach ($themes as $theme) {
            $path = $this->getRelativePath($theme->getPathname());

            if (0 === strncmp($path, 'system/modules/', 15)) {
                continue;
            }

            $this->symlink($path, 'system/themes/'.basename($path));
        }
    }

    private function createSymlinksFromFinder(Finder $finder, string $prepend): void
    {
        $files = $this->filterNestedPaths($finder, $prepend);

        foreach ($files as $file) {
            $path = rtrim($prepend.'/'.$file->getRelativePath(), '/');
            $this->symlink($path, $this->webDir.'/'.$path);
        }
    }

    private function triggerSymlinkEvent(): void
    {
        $event = new GenerateSymlinksEvent();

        $this->eventDispatcher->dispatch($event, ContaoCoreEvents::GENERATE_SYMLINKS);

        foreach ($event->getSymlinks() as $target => $link) {
            $this->symlink($target, $link);
        }
    }

    /**
     * The method will try to generate relative symlinks and fall back to generating
     * absolute symlinks if relative symlinks are not supported (see #208).
     */
    private function symlink(string $target, string $link): void
    {
        $target = strtr($target, '\\', '/');
        $link = strtr($link, '\\', '/');

        try {
            SymlinkUtil::symlink($target, $link, $this->projectDir);

            $this->rows[] = [
                sprintf(
                    '<fg=green;options=bold>%s</>',
                    '\\' === \DIRECTORY_SEPARATOR ? 'OK' : "\xE2\x9C\x94" // HEAVY CHECK MARK (U+2714)
                ),
                $link,
                $target,
            ];
        } catch (\Exception $e) {
            $this->statusCode = 1;

            $this->rows[] = [
                sprintf(
                    '<fg=red;options=bold>%s</>',
                    '\\' === \DIRECTORY_SEPARATOR ? 'ERROR' : "\xE2\x9C\x98" // HEAVY BALLOT X (U+2718)
                ),
                $link,
                sprintf('<error>%s</error>', $e->getMessage()),
            ];
        }
    }

    /**
     * Returns a finder instance to find files in the given path.
     */
    private function findIn(string $path): Finder
    {
        return Finder::create()
            ->ignoreDotFiles(false)
            ->sort(
                static function (SplFileInfo $a, SplFileInfo $b): int {
                    $countA = substr_count(strtr($a->getRelativePath(), '\\', '/'), '/');
                    $countB = substr_count(strtr($b->getRelativePath(), '\\', '/'), '/');

                    return $countA <=> $countB;
                }
            )
            ->followLinks()
            ->in($path)
        ;
    }

    /**
     * Filters nested paths so only the top folder is symlinked.
     *
     * @return array<SplFileInfo>
     */
    private function filterNestedPaths(Finder $finder, string $prepend): array
    {
        $parents = [];
        $files = iterator_to_array($finder);

        foreach ($files as $key => $file) {
            $path = rtrim(strtr($prepend.'/'.$file->getRelativePath(), '\\', '/'), '/');

            if (!empty($parents)) {
                $parent = \dirname($path);

                while (false !== strpos($parent, '/')) {
                    if (\in_array($parent, $parents, true)) {
                        $this->rows[] = [
                            sprintf(
                                '<fg=yellow;options=bold>%s</>',
                                '\\' === \DIRECTORY_SEPARATOR ? 'WARNING' : '!'
                            ),
                            $this->webDir.'/'.$path,
                            sprintf('<comment>Skipped because %s will be symlinked.</comment>', $parent),
                        ];

                        unset($files[$key]);
                        break;
                    }

                    $parent = \dirname($parent);
                }
            }

            $parents[] = $path;
        }

        return array_values($files);
    }

    private function getRelativePath(string $path): string
    {
        return str_replace(strtr($this->projectDir, '\\', '/').'/', '', strtr($path, '\\', '/'));
    }
}
