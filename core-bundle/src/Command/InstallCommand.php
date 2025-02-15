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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Installs the required Contao directories.
 *
 * @internal
 */
class InstallCommand extends Command
{
    protected static $defaultName = 'contao:install';

    /**
     * @var Filesystem
     */
    private $fs;

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
    private $uploadPath;

    /**
     * @var string
     */
    private $imageDir;

    /**
     * @var string
     */
    private $webDir;

    public function __construct(string $projectDir, string $uploadPath, string $imageDir)
    {
        $this->projectDir = $projectDir;
        $this->uploadPath = $uploadPath;
        $this->imageDir = $imageDir;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'The target directory', 'web')
            ->setDescription('Installs the required Contao directories')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->fs = new Filesystem();
        $this->webDir = rtrim($input->getArgument('target'), '/');

        $this->addEmptyDirs();

        if (!empty($this->rows)) {
            $io = new SymfonyStyle($input, $output);
            $io->newLine();
            $io->listing($this->rows);
        }

        return 0;
    }

    private function addEmptyDirs(): void
    {
        static $emptyDirs = [
            'assets/css',
            'assets/js',
            'system',
            'system/cache',
            'system/config',
            'system/modules',
            'system/themes',
            'system/tmp',
            'templates',
            '%s/share',
            '%s/system',
        ];

        foreach ($emptyDirs as $path) {
            $this->addEmptyDir($this->projectDir.'/'.sprintf($path, $this->webDir));
        }

        $this->addEmptyDir($this->imageDir);
        $this->addEmptyDir($this->projectDir.'/'.$this->uploadPath);
    }

    private function addEmptyDir(string $path): void
    {
        if ($this->fs->exists($path)) {
            return;
        }

        $this->fs->mkdir($path);

        $this->rows[] = str_replace($this->projectDir.'/', '', $path);
    }
}
