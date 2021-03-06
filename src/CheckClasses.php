<?php

namespace Imanghafoori\LaravelMicroscope;

use Illuminate\Support\Str;
use Imanghafoori\LaravelMicroscope\Contracts\FileCheckContract;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Finder\Finder;

class CheckClasses
{
    protected static $fixedNamespaces = [];

    /**
     * Get all of the listeners and their corresponding events.
     *
     * @param  iterable  $files
     * @param  string  $basePath
     *
     * @param $composerPath
     * @param $composerNamespace
     *
     * @param  FileCheckContract  $fileCheckContract
     *
     * @return void
     */
    public static function checkImports($files, FileCheckContract $fileCheckContract)
    {
        foreach ($files as $classFilePath) {
            if ($fileCheckContract) {
                $fileCheckContract->onFileTap($classFilePath);
            }

            $absFilePath = $classFilePath->getRealPath();

            $tokens = token_get_all(file_get_contents($absFilePath));

            // If file is empty or does not begin with <?php
            if (($tokens[0][0] ?? null) !== T_OPEN_TAG) {
                continue;
            }

            [
                $currentNamespace,
                $class,
                $type,
                $parent,
                $interfaces
            ] = GetClassProperties::readClassDefinition($tokens);

            // It means that, there is no class/trait definition found in the file.
            if (! $class) {
                continue;
            }

            event('laravel_microscope.checking_file', [$absFilePath]);
            // better to do it an event listener.
            $fileCheckContract->onFileTap($classFilePath);

            $tokens = token_get_all(file_get_contents($absFilePath));
            $nonImportedClasses = ParseUseStatement::findClassReferences($tokens, $absFilePath);
            foreach ($nonImportedClasses as $nonImportedClass) {
                $v = trim($nonImportedClass['class'], '\\');
                if (! class_exists($v) && ! trait_exists($v) && ! interface_exists($v) && ! function_exists($v)) {
                    app(ErrorPrinter::class)->wrongUsedClassError($absFilePath, $nonImportedClass);
                }
            }

            try {
//                $classPath = self::relativePath($basePath, $absFilePath);
//                $correctNamespace = NamespaceCorrector::calculateCorrectNamespace($classPath, $composerPath, $composerNamespace);

                if ($currentNamespace) {
                    $namespacedClassName = $currentNamespace.'\\'.$class;
                } else {
                    $namespacedClassName = $class;
                }

                $imports = ParseUseStatement::getUseStatementsByPath($namespacedClassName, $absFilePath);
                self::checkImportedClasses($imports, $absFilePath);

                if ($currentNamespace) {
                    $ref = new ReflectionClass($currentNamespace.'\\'.$class);
                    ModelRelations::checkModelsRelations($currentNamespace.'\\'.$class, $ref);
                } else {
                    // @todo show skipped file...
                }
            } catch (ReflectionException $e) {
                // @todo show skipped file...
            }
        }
    }

    /**
     * Get all of the listeners and their corresponding events.
     *
     * @param  iterable  $paths
     * @param $composerPath
     * @param $composerNamespace
     *
     * @param  FileCheckContract  $fileCheckContract
     *
     * @return void
     */
    public static function checkAllClasses($paths, $composerPath, $composerNamespace, FileCheckContract $fileCheckContract)
    {
        foreach ($paths as $classFilePath) {
            if ($fileCheckContract) {
                $fileCheckContract->onFileTap($classFilePath);
            }

            $absFilePath = $classFilePath->getRealPath();

            // exclude blade files
            if (Str::endsWith($absFilePath, ['.blade.php'])) {
                continue;
            }

            // exclude migration directories
            if (Str::startsWith($absFilePath, self::migrationPaths())) {
                continue;
            }

            if (! self::hasOpeningTag($absFilePath)) {
                continue;
            }

            if ($fileCheckContract) {
                $fileCheckContract->onFileTap($classFilePath);
            }

            [
                $currentNamespace,
                $class,
                $type,
                $parent
            ] = GetClassProperties::fromFilePath($absFilePath);

            // skip if there is no class/trait/interface definition found.
            // for example a route file or a config file.
            if (! $class) {
                continue;
            }

            $relativePath = self::getRelativePath($absFilePath);
            $correctNamespace = NamespaceCorrector::calculateCorrectNamespace($relativePath, $composerPath, $composerNamespace);
            if ($currentNamespace !== $correctNamespace) {
                self::doNamespaceCorrection($correctNamespace, $relativePath, $currentNamespace, $absFilePath);
            }
        }
    }

    public static function hasOpeningTag($file)
    {
        $fp = fopen($file, 'r');

        if (feof($fp)) {
            return false;
        }

        $buffer = fread($fp, 20);
        fclose($fp);

        return Str::startsWith($buffer, '<?php');
    }

    /**
     * Calculate the namespace\className from absolute file path.
     *
     * @param  string  $filePath
     * @param  string  $basePath
     *
     * @param $path
     * @param $rootNamespace
     *
     * @return string
     */
    protected static function calculateClassFromFile($filePath, $basePath, $path, $rootNamespace)
    {
        $class = trim(Str::replaceFirst($basePath, '', $filePath), DIRECTORY_SEPARATOR);

        // remove .php from class path
        $withoutDotPhp = Str::replaceLast('.php', '', $class);
        // ensure backslash on windows
        $allBackSlash = str_replace(DIRECTORY_SEPARATOR, '\\', $withoutDotPhp);

        // replaces the base folder name with corresponding namespace
        return str_replace(rtrim($path, '/').'\\', $rootNamespace, $allBackSlash);
    }

    private static function checkImportedClasses($imports, $absPath)
    {
        foreach ($imports as $i => $import) {
            if (self::exists($import[0])) {
                app(ErrorPrinter::class)->wrongImport($absPath, $import[0], $import[1]);
            }
        }
    }

    /**
     * @param $imp
     *
     * @return bool
     */
    private static function exists($imp)
    {
        return ! class_exists($imp) && ! interface_exists($imp) && ! trait_exists($imp);
    }

    protected static function doNamespaceCorrection($correctNamespace, $classPath, $currentNamespace, $absFilePath)
    {
        // maybe an event listener
        app(ErrorPrinter::class)->badNamespace($classPath, $correctNamespace, $currentNamespace);

        event('laravel_microscope.namespace_fixing', get_defined_vars());
        NamespaceCorrector::fix($absFilePath, $currentNamespace, $correctNamespace);
        event('laravel_microscope.namespace_fixed', get_defined_vars());

        // maybe a listener for: 'microscope.namespace_fixed' event.
        app(ErrorPrinter::class)->fixedNamespace($correctNamespace);
    }

    private static function migrationPaths()
    {
        // normalize the migration paths
        $migrationDirs = [];
        foreach (app('migrator')->paths() as $path) {
            $migrationDirs[] = str_replace([
                '\\',
                '/',
            ], [
                DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
            ], $path);
        }

        /*foreach ($migrationDirs as $dir) {
            $parts = explode(DIRECTORY_SEPARATOR, $dir);

            foreach($parts as $part) {

            }
        }*/

        return $migrationDirs;
    }

    public static function getAllPhpFiles($psr4Path)
    {
        return (new Finder)->files()->name('*.php')->in(base_path($psr4Path));
    }

    private static function getRelativePath($absFilePath)
    {
        return trim(Str::replaceFirst(base_path(), '', $absFilePath), DIRECTORY_SEPARATOR);
    }
}
