<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Image;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\FilesModel;
use Contao\Image\DeferredResizerInterface;
use Contao\Image\Image;
use Contao\Image\ImageInterface;
use Contao\Image\ImportantPart;
use Contao\Image\ResizeConfiguration;
use Contao\Image\ResizeOptions;
use Contao\Image\ResizerInterface;
use Contao\ImageSizeModel;
use Imagine\Image\ImagineInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

class ImageFactory implements ImageFactoryInterface
{
    /**
     * @var ResizerInterface
     */
    private $resizer;

    /**
     * @var ImagineInterface
     */
    private $imagine;

    /**
     * @var ImagineInterface
     */
    private $imagineSvg;

    /**
     * @var ContaoFramework
     */
    private $framework;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var bool
     */
    private $bypassCache;

    /**
     * @var array
     */
    private $imagineOptions;

    /**
     * @var array
     */
    private $validExtensions;

    /**
     * @var string
     */
    private $uploadDir;

    /**
     * @var array
     */
    private $predefinedSizes = [];

    /**
     * @var ?LoggerInterface
     */
    private $logger;

    /**
     * @internal Do not inherit from this class; decorate the "contao.image.image_factory" service instead
     */
    public function __construct(ResizerInterface $resizer, ImagineInterface $imagine, ImagineInterface $imagineSvg, Filesystem $filesystem, ContaoFramework $framework, bool $bypassCache, array $imagineOptions, array $validExtensions, string $uploadDir, ?LoggerInterface $logger = null)
    {
        $this->resizer = $resizer;
        $this->imagine = $imagine;
        $this->imagineSvg = $imagineSvg;
        $this->filesystem = $filesystem;
        $this->framework = $framework;
        $this->bypassCache = $bypassCache;
        $this->imagineOptions = $imagineOptions;
        $this->validExtensions = $validExtensions;
        $this->uploadDir = $uploadDir;
        $this->logger = $logger;
    }

    /**
     * Sets the predefined image sizes.
     */
    public function setPredefinedSizes(array $predefinedSizes): void
    {
        $this->predefinedSizes = $predefinedSizes;
    }

    public function create($path, $size = null, $options = null): ImageInterface
    {
        if (null !== $options && !\is_string($options) && !$options instanceof ResizeOptions) {
            throw new \InvalidArgumentException('Options must be of type null, string or '.ResizeOptions::class);
        }

        if ($path instanceof ImageInterface) {
            $image = $path;
        } else {
            $path = (string) $path;
            $fileExtension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (\in_array($fileExtension, ['svg', 'svgz'], true)) {
                $imagine = $this->imagineSvg;
            } else {
                $imagine = $this->imagine;
            }

            if (!\in_array($fileExtension, $this->validExtensions, true)) {
                throw new \InvalidArgumentException(sprintf('Image type "%s" was not allowed to be processed', $fileExtension));
            }

            if (!$this->filesystem->isAbsolutePath($path)) {
                throw new \InvalidArgumentException(sprintf('Image path "%s" must be absolute', $path));
            }

            if (
                $this->resizer instanceof DeferredResizerInterface
                && !$this->filesystem->exists($path)
                && $deferredImage = $this->resizer->getDeferredImage($path, $imagine)
            ) {
                $image = $deferredImage;
            } else {
                $image = new Image($path, $imagine, $this->filesystem);
            }
        }

        $targetPath = $options instanceof ResizeOptions ? $options->getTargetPath() : $options;

        if ($size instanceof ResizeConfiguration) {
            $resizeConfig = $size;
            $importantPart = null;
        } else {
            [$resizeConfig, $importantPart, $options] = $this->createConfig($size, $image);
        }

        if (!\is_object($path) || !$path instanceof ImageInterface) {
            if (null === $importantPart) {
                $importantPart = $this->createImportantPart($image);
            }

            $image->setImportantPart($importantPart);
        }

        if (null === $options && null === $targetPath && null === $size) {
            return $image;
        }

        if (!$options instanceof ResizeOptions) {
            $options = new ResizeOptions();

            if (!$size instanceof ResizeConfiguration && $resizeConfig->isEmpty()) {
                $options->setSkipIfDimensionsMatch(true);
            }
        }

        if (null !== $targetPath) {
            $options->setTargetPath($targetPath);
        }

        if (!$options->getImagineOptions()) {
            $options->setImagineOptions($this->imagineOptions);
        }

        $options->setBypassCache($options->getBypassCache() || $this->bypassCache);

        return $this->resizer->resize($image, $resizeConfig, $options);
    }

    public function getImportantPartFromLegacyMode(ImageInterface $image, $mode): ImportantPart
    {
        if (1 !== substr_count($mode, '_')) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a legacy resize mode', $mode));
        }

        $importantPart = [0, 0, 1, 1];
        [$modeX, $modeY] = explode('_', $mode);

        if ('left' === $modeX) {
            $importantPart[2] = 0;
        } elseif ('right' === $modeX) {
            $importantPart[0] = 1;
            $importantPart[2] = 0;
        }

