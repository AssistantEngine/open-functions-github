<?php

namespace AssistantEngine\OpenFunctions\Github;

use AssistantEngine\OpenFunctions\Core\Contracts\AbstractOpenFunction;
use AssistantEngine\OpenFunctions\Core\Helpers\FunctionDefinition;
use AssistantEngine\OpenFunctions\Core\Helpers\Parameter;
use AssistantEngine\OpenFunctions\Core\Models\Responses\TextResponseItem;
use AssistantEngine\OpenFunctions\Github\Models\Parameters;
use AssistantEngine\OpenFunctions\Github\Repository\GitRepository;

class GithubOpenFunction extends AbstractOpenFunction
{
    private GitRepository $gitRepository;
    private Parameters $parameter;

    public function __construct(Parameters $parameter)
    {
        $this->parameter = $parameter;
        $this->gitRepository = new GitRepository($parameter->token, $parameter->owner, $parameter->repository);
    }

    // List files in the repo (excluding folders)
    public function listFiles($branchName)
    {
        $this->gitRepository->checkoutBranch($branchName);

        // Get the list of files (excluding directories)
        return new TextResponseItem(json_encode($this->gitRepository->listFiles(true)));
    }

    // Read contents of specified files
    public function readFiles($branchName, array $filenames)
    {
        // Determine which branch to use
        $this->gitRepository->checkoutBranch($branchName);

        $response = [];
        $fileContents = [];

        foreach ($filenames as $filename) {
            try {
                $content = $this->gitRepository->readFile($filename);
                $fileContents[$filename] = $content;
            } catch (\Exception $e) {
                $fileContents[$filename] = 'Error: Not found';
            }
        }

        foreach ($fileContents as $filename => $content) {
            $response[] = new TextResponseItem(json_encode([$filename => $content]));
        }

        return $response;
    }

    // Modify multiple files and commit them
    public function commitFiles($branchName, array $files, $commitMessage)
    {
        if (in_array($branchName, $this->parameter->protected)) {
            throw new \Exception("Operation not allowed: The branch '{$branchName}' is protected.");
        }

        // Checkout the feature branch, creating it if necessary
        $this->gitRepository->checkoutBranch($branchName);

        // Modify the files and commit changes
        $this->gitRepository->modifyFiles($files, $commitMessage);

        return new TextResponseItem(json_encode(['success' => true]));
    }

    public function generateFunctionDefinitions(): array
    {
        // Get the list of branches
        $branches = $this->gitRepository->listBranches();

        $result = [];

        $listFilesFunction = (new FunctionDefinition(
            'listFiles',
            'List all files in the specified branch.'
        ))->addParameter(
            Parameter::string('branchName')
                ->description('The branch to list files from')
                ->enum($branches)
                ->required()
        );

        $result[] = $listFilesFunction->createFunctionDescription();


        $readFilesFunction = (new FunctionDefinition(
            'readFiles',
            'Read contents of specified files from the specified branch.'
        ))
            ->addParameter(
                Parameter::string('branchName')
                    ->description('The branch to read files from')
                    ->enum($branches)
                    ->required()
            )
            ->addParameter(
                Parameter::array('filenames')
                    ->description('An array of filenames to read')
                    ->setItems(
                        Parameter::string(null)->description('A filename')
                    )
                    ->required()
            );

        $result[] = $readFilesFunction->createFunctionDescription();


        $commitFilesFunction = (new FunctionDefinition(
            'commitFiles',
            'Modify multiple files and commit them to the specified branch. '
        ))
            ->addParameter(
                Parameter::string('branchName')
                    ->description('The branch to commit files to')
                    ->enum($branches)
                    ->required()
            )
            ->addParameter(
                Parameter::array('files')
                    ->description('An array of files to commit')
                    ->setItems(
                        Parameter::object(null)
                            ->description("The file to commit")
                            ->addProperty(
                                Parameter::string('path')
                                    ->description('The path of the file to commit')
                                    ->required()
                            )
                            ->addProperty(
                                Parameter::string('content')
                                    ->description('The content of the file to commit')
                                    ->required()
                            )
                    )
                    ->required()
            )
            ->addParameter(
                Parameter::string('commitMessage')
                    ->description('The commit message')
                    ->required()
            );

        $result[] = $commitFilesFunction->createFunctionDescription();

        return $result;
    }
}
