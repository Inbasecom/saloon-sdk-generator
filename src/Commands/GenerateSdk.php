<?php

namespace Crescat\SaloonSdkGenerator\Commands;

use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Exceptions\ParserNotRegisteredException;
use Crescat\SaloonSdkGenerator\Factory;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Arr;
use LaravelZero\Framework\Commands\Command;
use Nette\PhpGenerator\PhpFile;
use ZipArchive;

class GenerateSdk extends Command
{
    protected $signature = 'generate:sdk
                            {path : Path to the API specification file to generate the SDK from, must be a local file}
                            {--type=postman : The type of API Specification (postman, openapi)}
                            {--name=Unnamed : The name of the SDK}
                            {--namespace=App\\Sdk : The root namespace of the SDK}
                            {--output=./build : The output path where the code will be created, will be created if it does not exist.}
                            {--force : Force overwriting existing files}
                            {--dry : Dry run, will only show the files to be generated, does not create or modify any files.}
                            {--zip : Generate a zip archive containing all the files}';

    protected $description = 'Generate an SDK based on an API specification file.';

    protected GeneratedCode $result;

    public function handle(): void
    {
        $inputPath = $this->argument('path');

        // TODO: Support remote URLs or move this into each parser class so they can deal with it instead.
        if (! file_exists($inputPath)) {
            $this->error("File not found: $inputPath");

            return;
        }

        $type = trim(strtolower($this->option('type')));

        $generator = new CodeGenerator(
            config: new Config(
                connectorName: $this->option('name'),
                namespace: $this->option('namespace'),
                resourceNamespaceSuffix: 'Resource',
                requestNamespaceSuffix: 'Requests',
                dtoNamespaceSuffix: 'Dto',
                ignoredQueryParams: [
                    'after',
                    'order_by',
                    'per_page',
                ]
            )
        );

        try {
            $specification = Factory::parse($type, $inputPath);
        } catch (ParserNotRegisteredException) {
            // TODO: Prettier errors using termwind
            $this->error("No parser registered for --type='$type'");

            if (in_array($type, ['yml', 'yaml', 'json', 'xml'])) {
                $this->warn('Note: the --type option is used to specify the API Specification type (ex: openapi, postman), not the file format.');
            }

            $this->line('Available types: '.implode(', ', Factory::getRegisteredParserTypes()));

            return;
        }

        $this->result = $generator->run($specification);

        if ($this->option('dry')) {
            $this->printGeneratedFiles();

            return;
        }

        $this->option('zip')
            ? $this->generateZipArchive()
            : $this->dumpGeneratedFiles();

    }

    protected function printGeneratedFiles(): void
    {
        $this->title('Generated Files');

        $this->comment("\nConnector:");
        if ($this->result->connectorClass) {
            $this->line(Utils::formatNamespaceAndClass($this->result->connectorClass));
        }

        $this->comment("\nBase Resource:");
        if ($this->result->resourceBaseClass) {
            $this->line(Utils::formatNamespaceAndClass($this->result->resourceBaseClass));
        }

        $this->comment("\nResources:");
        foreach ($this->result->resourceClasses as $resourceClass) {
            $this->line(Utils::formatNamespaceAndClass($resourceClass));
        }

        $this->comment("\nRequests:");
        foreach ($this->result->requestClasses as $requestClass) {
            $this->line(Utils::formatNamespaceAndClass($requestClass));
        }
    }

    protected function dumpGeneratedFiles(): void
    {
        $this->title('Generated Files');

        $this->comment("\nConnector:");
        if ($this->result->connectorClass) {
            $this->result->dumpToFile($this->result->connectorClass);
        }

        $this->comment("\nBase Resource:");
        if ($this->result->resourceBaseClass) {
            $this->dumpToFile($this->result->resourceBaseClass);
        }

        $this->comment("\nResources:");
        foreach ($this->result->resourceClasses as $resourceClass) {
            $this->dumpToFile($resourceClass);
        }

        $this->comment("\nRequests:");
        foreach ($this->result->requestClasses as $requestClass) {
            $this->dumpToFile($requestClass);
        }
    }

    protected function dumpToFile(PhpFile $file): void
    {
        $filePath = $this->result->outputPath($file);

        if (file_exists($filePath) && ! $this->option('force')) {
            $this->warn("- File already exists: $filePath");

            return;
        }

        $ok = $this->result->dumpToFile($file);
        if ($ok === false) {
            $this->error("- Failed to write: $filePath");
        } else {
            $this->line("- Created: $filePath");
        }
    }

    protected function generateZipArchive(): void
    {
        $zipFileName = $this->option('name').'_sdk.zip';
        $zipPath = $this->option('output').DIRECTORY_SEPARATOR.$zipFileName;

        if (! file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), recursive: true);
        }

        if (file_exists($zipPath) && ! $this->option('force')) {
            $this->warn("- Zip archive already exists: $zipPath");

            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("- Failed to create the ZIP archive: $zipPath");

            return;
        }

        $filesToZip = array_merge(
            [$this->result->connectorClass, $this->result->resourceBaseClass],
            $this->result->resourceClasses,
            $this->result->requestClasses
        );

        foreach ($filesToZip as $file) {
            $filePathInZip = str_replace('\\', '/', Arr::first($file->getNamespaces())->getName()).'/'.Arr::first($file->getClasses())->getName().'.php';
            $zip->addFromString($filePathInZip, (string) $file);
            $this->line("- Wrote file to ZIP: $filePathInZip");
        }

        $zip->close();

        $this->line("- Created zip archive: $zipPath");
    }
}