        if ('top' === $modeY) {
            $importantPart[3] = 0;
        } elseif ('bottom' === $modeY) {
            $importantPart[1] = 1;
            $importantPart[3] = 0;
        }

        return new ImportantPart($importantPart[0], $importantPart[1], $importantPart[2], $importantPart[3]);
    }

    /**
     * Creates a resize configuration object.
     *
     * @param int|array|null $size An image size or an array with width, height and resize mode
     *
     * @return array<(ResizeConfiguration|ImportantPart|ResizeOptions|null)>
     */
    private function createConfig($size, ImageInterface $image): array
    {
        if (!\is_array($size)) {
            $size = [0, 0, $size];
        }

        $config = new ResizeConfiguration();
        $options = new ResizeOptions();

        if (isset($size[2])) {
            // Database record
            if (is_numeric($size[2])) {
                /** @var ImageSizeModel $imageModel */
                $imageModel = $this->framework->getAdapter(ImageSizeModel::class);

                if (null !== ($imageSize = $imageModel->findByPk($size[2]))) {
                    $this->enhanceResizeConfig($config, $imageSize->row());
                    $options->setSkipIfDimensionsMatch((bool) $imageSize->skipIfDimensionsMatch);
                }

                return [$config, null, $options];
            }

            // Predefined sizes
            if (isset($this->predefinedSizes[$size[2]])) {
                $this->enhanceResizeConfig($config, $this->predefinedSizes[$size[2]]);
                $options->setSkipIfDimensionsMatch($this->predefinedSizes[$size[2]]['skipIfDimensionsMatch'] ?? false);

                return [$config, null, $options];
            }
        }

        if (!empty($size[0])) {
            $config->setWidth((int) $size[0]);
        }

        if (!empty($size[1])) {
            $config->setHeight((int) $size[1]);
        }

        if (!isset($size[2]) || 1 !== substr_count($size[2], '_')) {
            if (!empty($size[2])) {
                $config->setMode($size[2]);
            }

            return [$config, null, null];
        }

        $config->setMode(ResizeConfiguration::MODE_CROP);

        return [$config, $this->getImportantPartFromLegacyMode($image, $size[2]), null];
    }

    /**
     * Enhances the resize configuration with the image size settings.
     */
    private function enhanceResizeConfig(ResizeConfiguration $config, array $imageSize): void
    {
        if (isset($imageSize['width'])) {
            $config->setWidth((int) $imageSize['width']);
        }

        if (isset($imageSize['height'])) {
            $config->setHeight((int) $imageSize['height']);
        }

        if (isset($imageSize['zoom'])) {
            $config->setZoomLevel((int) $imageSize['zoom']);
        }

        if (isset($imageSize['resizeMode'])) {
            $config->setMode((string) $imageSize['resizeMode']);
        }
    }

    /**
     * Fetches the important part from the database.
     */
    private function createImportantPart(ImageInterface $image): ?ImportantPart
    {
        if (0 !== strncmp($image->getPath(), $this->uploadDir.'/', \strlen($this->uploadDir) + 1)) {
            return null;
        }

        if (!$this->framework->isInitialized()) {
            throw new \RuntimeException('Contao framework was not initialized');
        }

        /** @var FilesModel $filesModel */
        $filesModel = $this->framework->getAdapter(FilesModel::class);
        $file = $filesModel->findByPath($image->getPath());

        if (null === $file || !$file->importantPartWidth || !$file->importantPartHeight) {
            return null;
        }

        // Larger values are considered to be in the old format (in absolute
        // pixels) so we try to convert them to the new format if possible.
        // Because of rounding errors, the values of the new format might slightly
        // exceed 1.0, this is why we check for ">= 2" to detect the old format.
        if (
            (float) $file->importantPartX + (float) $file->importantPartWidth >= 2
            || (float) $file->importantPartY + (float) $file->importantPartHeight >= 2
        ) {
            @trigger_error(sprintf('Defining the important part in absolute pixels has been deprecated and will no longer work in Contao 5.0. Run the database migration to migrate to the new format.'), E_USER_DEPRECATED);

            if ($this->logger) {
                $this->logger->warning(
                    sprintf(
                        'Invalid important part x=%s, y=%s, width=%s, height=%s for image "%s".',
                        $file->importantPartX,
                        $file->importantPartY,
                        $file->importantPartWidth,
                        $file->importantPartHeight,
                        $image->getPath()
                    ),
                    ['contao' => new ContaoContext(__METHOD__, ContaoContext::ERROR)]
                );
            }

            try {
                $size = $image->getDimensions()->getSize();

                return new ImportantPart(
                    (float) $file->importantPartX / $size->getWidth(),
                    (float) $file->importantPartY / $size->getHeight(),
                    (float) $file->importantPartWidth / $size->getWidth(),
                    (float) $file->importantPartHeight / $size->getHeight()
                );
            } catch (\Throwable $exception) {
                return new ImportantPart();
            }
        }

        return new ImportantPart(
            (float) $file->importantPartX,
            (float) $file->importantPartY,
            (float) $file->importantPartWidth,
            (float) $file->importantPartHeight
        );
    }
}
