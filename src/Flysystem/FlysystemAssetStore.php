<?php
namespace ShowPro\ImageOptimiser\Flysystem;

use League\Flysystem\Filesystem;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore as SS_FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use Spatie\ImageOptimizer\OptimizerChain;
use WebPConvert\Convert\Exceptions\ConversionFailedException;
use WebPConvert\WebPConvert;

/**
 * Optimised Flysystem AssetStore
 * ==============================
 *
 * Extends SilverStripe\Assets\Flysystem\FlysystemAssetStore
 * to automatically optimise files prior to storage.
 *
 * @license: MIT-style license http://opensource.org/licenses/MIT
 * @author:  Techno Joy development team (www.technojoy.co.nz)
 */
class FlysystemAssetStore extends SS_FlysystemAssetStore
{
    use Configurable;

    /**
     * Default Image Optimizer config
     *
     * @var array
     */
    private static $chains = [
        'Spatie\ImageOptimizer\Optimizers\Jpegoptim' => [
            '--max=85',
            '--all-progressive',
        ],
        'Spatie\ImageOptimizer\Optimizers\Pngquant'  => [
            '--force',
        ],
        'Spatie\ImageOptimizer\Optimizers\Optipng'   => [
            '-i0',
            '-o2',
            '-quiet',
        ],
        'Spatie\ImageOptimizer\Optimizers\Gifsicle'  => [
            '-b',
            '-O3',
        ],
    ];

    private static $webp_default_quality = 80;

    public function __construct()
    {
        $this->webp_quality = $this->config()->webp_default_quality;
    }

    /**
     * Asset Store file from local file Optimize file after upload
     *
     * @param String $path     Local path
     * @param String $filename Optional filename
     * @param String $hash     Optional hash
     * @param String $variant  Optional variant
     * @param Array  $config   Optional config options
     *
     * @return void
     */
    public function setFromLocalFile($path, $filename = null, $hash = null, $variant = null, $config = [])
    {
        $this->_optimisePath($path, $filename);

        return parent::setFromLocalFile($path, $filename, $hash, $variant, $config);
    }


    /**
     * Asset Store file from string
     *
     * @param String $data     File string
     * @param String $filename Optional file name
     * @param String $hash     Optional hash
     * @param String $variant  Optional variant
     * @param Array  $config   Optional config options
     *
     * @return void
     */
    public function setFromString($data, $filename, $hash = null, $variant = null, $config = [])
    {
        if ($filename) {
            $extension = substr(strrchr($filename, '.'), 1);
            $tmp_file  = TEMP_PATH . DIRECTORY_SEPARATOR . 'raw_' . uniqid() . '.' . $extension;
            file_put_contents($tmp_file, $data);
            $this->_optimisePath($tmp_file, $filename);

            $fileID = $this->getFileID($filename, $hash);
            if ($this->getPublicFilesystem()->has($fileID)) {
                $this->createWebPImage( $tmp_file, $filename, $hash, $variant, $config );
            }

            $data = file_get_contents($tmp_file);
            unlink($tmp_file);
        }


        return parent::setFromString($data, $filename, $hash, $variant, $config);
    }


    /**
     * Move a file and its associated variant from one file store to another adjusting the file name format.
     * @param ParsedFileID $parsedFileID
     * @param Filesystem $from
     * @param FileResolutionStrategy $fromStrategy
     * @param Filesystem $to
     * @param FileResolutionStrategy $toStrategy
     */
    protected function moveBetweenFileStore(
        ParsedFileID $parsedFileID,
        Filesystem $from,
        FileResolutionStrategy $fromStrategy,
        Filesystem $to,
        FileResolutionStrategy $toStrategy,
        $swap = false
    ) {
        /** @var FileHashingService $hasher */
        $hasher = Injector::inst()->get(FileHashingService::class);

        // Let's find all the variants on the origin store ... those need to be moved to the destination
        /** @var ParsedFileID $variantParsedFileID */
        foreach ($fromStrategy->findVariants($parsedFileID, $from) as $variantParsedFileID) {
            // Copy via stream
            $fromFileID = $variantParsedFileID->getFileID();
            $toFileID = $toStrategy->buildFileID($variantParsedFileID);

            $stream = $from->readStream($fromFileID);
            $to->putStream($toFileID, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            // Remove the origin file and keep the file ID
            $idsToDelete[] = $fromFileID;
            $from->delete($fromFileID);

            $hasher->move($fromFileID, $from, $toFileID, $to);
            $this->truncateDirectory(dirname($fromFileID), $from);

            $copyFrom = $from->getConfig();
            if($copyFrom->get('visibility')!='public'){
                $this->createWebPImage(
                    PUBLIC_PATH.'/assets/'.$variantParsedFileID->getFilename(),
                    $variantParsedFileID->getFilename(),
                    $variantParsedFileID->getHash(),
                    $variantParsedFileID->getVariant(),
                    [] );
                //$this->createWebPImageFromFile($toFileID, $variantParsedFileID->getHash());
            }else{
                $orgpath = $this->createWebPName('.//assets/'.$fromFileID);
                if (file_exists($orgpath)) {
                    $foo = unlink( $orgpath  );
                }

            }

        }
    }


    /**
     * Optimise a file path
     * Silently ignores unsupported filetypes
     *
     * @param String $path     Path to file
     * @param String $filename File name
     *
     * @return void
     */
    private function _optimisePath($path, $filename = null)
    {
        if (!$filename) {
            // we do not know the name, so probably cannot
            // identfy what file it actually is, skip processing
            return;
        }

        $extension = strtolower(substr(strrchr($filename, '.'), 1));

        $tmp_file = TEMP_PATH . DIRECTORY_SEPARATOR . 'optim_' . uniqid() . '.' . $extension;

        copy($path, $tmp_file);

        $chains = $this->config()->get('chains');

        // create optimizer
        $optimizer = new OptimizerChain;
        foreach ($chains as $class => $options) {
            $optimizer->addOptimizer(
                new $class($options)
            );
        }

        $optimizer->optimize($tmp_file);

        $raw_size   = filesize($path);
        $optim_size = filesize($tmp_file);

        if ($raw_size > $optim_size && $optim_size > 0) {
            // print "$filename = $raw_size:$optim_size, ";
            $raw = file_get_contents($tmp_file);
            file_put_contents($path, $raw);
        }

        unlink($tmp_file);
    }


    /**
     * @param $path
     * @param $filename
     * @param $hash
     * @param bool $variant
     */
    public function createWebPImage($path, $filename, $hash, $variant = false)
    {
        if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng')) {
            $orgpath = './'.$this->getAsURL($filename, $hash, $variant);
            $destination = $this->createWebPName($orgpath);
            $options = [];

            WebPConvert::convert( $path, $destination, $options );

        }
    }

    /**
     * @param $filename
     *
     * @return string
     */
    public function createWebPName($filename)
    {
        $picname = pathinfo($filename, PATHINFO_FILENAME);
        $directory = pathinfo($filename, PATHINFO_DIRNAME);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        return $directory.'/'.$picname.'.'.$extension.'.webp';
    }

}
